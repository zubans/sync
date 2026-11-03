package app.cep

import android.app.Application
import android.content.Intent
import android.net.Uri
import androidx.core.content.ContextCompat
import androidx.lifecycle.AndroidViewModel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File

val json = Json {
    ignoreUnknownKeys = true
    encodeDefaults = true
    prettyPrint = true
}

class AppModel(app: Application) : AndroidViewModel(app) {
    /** Тот же формат, что ~/Library/Application Support/CEP/profiles.json на macOS. */
    private val storeFile = File(app.filesDir, "profiles.json")

    private val _store = MutableStateFlow(load())
    val store: StateFlow<Store> = _store.asStateFlow()

    val alert = MutableStateFlow<String?>(null)

    private fun load(): Store {
        if (!storeFile.exists()) return Store()
        return runCatching { decode(storeFile.readText()) }.getOrElse {
            alert.value = "Не удалось прочитать профили: ${it.message}"
            Store()
        }
    }

    /** Профили неизвестных протоколов пропускаем, а не теряем весь файл; из цепочек убираем пропавшие узлы. */
    private fun decode(text: String): Store {
        val raw = json.decodeFromString<Store>(text)
        val profiles = raw.profiles.filter { it.protocol != null }
        val ids = profiles.map { it.id }.toSet()
        return Store(profiles, raw.chains.map { c -> c.copy(hops = c.hops.filter { it in ids }) })
    }

    private fun update(block: (Store) -> Store) {
        val next = block(_store.value)
        _store.value = next
        // в файле пароли: он лежит в закрытом каталоге приложения
        storeFile.writeText(json.encodeToString(next))
    }

    // Профили

    fun profile(id: String): Profile? = _store.value.profiles.firstOrNull { it.id == id }

    fun addProfile(proto: VpnProtocol): Profile {
        val n = _store.value.profiles.count { it.proto == proto.raw } + 1
        val p = Profile(name = "${proto.title} $n", proto = proto.raw)
        update { it.copy(profiles = it.profiles + p) }
        return p
    }

    /** Профили из ссылок vless:// hy2:// hysteria2:// hysteria:// — по одной в строке. */
    fun importLinks(text: String): Int {
        val added = mutableListOf<Profile>()
        for (line in text.lines().map { it.trim() }.filter { it.isNotEmpty() }) {
            val scheme = line.substringBefore(":").lowercase()
            val proto = when (scheme) {
                "vless" -> VpnProtocol.VLESS
                "hy2", "hysteria2" -> VpnProtocol.HYSTERIA2
                "hysteria" -> VpnProtocol.HYSTERIA
                else -> {
                    alert.value = "Это не ссылка vless://, hysteria2://, hy2:// или hysteria://: ${line.take(40)}"
                    continue
                }
            }
            val uri = runCatching { Uri.parse(line) }.getOrNull()
            val label = uri?.fragment.orEmpty()
            val name = label.ifEmpty { "${proto.title} ${uri?.host.orEmpty()}" }
            added += Profile(name = name, proto = proto.raw, uri = line)
        }
        if (added.isNotEmpty()) update { it.copy(profiles = it.profiles + added) }
        return added.size
    }

    fun saveProfile(p: Profile) = update { s ->
        s.copy(profiles = s.profiles.map { if (it.id == p.id) p else it })
    }

    fun deleteProfile(id: String) = update { s ->
        s.copy(
            profiles = s.profiles.filter { it.id != id },
            chains = s.chains.map { c -> c.copy(hops = c.hops.filter { it != id }) },
        )
    }

    // Цепочки

    fun chain(id: String): Chain? = _store.value.chains.firstOrNull { it.id == id }

    fun addChain(): Chain {
        val c = Chain(name = "Цепочка ${_store.value.chains.size + 1}")
        update { it.copy(chains = it.chains + c) }
        return c
    }

    fun saveChain(c: Chain) = update { s ->
        s.copy(chains = s.chains.map { if (it.id == c.id) c else it })
    }

    fun deleteChain(id: String) = update { s -> s.copy(chains = s.chains.filter { it.id != id }) }

    fun plan(chain: Chain): Plan = Plan(
        chainName = chain.name,
        hops = chain.hops.mapNotNull { profile(it) },
        dns = chain.dns,
        blockIPv6 = chain.blockIPv6,
    )

    /** Ошибки, из-за которых цепочку не запустить (проверяет движок). */
    fun problems(chain: Chain): List<String> {
        val result = cepcore.Cepcore.validate(json.encodeToString(plan(chain)))
        return json.decodeFromString<List<String>>(result)
    }

    // Перенос настроек с macOS

    /** profiles.json с Mac: профили и цепочки с теми же id заменяются, новые добавляются. */
    fun importStore(uri: Uri) {
        val ctx = getApplication<Application>()
        val text = runCatching {
            ctx.contentResolver.openInputStream(uri)!!.use { it.readBytes().decodeToString() }
        }.getOrElse {
            alert.value = "Не удалось прочитать файл: ${it.message}"
            return
        }
        val incoming = runCatching { decode(text) }.getOrElse {
            alert.value = "Это не profiles.json приложения CEP: ${it.message}"
            return
        }
        update { s ->
            val pIds = incoming.profiles.map { it.id }.toSet()
            val cIds = incoming.chains.map { it.id }.toSet()
            Store(
                profiles = s.profiles.filter { it.id !in pIds } + incoming.profiles,
                chains = s.chains.filter { it.id !in cIds } + incoming.chains,
            )
        }
        alert.value = "Загружено: профилей ${incoming.profiles.size}, цепочек ${incoming.chains.size}"
    }

    fun exportStore(uri: Uri) {
        val ctx = getApplication<Application>()
        runCatching {
            ctx.contentResolver.openOutputStream(uri, "wt")!!.use {
                it.write(json.encodeToString(_store.value).toByteArray())
            }
        }.onFailure { alert.value = "Не удалось сохранить: ${it.message}" }
    }

    // Подключение

    fun connect(chain: Chain) {
        if (Engine.state.value.isActive) {
            alert.value = "Сначала отключите текущую цепочку"
            return
        }
        val problems = runCatching { problems(chain) }.getOrElse { listOf(it.message ?: "ошибка") }
        if (problems.isNotEmpty()) {
            alert.value = problems.joinToString("\n")
            return
        }
        val ctx = getApplication<Application>()
        val intent = Intent(ctx, CepVpnService::class.java)
            .setAction(CepVpnService.ACTION_START)
            .putExtra(CepVpnService.EXTRA_PLAN, json.encodeToString(plan(chain)))
        ContextCompat.startForegroundService(ctx, intent)
    }

    fun disconnect() {
        val ctx = getApplication<Application>()
        ctx.startService(Intent(ctx, CepVpnService::class.java).setAction(CepVpnService.ACTION_STOP))
    }
}
