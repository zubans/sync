package com.example.contactsync.cep

import android.app.Application
import android.content.Intent
import android.net.Uri
import androidx.core.content.ContextCompat
import androidx.lifecycle.AndroidViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
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

    private fun update(block: (CepStore) -> CepStore) {
        val next = block(_store.value)
        _store.value = next
        storeFile.writeText(CepLogic.encode(next))
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
        alert.value = "Загружено: профилей ${incoming.profiles.size}, цепочек ${incoming.chains.size}"
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
