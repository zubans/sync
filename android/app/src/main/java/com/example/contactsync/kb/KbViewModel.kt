package com.example.contactsync.kb

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.example.contactsync.App
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch

/** Встреча, отправленная с планшета, и как идёт её обработка в KB. */
data class MeetingProgress(val title: String, val status: String)

data class KbState(
    val user: String? = null,
    val baseUrl: String = KbSession.DEFAULT_URL,
    val projects: List<KbProject> = emptyList(),
    val project: KbProject? = null,
    val tasks: List<KbTask> = emptyList(),
    val recording: ActiveRecording? = null,
    /** Записи на планшете, ещё не принятые KB. */
    val pendingUploads: Int = 0,
    val meetings: List<MeetingProgress> = emptyList(),
    val loading: Boolean = false,
    val error: String? = null,
)

/**
 * Данные KB для виджета, второго экрана и экрана «База знаний». KB не присылает событий —
 * опрашиваем, пока экран виден: раз в 15 секунд, если модель что-то делает, иначе раз в минуту.
 */
class KbViewModel(application: Application) : AndroidViewModel(application) {

    private val app = application as App
    private val session = app.kbSession
    private val api = app.kbApi

    private val _state = MutableStateFlow(KbState(user = session.user.value, baseUrl = session.baseUrl))
    val state: StateFlow<KbState> = _state.asStateFlow()

    private var pollJob: Job? = null

    init {
        viewModelScope.launch { session.user.collect { user -> _state.update { it.copy(user = user) } } }
        viewModelScope.launch { MeetingRecorderService.state.collect { rec -> _state.update { it.copy(recording = rec) } } }
    }

    fun onResume() {
        pollJob?.cancel()
        pollJob = viewModelScope.launch {
            while (isActive) {
                val running = refresh()
                delay(if (running) FAST_MS else SLOW_MS)
            }
        }
    }

    fun onPause() {
        pollJob?.cancel()
    }

    fun login(url: String, username: String, password: String, onDone: (String?) -> Unit) = viewModelScope.launch {
        session.baseUrl = url.ifBlank { KbSession.DEFAULT_URL }
        val error = try {
            api.login(username, password)
            _state.update { it.copy(baseUrl = session.baseUrl, error = null) }
            MeetingUploads.resumeAll(getApplication())
            refresh()
            null
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            e.message ?: "Не удалось войти"
        }
        onDone(error)
    }

    /** Пользователь вошёл на веб-странице KB — подхватываем ту же сессию для виджета. */
    fun adoptWebSession(cookieHeader: String?) {
        if (session.isLoggedIn || cookieHeader.isNullOrBlank()) return
        viewModelScope.launch {
            session.adoptWebCookies(cookieHeader)
            if (runCatching { api.me() }.getOrNull() != null) {
                MeetingUploads.resumeAll(getApplication())
                refresh()
            }
        }
    }

    fun logout() = viewModelScope.launch {
        api.logout()
        _state.update { it.copy(projects = emptyList(), tasks = emptyList(), project = null) }
    }

    fun selectProject(project: KbProject) {
        session.projectSlug = project.slug
        _state.update { it.copy(project = project, tasks = emptyList()) }
        viewModelScope.launch { refresh() }
    }

    fun startRecording() {
        val project = _state.value.project ?: return
        MeetingRecorderService.start(getApplication(), project.slug, project.name)
    }

    fun stopRecording() = MeetingRecorderService.stop(getApplication())

    /** @return модель сейчас работает над какой-то задачей — опрашивать чаще. */
    private suspend fun refresh(): Boolean {
        _state.update { it.copy(pendingUploads = MeetingUploads.pending(getApplication()).size) }
        if (!session.isLoggedIn) return false
        _state.update { it.copy(loading = true) }
        return try {
            val projects = _state.value.projects.ifEmpty { api.projects() }
            val project = projects.firstOrNull { it.slug == session.projectSlug } ?: projects.firstOrNull()
            val tasks = project?.let { api.openTasks(it.slug) }.orEmpty()
            _state.update { it.copy(projects = projects, project = project, tasks = tasks, meetings = meetings(), loading = false, error = null) }
            tasks.any(KbStages::isRunning) || _state.value.meetings.isNotEmpty()
        } catch (e: CancellationException) {
            throw e
        } catch (e: KbUnauthorizedException) {
            _state.update { it.copy(loading = false, error = null) }
            false
        } catch (e: Exception) {
            _state.update { it.copy(loading = false, error = "KB недоступна: ${e.message}") }
            false
        }
    }

    /** Статусы встреч, отправленных за сутки; законченные убираем из списка. */
    private suspend fun meetings(): List<MeetingProgress> = session.recentMeetings().mapNotNull { id ->
        val meeting = runCatching { api.meeting(id) }.getOrNull() ?: return@mapNotNull null
        val status = KbStages.meetingStatus(meeting)
        if (status == null) session.forgetMeeting(id)
        status?.let { MeetingProgress(meeting.title, it) }
    }

    private companion object {
        const val FAST_MS = 15_000L
        const val SLOW_MS = 60_000L
    }
}
