package com.example.contactsync.cep

import android.app.Application
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.ContextCompat
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.launch
import okhttp3.OkHttpClient
import okhttp3.Request
import java.net.URI
import java.util.concurrent.TimeUnit
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.withContext
import java.io.File

/** Профили, цепочки и подключение CEP. Профили с паролями лежат в закрытом каталоге приложения. */
class CepViewModel(app: Application) : AndroidViewModel(app) {
    /** Тот же формат, что ~/Library/Application Support/CEP/profiles.json на macOS. */
    private val storeFile = File(app.filesDir, STORE_FILE)

    val alert = MutableStateFlow<String?>(null)

    private val _store = MutableStateFlow(load())
    val store: StateFlow<CepStore> = _store.asStateFlow()

    private fun load(): CepStore {
        if (!storeFile.exists()) return CepStore()
        return runCatching { CepLogic.decode(storeFile.readText()) }.getOrElse {
            alert.value = "Не удалось прочитать профили: ${it.message}"
            CepStore()
        }
    }

    @Synchronized
    private fun update(block: (CepStore) -> CepStore) {
        val next = block(_store.value)
        _store.value = next
        storeFile.writeText(CepLogic.encode(next))
    }

    // Подписки

    /** Подписки, которые сейчас обновляются. */
    private val _refreshing = MutableStateFlow<Set<String>>(emptySet())
    val refreshing: StateFlow<Set<String>> = _refreshing.asStateFlow()

    // после _refreshing: обновление подписок при создании модели пользуется им
    init {
        refreshStaleSubscriptions()
    }

    private val http by lazy {
        OkHttpClient.Builder().connectTimeout(30, TimeUnit.SECONDS).readTimeout(45, TimeUnit.SECONDS).build()
    }

    /** HWID этого телефона: sha256 от ANDROID_ID — постоянный для приложения и не раскрывает сам идентификатор. */
    val deviceHwid: String by lazy {
        val id = Settings.Secure.getString(getApplication<Application>().contentResolver, Settings.Secure.ANDROID_ID)
        CepLogic.hwid(id ?: Build.MODEL)
    }

    fun subscription(id: String): CepSubscription? = _store.value.subscriptions.firstOrNull { it.id == id }

    /** Новая подписка по адресу: сразу обновляется, имя берётся из ответа панели. */
    fun addSubscription(url: String): CepSubscription? {
        val trimmed = url.trim()
        val host = runCatching { URI(trimmed) }.getOrNull()?.takeIf { it.scheme == "https" || it.scheme == "http" }?.host
        if (host == null) {
            alert.value = "Это не адрес подписки: нужен https://…"
            return null
        }
        val s = CepSubscription(name = host, url = trimmed)
        update { it.copy(subscriptions = it.subscriptions + s) }
        refreshSubscription(s.id)
        return s
    }

    fun saveSubscription(s: CepSubscription) = update { st -> st.copy(subscriptions = st.subscriptions.map { if (it.id == s.id) s else it }) }

    /** Профили остаются с последней ссылкой, но больше не обновляются. */
    fun deleteSubscription(id: String) = update { st ->
        st.copy(
            subscriptions = st.subscriptions.filter { it.id != id },
            profiles = st.profiles.map { if (it.subscriptionID == id) it.copy(subscriptionID = null) else it },
        )
    }

    fun hwidFor(s: CepSubscription): String = s.hwid.trim().ifEmpty { deviceHwid }

    /** Скачивает список серверов и обновляет ссылки в профилях, выбравших серверы этой подписки. */
    fun refreshSubscription(id: String) {
        val s = subscription(id) ?: return
        if (id in _refreshing.value) return
        _refreshing.update { it + id }
        viewModelScope.launch(Dispatchers.IO) {
            try {
                val request = Request.Builder().url(s.url)
                    .header("User-Agent", CepLogic.SUBSCRIPTION_USER_AGENT)
                    .header("x-hwid", hwidFor(s))
                    .header("x-device-os", "Android")
                    .header("x-ver-os", Build.VERSION.RELEASE)
                    .header("x-device-model", Build.MODEL)
                    .build()
                http.newCall(request).execute().use { response ->
                    if (!response.isSuccessful) throw IllegalStateException("сервер подписки ответил ${response.code}")
                    val servers = CepLogic.parseSubscription(response.body?.string().orEmpty())
                    if (servers.isEmpty()) throw IllegalStateException("в ответе нет ни одного сервера")
                    if (CepLogic.isStub(servers)) {
                        throw IllegalStateException(
                            if (response.header("x-hwid-limit") == "true") {
                                "панель не приняла устройство: возможно, исчерпан лимит устройств подписки. Укажите HWID уже зарегистрированного устройства или освободите место у провайдера"
                            } else {
                                "панель вернула заглушку вместо серверов: «${servers[0].name}»"
                            },
                        )
                    }
                    update {
                        CepLogic.applySubscription(
                            it, id, servers,
                            CepLogic.subscriptionTitle(response.header("profile-title")),
                            CepLogic.subscriptionUserInfo(response.header("subscription-userinfo")),
                            System.currentTimeMillis() / 1000.0,
                        )
                    }
                }
            } catch (e: Exception) {
                alert.value = "Подписка «${s.name}»: ${e.message}"
            } finally {
                _refreshing.update { it - id }
            }
        }
    }

    /** Подписки старше суток обновляются при открытии CEP. */
    private fun refreshStaleSubscriptions() {
        val dayAgo = System.currentTimeMillis() / 1000.0 - CepLogic.APPLE_EPOCH - 86_400
        _store.value.subscriptions.filter { (it.updatedAt ?: 0.0) < dayAgo }.forEach { refreshSubscription(it.id) }
    }

    /** Профиль с выбранным сервером подписки. */
    fun addProfile(server: CepSubscriptionServer, subscriptionId: String): CepProfile? {
        val proto = server.protocol ?: return null
        val p = CepProfile(name = server.name, proto = proto.raw, uri = server.uri, subscriptionID = subscriptionId, subscriptionServer = server.name)
        update { it.copy(profiles = it.profiles + p) }
        return p
    }

    /** Профиль переключается на другой сервер той же подписки; имя меняется, только если было именем сервера. */
    fun selectServer(profileId: String, server: CepSubscriptionServer) {
        val proto = server.protocol ?: return
        update { st ->
            st.copy(
                profiles = st.profiles.map { p ->
                    if (p.id != profileId) return@map p
                    p.copy(
                        name = if (p.name == p.subscriptionServer) server.name else p.name,
                        proto = proto.raw, uri = server.uri, subscriptionServer = server.name,
                    )
                },
            )
        }
    }

    // Профили

    fun profile(id: String): CepProfile? = _store.value.profiles.firstOrNull { it.id == id }

    fun addProfile(proto: VpnProtocol): CepProfile {
        val p = CepProfile(name = CepLogic.newProfileName(proto, _store.value.profiles), proto = proto.raw)
        update { it.copy(profiles = it.profiles + p) }
        return p
    }

    fun importLinks(text: String) {
        val parsed = CepLogic.parseLinks(text)
        if (parsed.profiles.isNotEmpty()) update { it.copy(profiles = it.profiles + parsed.profiles) }
        if (parsed.rejected.isNotEmpty()) {
            alert.value = "Не ссылки vless://, hysteria2://, hy2:// или hysteria://:\n" +
                parsed.rejected.joinToString("\n") { it.take(40) }
        }
    }

    fun saveProfile(p: CepProfile) = update { s -> s.copy(profiles = s.profiles.map { if (it.id == p.id) p else it }) }

    fun deleteProfile(id: String) = update { s ->
        s.copy(
            profiles = s.profiles.filter { it.id != id },
            chains = s.chains.map { c -> c.copy(hops = c.hops.filter { it != id }) },
        )
    }

    // Цепочки

    fun addChain(): CepChain {
        val c = CepChain(name = "Цепочка ${_store.value.chains.size + 1}")
        update { it.copy(chains = it.chains + c) }
        return c
    }

    fun saveChain(c: CepChain) = update { s -> s.copy(chains = s.chains.map { if (it.id == c.id) c else it }) }

    fun deleteChain(id: String) = update { s -> s.copy(chains = s.chains.filter { it.id != id }) }

    private fun planJson(chain: CepChain): String =
        CepLogic.json.encodeToString(CepPlan.serializer(), CepLogic.plan(chain, _store.value.profiles))

    /** Ошибки, из-за которых цепочку не запустить. Проверяет движок: он же потом собирает конфиг. */
    fun problems(chain: CepChain): List<String> = try {
        CepLogic.json.decodeFromString<List<String>>(cepcore.Cepcore.validate(planJson(chain)))
    } catch (e: UnsatisfiedLinkError) {
        // движок собран только под arm64 и x86_64
        listOf("Движок CEP не собран для процессора этого устройства")
    }

    /** Подбирает оптимальный внешний MTU для узла с учётом предыдущих хопов цепочки (если узел уже в цепочке). */
    suspend fun probeMtu(profile: CepProfile): MtuProbeResult = withContext(Dispatchers.IO) {
        try {
            val profileJson = CepLogic.json.encodeToString(CepProfile.serializer(), profile)
            val chain = _store.value.chains.firstOrNull { profile.id in it.hops }
            val chainJson = chain?.let { planJson(it) } ?: ""
            val raw = cepcore.Cepcore.probeProfileMTU(profileJson, chainJson)
            CepLogic.json.decodeFromString(MtuProbeResult.serializer(), raw)
        } catch (e: UnsatisfiedLinkError) {
            MtuProbeResult(ok = false, error = "Движок CEP не собран для процессора этого устройства")
        } catch (e: Throwable) {
            MtuProbeResult(ok = false, error = e.message ?: "Ошибка подбора MTU")
        }
    }

    // Перенос с macOS

    fun importStore(uri: Uri) {
        val ctx = getApplication<Application>()
        val text = runCatching {
            ctx.contentResolver.openInputStream(uri)!!.use { it.readBytes().decodeToString() }
        }.getOrElse {
            alert.value = "Не удалось прочитать файл: ${it.message}"
            return
        }
        val incoming = runCatching { CepLogic.decode(text) }.getOrElse {
            alert.value = "Это не profiles.json приложения CEP: ${it.message}"
            return
        }
        update { CepLogic.merge(it, incoming) }
        alert.value = "Загружено: профилей ${incoming.profiles.size}, цепочек ${incoming.chains.size}, подписок ${incoming.subscriptions.size}"
    }

    fun exportStore(uri: Uri) {
        val ctx = getApplication<Application>()
        runCatching {
            ctx.contentResolver.openOutputStream(uri, "wt")!!.use { it.write(CepLogic.encode(_store.value).toByteArray()) }
        }.onFailure { alert.value = "Не удалось сохранить: ${it.message}" }
    }

    fun readConfigFile(uri: Uri): String? {
        val ctx = getApplication<Application>()
        return runCatching { ctx.contentResolver.openInputStream(uri)!!.use { it.readBytes().decodeToString() } }
            .onFailure { alert.value = "Не удалось прочитать файл: ${it.message}" }
            .getOrNull()
    }

    // Подключение

    fun connect(chain: CepChain) {
        if (CepEngine.state.value.isActive) {
            alert.value = "Сначала отключите текущую цепочку"
            return
        }
        val problems = problems(chain)
        if (problems.isNotEmpty()) {
            alert.value = problems.joinToString("\n")
            return
        }
        val ctx = getApplication<Application>()
        // В Intent только id цепочки: пароли через Intent не передаём, служба читает профили из файла сама.
        val intent = Intent(ctx, CepVpnService::class.java)
            .setAction(CepVpnService.ACTION_START)
            .putExtra(CepVpnService.EXTRA_CHAIN_ID, chain.id)
        ContextCompat.startForegroundService(ctx, intent)
    }

    fun disconnect() {
        val ctx = getApplication<Application>()
        ctx.startService(Intent(ctx, CepVpnService::class.java).setAction(CepVpnService.ACTION_STOP))
    }

    companion object {
        const val STORE_FILE = "cep-profiles.json"
    }
}
