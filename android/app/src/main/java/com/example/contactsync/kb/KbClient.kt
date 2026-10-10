package com.example.contactsync.kb

import android.content.Context
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import okhttp3.Cookie
import okhttp3.CookieJar
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.asRequestBody
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Сессия KB кончилась (или её не было) — нужен повторный вход. */
class KbUnauthorizedException : IOException("Нужно войти в базу знаний")

class KbException(message: String, val code: Int) : IOException(message)

/**
 * Вход в KB и сессия. KB выдаёт cookie-сессию на 30 дней (`POST /api/login`); токены API для
 * приложения не годятся — ими нельзя создать встречу и прикрепить аудио. Пароль не хранится:
 * когда сессия кончится, приложение попросит войти снова.
 */
class KbSession(context: Context) {
    private val prefs = context.getSharedPreferences("kb", Context.MODE_PRIVATE)

    var baseUrl: String
        get() = prefs.getString(KEY_URL, null) ?: DEFAULT_URL
        set(value) = prefs.edit().putString(KEY_URL, value.trim().trimEnd('/')).apply()

    /** Выбранный в виджете проект. */
    var projectSlug: String?
        get() = prefs.getString(KEY_PROJECT, null)
        set(value) = prefs.edit().putString(KEY_PROJECT, value).apply()

    /** Лимит длины записи на сервере (`audioMaxMinutes`) — по нему режем длинные встречи. */
    var audioMaxMinutes: Int
        get() = prefs.getInt(KEY_MAX_MINUTES, 60)
        set(value) = prefs.edit().putInt(KEY_MAX_MINUTES, value).apply()

    /** Встречи, отправленные с планшета за последние сутки: виджет показывает, как идёт их расшифровка. */
    fun recentMeetings(): List<Int> {
        val dayAgo = System.currentTimeMillis() - 24 * 60 * 60 * 1000L
        return prefs.getStringSet(KEY_MEETINGS, emptySet()).orEmpty()
            .mapNotNull { it.split("|").takeIf { p -> p.size == 2 } }
            .filter { it[1].toLong() > dayAgo }
            .map { it[0].toInt() }
    }

    fun rememberMeeting(id: Int) {
        val dayAgo = System.currentTimeMillis() - 24 * 60 * 60 * 1000L
        val kept = prefs.getStringSet(KEY_MEETINGS, emptySet()).orEmpty()
            .filter { it.substringAfter("|").toLongOrNull()?.let { t -> t > dayAgo } == true }
        prefs.edit().putStringSet(KEY_MEETINGS, (kept + "$id|${System.currentTimeMillis()}").toSet()).apply()
    }

    fun forgetMeeting(id: Int) {
        prefs.edit().putStringSet(
            KEY_MEETINGS,
            prefs.getStringSet(KEY_MEETINGS, emptySet()).orEmpty().filterNot { it.startsWith("$id|") }.toSet(),
        ).apply()
    }

    private val _user = MutableStateFlow(prefs.getString(KEY_USER, null))
    /** Имя вошедшего пользователя; null — не вошли или сессия кончилась. */
    val user: StateFlow<String?> = _user.asStateFlow()

    val isLoggedIn: Boolean get() = _user.value != null

    internal fun signedIn(user: String) {
        prefs.edit().putString(KEY_USER, user).apply()
        _user.value = user
    }

    internal fun signedOut() {
        prefs.edit().remove(KEY_USER).remove(KEY_COOKIES).apply()
        _user.value = null
    }

    /** Cookie сессии хранятся в приватных настройках приложения — переживают перезапуск. */
    internal val cookieJar = object : CookieJar {
        override fun saveFromResponse(url: HttpUrl, cookies: List<Cookie>) {
            val kept = loadAll().filterNot { old -> cookies.any { it.name == old.name } } + cookies
            prefs.edit().putStringSet(KEY_COOKIES, kept.filter { it.expiresAt > System.currentTimeMillis() }
                .map { it.toString() }.toSet()).apply()
        }

        override fun loadForRequest(url: HttpUrl): List<Cookie> = loadAll().filter { it.matches(url) }
    }

    /**
     * Вошли прямо на веб-странице KB во втором экране — забираем её cookie себе,
     * чтобы виджет и запись встреч работали без второго входа.
     */
    fun adoptWebCookies(header: String) {
        val url = baseUrl.toHttpUrlOrNull() ?: return
        val cookies = header.split(";").mapNotNull { part ->
            val (name, value) = part.trim().split("=", limit = 2).takeIf { it.size == 2 } ?: return@mapNotNull null
            Cookie.Builder().name(name).value(value).hostOnlyDomain(url.host).path("/")
                .expiresAt(System.currentTimeMillis() + WEB_COOKIE_TTL_MS).build()
        }
        if (cookies.isNotEmpty()) cookieJar.saveFromResponse(url, cookies)
    }

    /** Cookie для WebView второго экрана: та же сессия, без повторного входа. */
    fun cookieHeaders(): List<String> = cookieJar.loadForRequest(baseUrl.toHttpUrl()).map { "${it.name}=${it.value}; path=/" }

    private fun loadAll(): List<Cookie> {
        val url = baseUrl.toHttpUrlOrNull() ?: return emptyList()
        return prefs.getStringSet(KEY_COOKIES, emptySet()).orEmpty().mapNotNull { Cookie.parse(url, it) }
            .filter { it.expiresAt > System.currentTimeMillis() }
    }

    private fun String.toHttpUrlOrNull(): HttpUrl? = runCatching { toHttpUrl() }.getOrNull()

    companion object {
        const val DEFAULT_URL = "http://192.168.100.247:8580"
        /** Сессия KB живёт 30 дней; точный срок веб-страница не сообщает. */
        private const val WEB_COOKIE_TTL_MS = 30L * 24 * 60 * 60 * 1000
        private const val KEY_URL = "url"
        private const val KEY_USER = "user"
        private const val KEY_COOKIES = "cookies"
        private const val KEY_PROJECT = "project"
        private const val KEY_MAX_MINUTES = "audio_max_minutes"
        private const val KEY_MEETINGS = "recent_meetings"
    }
}

/** REST API KB: задачи, встречи и загрузка аудио. Все вызовы — от имени вошедшего пользователя. */
class KbApi(private val session: KbSession) {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false; coerceInputValues = true }
    private val jsonType = "application/json".toMediaType()

    private val client = OkHttpClient.Builder()
        .cookieJar(session.cookieJar)
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .build()

    /** Загрузка часового аудио по домашней сети может идти минуты. */
    private val uploadClient = client.newBuilder().writeTimeout(10, TimeUnit.MINUTES).readTimeout(2, TimeUnit.MINUTES).build()

    suspend fun login(username: String, password: String) {
        val response = call<KbUser>(post("/api/login", json.encodeToString(KbLoginRequest(username.trim(), password))), authFailure = false)
        session.signedIn(response.username)
        settings()?.let { session.audioMaxMinutes = it.audioMaxMinutes }
    }

    suspend fun logout() {
        runCatching { call<Unit?>(post("/api/logout", "{}")) }
        session.signedOut()
    }

    /** Кто вошёл по текущей сессии; null — сессии нет. */
    suspend fun me(): KbUser? = try {
        call<KbUser>(get("/api/me"), authFailure = false).also { session.signedIn(it.username) }
    } catch (e: KbException) {
        if (e.code == 401) null else throw e
    }

    suspend fun projects(): List<KbProject> = call(get("/api/projects"))

    /** Открытые задачи проекта: к выполнению, в работе, на ревью и только что сделанные. */
    suspend fun openTasks(slug: String): List<KbTask> = call(get("/api/projects/$slug/tasks?status=open"))

    suspend fun settings(): KbSettings? = runCatching { call<KbSettings>(get("/api/settings")) }.getOrNull()

    suspend fun createMeeting(slug: String, meeting: KbNewMeeting): KbMeeting =
        call(post("/api/projects/$slug/meetings", json.encodeToString(meeting)))

    suspend fun meeting(id: Int): KbMeeting = call(get("/api/meetings/$id"))

    /** Модель расшифровки по умолчанию для проекта и встречи — та же, что предлагает веб-интерфейс. */
    suspend fun defaultTranscriptionModel(slug: String, meetingId: Int): Int? =
        call<KbModelOptions>(get("/api/model-options?task=transcription&project=$slug&meeting=$meetingId")).preselectedModelId

    /** Файл уходит прямо в хранилище S3 по подписанной ссылке, в обход API (там лимит 55 МБ). */
    suspend fun uploadAudio(file: File): String {
        val signed = call<KbSignedUpload>(post("/api/uploads/sign", json.encodeToString(KbSignRequest(file.name, AUDIO_TYPE))))
        val contentType = signed.headers["Content-Type"] ?: AUDIO_TYPE
        val request = Request.Builder().url(signed.url).put(file.asRequestBody(contentType.toMediaType())).build()
        withContext(Dispatchers.IO) {
            uploadClient.newCall(request).execute().use {
                if (!it.isSuccessful) throw KbException("Хранилище KB не приняло файл: HTTP ${it.code}", it.code)
            }
        }
        return signed.key
    }

    /**
     * Прикрепить загруженный файл к встрече и запустить расшифровку.
     * 409 — встреча уже обрабатывается: значит, прошлая попытка дошла, повторять не нужно.
     */
    suspend fun attachAudio(meetingId: Int, key: String, modelId: Int?) {
        try {
            call<KbMeeting>(post("/api/meetings/$meetingId/audio", json.encodeToString(KbAttachAudio(key, modelId))))
        } catch (e: KbException) {
            if (e.code != 409) throw e
        }
    }

    private fun get(path: String) = Request.Builder().url(session.baseUrl + path).get()

    private fun post(path: String, body: String) = Request.Builder().url(session.baseUrl + path).post(body.toRequestBody(jsonType))

    private suspend inline fun <reified T> call(builder: Request.Builder, authFailure: Boolean = true): T =
        withContext(Dispatchers.IO) {
            client.newCall(builder.header("Accept", "application/json").build()).execute().use { response ->
                val body = response.body?.string().orEmpty()
                when {
                    response.code == 401 && authFailure -> {
                        session.signedOut()
                        throw KbUnauthorizedException()
                    }
                    response.code == 401 -> throw KbException("Неверный логин или пароль", 401)
                    !response.isSuccessful -> throw KbException(errorMessage(response.code, body), response.code)
                    body.isBlank() -> null as T
                    else -> json.decodeFromString<T>(body)
                }
            }
        }

    private fun errorMessage(code: Int, body: String): String =
        runCatching { json.decodeFromString<Map<String, kotlinx.serialization.json.JsonElement>>(body)["error"]?.toString()?.trim('"') }
            .getOrNull() ?: "KB ответила ошибкой $code"

    private companion object {
        const val AUDIO_TYPE = "audio/mp4"
    }
}
