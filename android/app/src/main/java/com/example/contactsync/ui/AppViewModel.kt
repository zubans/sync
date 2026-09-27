package com.example.contactsync.ui

import android.app.Application
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.example.contactsync.App
import com.example.contactsync.data.AuthResponse
import com.example.contactsync.data.UnauthorizedException
import com.example.contactsync.sync.SyncReport
import com.example.contactsync.sync.SyncScheduler
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch

data class UiState(
    val loggedIn: Boolean,
    val serverUrl: String,
    val email: String?,
    val familyName: String?,
    val backgroundSync: Boolean,
    val lastSyncAt: Long,
    val lastSyncSummary: String?,
    val googleAccounts: List<String> = emptyList(),
    val busy: Boolean = false,
    val message: String? = null,
    val error: String? = null,
    /** Вход выполнен, но контакты ещё не восстановлены (ждём разрешений). */
    val pendingRestore: Boolean = false,
)

class AppViewModel(application: Application) : AndroidViewModel(application) {

    private val app = application as App
    private val session = app.session

    private val _state = MutableStateFlow(snapshot())
    val state: StateFlow<UiState> = _state.asStateFlow()

    fun onServerUrlChange(url: String) {
        session.serverUrl = url
        _state.update { it.copy(serverUrl = url) }
    }

    fun login(email: String, password: String) = authenticate { app.api.login(email.trim(), password) }

    fun register(email: String, password: String) = authenticate { app.api.register(email.trim(), password) }

    private fun authenticate(call: suspend () -> AuthResponse) = launchTask {
        val response = call()
        app.engine.clearLocalState()
        app.vault.clearLocal()
        session.signIn(response.token, response.user)
        _state.update { snapshot().copy(pendingRestore = true) }
        null
    }

    /** Вызывается, когда есть разрешения на контакты: сразу после входа — восстановление, иначе обычная синхронизация. */
    fun syncNow() {
        val restore = _state.value.pendingRestore
        launchTask {
            val report = if (restore) app.engine.restoreAndSync() else app.engine.sync()
            if (session.backgroundSync) SyncScheduler.enable(app)
            _state.update { it.copy(pendingRestore = false, googleAccounts = report.googleAccounts) }
            report.summary()
        }
    }

    fun restore() {
        launchTask { app.engine.restoreAndSync().summary() }
    }

    fun onPermissionsDenied() {
        _state.update { it.copy(error = "Без доступа к контактам синхронизация невозможна. Разрешите его в настройках приложения.") }
    }

    fun setBackgroundSync(enabled: Boolean) {
        session.backgroundSync = enabled
        if (enabled) SyncScheduler.enable(app) else SyncScheduler.disable(app)
        _state.update { it.copy(backgroundSync = enabled) }
    }

    fun logout() {
        viewModelScope.launch {
            runCatching { app.api.logout() }
            signOutLocally()
        }
    }

    fun dismissMessage() = _state.update { it.copy(message = null, error = null) }

    private fun signOutLocally(error: String? = null) {
        app.clearAccountData()
        _state.value = snapshot().copy(error = error)
    }

    private fun launchTask(block: suspend () -> String?) {
        if (_state.value.busy) return
        _state.update { it.copy(busy = true, message = null, error = null) }
        viewModelScope.launch {
            try {
                val message = block()
                _state.update { snapshot().copy(pendingRestore = it.pendingRestore, googleAccounts = it.googleAccounts, message = message) }
            } catch (e: CancellationException) {
                throw e
            } catch (e: UnauthorizedException) {
                signOutLocally(e.message)
            } catch (e: Exception) {
                _state.update { it.copy(error = e.message ?: e.javaClass.simpleName) }
            } finally {
                _state.update { it.copy(busy = false) }
            }
        }
    }

    private fun snapshot() = UiState(
        loggedIn = session.isLoggedIn,
        serverUrl = session.serverUrl,
        email = session.email,
        familyName = session.familyName,
        backgroundSync = session.backgroundSync,
        lastSyncAt = session.lastSyncAt,
        lastSyncSummary = session.lastSyncSummary,
    )
}
