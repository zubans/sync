package com.example.contactsync.cep

import kotlinx.serialization.json.Json
import java.net.URI
import java.net.URLDecoder
import java.security.MessageDigest
import java.text.SimpleDateFormat
import java.util.Base64
import java.util.Date
import java.util.Locale

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
        return CepStore(profiles, raw.chains.map { c -> c.copy(hops = c.hops.filter { it in ids }) }, raw.subscriptions)
    }

    fun encode(store: CepStore): String = json.encodeToString(CepStore.serializer(), store)

    /** Перенос с Mac: профили и цепочки с теми же id заменяются, новые добавляются в конец. */
    fun merge(current: CepStore, incoming: CepStore): CepStore {
        val profileIds = incoming.profiles.map { it.id }.toSet()
        val chainIds = incoming.chains.map { it.id }.toSet()
        return CepStore(
            profiles = current.profiles.filter { it.id !in profileIds } + incoming.profiles,
            chains = current.chains.filter { it.id !in chainIds } + incoming.chains,
            subscriptions = current.subscriptions.filter { s -> incoming.subscriptions.none { it.id == s.id } } + incoming.subscriptions,
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
        bypass = chain.bypass,
        autoReconnect = chain.autoReconnect,
        killSwitch = chain.killSwitch,
    )

    /**
     * Сети из поля «Мимо цепочки»: через запятую, пробел или с новой строки; адрес без маски — /32.
     * Те же правила, что у движка (parseRoutes) и у macOS: при ошибке — текст для пользователя.
     */
    fun parseCidrs(text: String): Result<List<String>> {
        val result = mutableListOf<String>()
        for (item in text.split(',', ';', ' ', '\n', '\t').map { it.trim() }.filter { it.isNotEmpty() }) {
            val addr = item.substringBefore('/')
            val bits = if ('/' in item) item.substringAfter('/').toIntOrNull() else 32
            if (ipv4(addr) == null) return Result.failure(IllegalArgumentException("«$item» — не IPv4-сеть; пример: 192.168.1.119 или 10.0.0.0/8"))
            if (bits == null || bits !in 1..32) return Result.failure(IllegalArgumentException("«$item»: маска должна быть от /1 до /32"))
            val cidr = "$addr/$bits"
            if (cidr !in result) result += cidr
        }
        return Result.success(result)
    }

    /** Пауза перед попыткой переподключения N (с 1): 2, 5, 10, 20, 30 с, дальше раз в минуту. */
    fun reconnectDelayMs(attempt: Int): Long {
        val steps = longArrayOf(2, 5, 10, 20, 30)
        return (if (attempt in 1..steps.size) steps[attempt - 1] else 60) * 1000
    }

    /**
     * Куда слать проверочный DNS-запрос через цепочку. Локальные сети и исключения не годятся — ответ
     * от них придёт мимо цепочки. Без DNS цепочки — 1.1.1.1 и 8.8.8.8.
     */
    fun healthTargets(dns: List<String>, bypass: List<String>): List<String> {
        val candidates = dns.filter { ipv4(it) != null }.ifEmpty { listOf("1.1.1.1", "8.8.8.8") }
        return candidates.filter { ip -> (PRIVATE_NETS + bypass).none { cidrContains(it, ip) } }
    }

    private val PRIVATE_NETS = listOf("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.0/8", "169.254.0.0/16", "100.64.0.0/10")

    fun cidrContains(cidr: String, ip: String): Boolean {
        val net = ipv4(cidr.substringBefore('/')) ?: return false
        val host = ipv4(ip) ?: return false
        val bits = cidr.substringAfter('/', "32").toIntOrNull()?.takeIf { it in 0..32 } ?: return false
        val mask = if (bits == 0) 0L else (0xFFFFFFFFL shl (32 - bits)) and 0xFFFFFFFFL
        return (net and mask) == (host and mask)
    }

    /** IPv4 строго из четырёх десятичных чисел — без DNS, в отличие от InetAddress.getByName. */
    private fun ipv4(s: String): Long? {
        val parts = s.split('.')
        if (parts.size != 4) return null
        var v = 0L
        for (p in parts) {
            if (p.isEmpty() || p.length > 3 || !p.all(Char::isDigit)) return null
            val n = p.toInt()
            if (n > 255) return null
            v = (v shl 8) or n.toLong()
        }
        return v
    }

    /** DNS-запрос NS корневой зоны: проверка, что цепочка пропускает трафик. */
    fun dnsProbeQuery(id: Int): ByteArray = byteArrayOf(
        (id shr 8).toByte(), id.toByte(), 0x01, 0x00, 0x00, 0x01, 0, 0, 0, 0, 0, 0,
        0x00, 0x00, 0x02, 0x00, 0x01,
    )

    /** Ответ на наш запрос и не SERVFAIL: SERVFAIL может прийти и без связи с сервером. */
    fun isDnsProbeAnswer(id: Int, reply: ByteArray, length: Int): Boolean =
        length >= 12 &&
            reply[0] == (id shr 8).toByte() && reply[1] == id.toByte() &&
            reply[2].toInt() and 0x80 != 0 &&
            reply[3].toInt() and 0x0F != 2

    // Подписки

    /** Панели выбирают формат ответа по User-Agent; с этим отдают простой список ссылок (base64 или текстом). */
    const val SUBSCRIPTION_USER_AGENT = "v2rayN/7.10.0"

    /** Разница между эпохой JSONEncoder на macOS (01.01.2001) и Unix-временем, в секундах. */
    const val APPLE_EPOCH = 978_307_200.0

    /**
     * Серверы из ответа подписки: base64 (обычный или url-safe, с паддингом или без) либо текст по ссылке в строке.
     * Повторяющиеся имена получают номер, чтобы выбор сервера был однозначным. Те же правила — в CEP для macOS.
     */
    fun parseSubscription(body: String): List<CepSubscriptionServer> {
        val raw = body.trim()
        val text = if ("://" in raw) raw else decodeBase64(raw) ?: raw
        val result = mutableListOf<CepSubscriptionServer>()
        for (line in text.lines().map { it.trim() }) {
            val scheme = line.substringBefore("://", "")
            if (scheme.isEmpty() || scheme == line) continue
            var name = linkLabel(line)
            if (name.isEmpty()) name = linkAddress(line) ?: line
            var unique = name
            var n = 2
            while (result.any { it.name == unique }) unique = "$name (${n++})"
            result += CepSubscriptionServer(unique, line)
        }
        return result
    }

    private fun linkLabel(uri: String): String {
        val fragment = uri.substringAfter('#', "")
        if (fragment.isEmpty()) return ""
        return runCatching { URLDecoder.decode(fragment.replace("+", "%2B"), "UTF-8") }.getOrDefault(fragment).trim()
    }

    /** host:port ссылки — запасной ключ, если провайдер переименовал сервер. */
    fun linkAddress(uri: String): String? = runCatching {
        val u = URI(uri)
        if (u.host.isNullOrEmpty() || u.port <= 0) null else "${u.host.trim('[', ']')}:${u.port}"
    }.getOrNull()

    private fun decodeBase64(s: String): String? {
        var b = s.filterNot { it.isWhitespace() }.replace('-', '+').replace('_', '/')
        while (b.length % 4 != 0) b += "="
        return runCatching { String(Base64.getDecoder().decode(b), Charsets.UTF_8) }.getOrNull()
    }

    fun linkProtocol(uri: String): VpnProtocol? = when (uri.substringBefore(':').lowercase()) {
        "vless" -> VpnProtocol.VLESS
        "hy2", "hysteria2" -> VpnProtocol.HYSTERIA2
        "hysteria" -> VpnProtocol.HYSTERIA
        else -> null
    }

    /** Почему сервер подписки не запустится, или null. VLESS любого транспорта (и xhttp) обслуживает встроенный xray. */
    fun serverUnsupportedReason(server: CepSubscriptionServer): String? =
        if (server.protocol == null) "протокол ${server.uri.substringBefore(':')} не поддерживается" else null

    /** Remnawave без принятого HWID отдаёт заглушку «Приложение не поддерживается» с адресом 0.0.0.0. */
    fun isStub(servers: List<CepSubscriptionServer>): Boolean =
        servers.isNotEmpty() && servers.all { linkAddress(it.uri)?.startsWith("0.0.0.0:") == true }

    /** Имя подписки из заголовка profile-title (бывает «base64:…»). */
    fun subscriptionTitle(header: String?): String? {
        val h = header?.trim().orEmpty()
        if (h.isEmpty()) return null
        return if (h.startsWith("base64:")) decodeBase64(h.removePrefix("base64:"))?.trim() else h
    }

    /** «upload=0; download=…; total=0; expire=…» → «использовано 237.5 ГБ из ∞, до 27.11.2026». */
    fun subscriptionUserInfo(header: String?): String? {
        val v = header.orEmpty().split(';').mapNotNull {
            val kv = it.split('=', limit = 2).map(String::trim)
            kv.getOrNull(1)?.toLongOrNull()?.let { n -> kv[0] to n }
        }.toMap()
        if (v.isEmpty()) return null
        val gb = { b: Long -> String.format(Locale.US, "%.1f ГБ", b / 1_073_741_824.0) }
        val used = (v["upload"] ?: 0) + (v["download"] ?: 0)
        val total = v["total"] ?: 0
        var s = "использовано ${gb(used)} из ${if (total > 0) gb(total) else "∞"}"
        val expire = v["expire"] ?: 0
        if (expire > 0) s += ", до " + SimpleDateFormat("dd.MM.yyyy", Locale.ROOT).format(Date(expire * 1000))
        return s
    }

    /** HWID устройства для панели: постоянный и не раскрывает сам идентификатор (sha256, 32 hex), как на macOS. */
    fun hwid(seed: String): String =
        MessageDigest.getInstance("SHA-256").digest("cep-hwid:$seed".toByteArray())
            .joinToString("") { "%02x".format(it) }.take(32)

    /** Сервер подписки для профиля: по имени, а если провайдер переименовал — по прежнему host:port. */
    fun matchServer(servers: List<CepSubscriptionServer>, name: String, previousUri: String): CepSubscriptionServer? =
        servers.firstOrNull { it.name == name }
            ?: linkAddress(previousUri)?.let { old -> servers.firstOrNull { linkAddress(it.uri) == old } }

    /** Новый список серверов: подписка запоминает его, профили, выбравшие её серверы, получают свежие ссылки. */
    fun applySubscription(
        store: CepStore, id: String, servers: List<CepSubscriptionServer>, title: String?, info: String?, nowUnix: Double,
    ): CepStore = store.copy(
        subscriptions = store.subscriptions.map { s ->
            if (s.id != id) return@map s
            // имя из панели — только пока пользователь не переименовал подписку (оно ещё равно хосту адреса)
            val keepName = title.isNullOrEmpty() || s.name != runCatching { URI(s.url).host }.getOrNull()
            s.copy(name = if (keepName) s.name else title!!, servers = servers, updatedAt = nowUnix - APPLE_EPOCH, info = info)
        },
        profiles = store.profiles.map { p ->
            if (p.subscriptionID != id) return@map p
            val server = matchServer(servers, p.subscriptionServer, p.uri) ?: return@map p
            val proto = server.protocol ?: return@map p
            p.copy(uri = server.uri, proto = proto.raw, subscriptionServer = server.name)
        },
    )

    /** DNS из поля ввода: через запятую, пробел или точку с запятой. */
    fun parseDns(text: String): List<String> = text.split(',', ' ', ';').map { it.trim() }.filter { it.isNotEmpty() }

    /** Новое имя профиля: «VLESS 3», если профилей VLESS уже два. */
    fun newProfileName(proto: VpnProtocol, profiles: List<CepProfile>): String =
        "${proto.title} ${profiles.count { it.proto == proto.raw } + 1}"
}
