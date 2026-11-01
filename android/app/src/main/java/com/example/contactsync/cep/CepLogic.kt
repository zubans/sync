package com.example.contactsync.cep

import kotlinx.serialization.json.Json
import java.net.URI

/** Чистая логика CEP без Android API: формат profiles.json, перенос с Mac, ссылки, задание движку. */
object CepLogic {
    val json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
        prettyPrint = true
    }

    /**
     * Разбор profiles.json. Профили неизвестных протоколов пропускаются, а не ломают весь файл
     * (так же делает приложение для macOS); из цепочек убираются узлы, которых нет среди профилей.
     */
    fun decode(text: String): CepStore {
        val raw = json.decodeFromString(CepStore.serializer(), text)
        val profiles = raw.profiles.filter { it.protocol != null }
        val ids = profiles.map { it.id }.toSet()
        return CepStore(profiles, raw.chains.map { c -> c.copy(hops = c.hops.filter { it in ids }) })
    }

    fun encode(store: CepStore): String = json.encodeToString(CepStore.serializer(), store)

    /** Перенос с Mac: профили и цепочки с теми же id заменяются, новые добавляются в конец. */
    fun merge(current: CepStore, incoming: CepStore): CepStore {
        val profileIds = incoming.profiles.map { it.id }.toSet()
        val chainIds = incoming.chains.map { it.id }.toSet()
        return CepStore(
            profiles = current.profiles.filter { it.id !in profileIds } + incoming.profiles,
            chains = current.chains.filter { it.id !in chainIds } + incoming.chains,
        )
    }

    /** Результат разбора ссылок: новые профили и строки, которые не похожи на ссылки. */
    data class ParsedLinks(
        val profiles: List<CepProfile>,
        /** Строки с неизвестной схемой — их показываем пользователю. */
        val rejected: List<String>,
    )

    /**
     * Профили из ссылок vless:// hy2:// hysteria2:// hysteria:// — по одной в строке.
     * Имя — из #метки ссылки, без неё — «протокол хост». Ссылку целиком проверяет движок при подключении.
     */
    fun parseLinks(text: String): ParsedLinks {
        val profiles = mutableListOf<CepProfile>()
        val rejected = mutableListOf<String>()
        for (line in text.lines().map { it.trim() }.filter { it.isNotEmpty() }) {
            val proto = when (line.substringBefore(":").lowercase()) {
                "vless" -> VpnProtocol.VLESS
                "hy2", "hysteria2" -> VpnProtocol.HYSTERIA2
                "hysteria" -> VpnProtocol.HYSTERIA
                else -> {
                    rejected += line
                    continue
                }
            }
            // Ссылка с перескоком портов (host:443,2000-3000) — не URI; имя тогда без хоста.
            val uri = runCatching { URI(line) }.getOrNull()
            val label = uri?.fragment.orEmpty()
            val name = label.ifEmpty { listOfNotNull(proto.title, uri?.host).joinToString(" ") }
            profiles += CepProfile(name = name, proto = proto.raw, uri = line)
        }
        return ParsedLinks(profiles, rejected)
    }

    /** Задание движку: узлы цепочки по порядку, пропавшие профили отбрасываются. */
    fun plan(chain: CepChain, profiles: List<CepProfile>): CepPlan = CepPlan(
        chainName = chain.name,
        hops = chain.hops.mapNotNull { id -> profiles.firstOrNull { it.id == id } },
        dns = chain.dns,
        blockIPv6 = chain.blockIPv6,
    )

    /** DNS из поля ввода: через запятую, пробел или точку с запятой. */
    fun parseDns(text: String): List<String> = text.split(',', ' ', ';').map { it.trim() }.filter { it.isNotEmpty() }

    /** Новое имя профиля: «VLESS 3», если профилей VLESS уже два. */
    fun newProfileName(proto: VpnProtocol, profiles: List<CepProfile>): String =
        "${proto.title} ${profiles.count { it.proto == proto.raw } + 1}"
}
