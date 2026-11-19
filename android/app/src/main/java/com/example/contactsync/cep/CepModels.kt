package com.example.contactsync.cep

import kotlinx.serialization.SerialName
import kotlinx.serialization.Serializable
import java.util.UUID

/** Протоколы узлов — значения совпадают с полем proto в profiles.json. */
enum class VpnProtocol(val raw: String, val title: String) {
    VLESS("vless", "VLESS"),
    HYSTERIA2("hysteria2", "Hysteria 2"),
    HYSTERIA("hysteria", "Hysteria (v1)"),
    AMNEZIAWG("amneziawg", "AmneziaWG"),
    OPENVPN("openvpn", "OpenVPN"),
    OPENCONNECT("openconnect", "OpenConnect"),
    ARGON4("argon4", "Argon4"),
    TRANSIT("transit", "Транзитный шлюз"),
    SSTP("sstp", "SSTP");

    /** Профиль задаётся ссылкой vless:// hysteria2:// hysteria://. */
    val isLink: Boolean get() = this == VLESS || this == HYSTERIA2 || this == HYSTERIA

    /** Почему протокол не работает на Android, или null. Совпадает с cepcore.Supported. */
    val unsupportedReason: String?
        get() = when (this) {
            AMNEZIAWG -> "AmneziaWG на Android пока не поддерживается"
            SSTP -> "SSTP на Android пока не поддерживается"
            else -> null
        }

    companion object {
        fun of(raw: String): VpnProtocol? = entries.firstOrNull { it.raw == raw }
    }
}

enum class OpenConnectFlavor(val raw: String, val title: String) {
    ANYCONNECT("anyconnect", "Cisco AnyConnect / ocserv"),
    GP("gp", "Palo Alto GlobalProtect"),
    NC("nc", "Juniper Network Connect"),
    PULSE("pulse", "Pulse Connect Secure"),
    FORTINET("fortinet", "Fortinet"),
    ARRAY("array", "Array Networks"),
}

/** Профиль узла. Общие поля совместимы с profiles.json CEP для macOS. */
@Serializable
data class CepProfile(
    val id: String = newCepId(),
    val name: String,
    val proto: String,
    /** Ссылка vless:// hysteria2:// hy2:// hysteria:// */
    val uri: String = "",
    /** Профиль из подписки: id подписки и имя выбранного сервера. Ссылку uri берёт при обновлении подписки. */
    val subscriptionID: String? = null,
    val subscriptionServer: String = "",
    /** Текст .ovpn или .conf AmneziaWG. */
    val config: String = "",
    /** host:port — для Argon4; host, host:port или URL — для OpenConnect и SSTP. */
    val server: String = "",
    val username: String = "",
    /** Для Argon4 — мастер-пароль протокола. */
    val password: String = "",
    /** Идентификатор владельца Argon4-сессии; пустой значит id профиля. */
    val clientID: String = "",
    val clientSecret: String = "",
    val openconnectFlavor: String = "anyconnect",
    /** pin-sha256:… или sha1/sha256-отпечаток сертификата сервера OpenConnect. */
    val serverCert: String = "",
    val authGroup: String = "",
    val ignoreCertErrors: Boolean = false,
    /** Сети, которые идут через этот узел, а не дальше по цепочке: «192.168.1.119, 10.0.0.0/8». */
    val routes: String = "",
    /** Подключаться к узлу внутри обфускации Argon4 (без отдельного поля сервера). */
    val useArgon: Boolean = false,
    /** Порт обёртки Argon4 на узле при useArgon; null — 51820. */
    val argonPort: Int? = null,
    val argonPassword: String = "",
    val argonProtocolVersion: Int? = null,
    val argonPaddingProfile: String? = null,
    val argonFirstPacketProfile: String? = null,
    val argonPathMTU: Int? = null,
    /** Максимум попыток UDP-рукопожатия Argon4. */
    val argonHandshakeMaxAttempts: Int? = null,
    /** Начальный таймаут попытки handshake Argon4 (мс). */
    val argonHandshakeInitialTimeoutMs: Int? = null,
    /** Потолок таймаута одной попытки handshake Argon4 (мс). */
    val argonHandshakeMaxTimeoutMs: Int? = null,
    /** Минимальный простой перед отправкой keepalive Argon4 (с). */
    val argonKeepaliveMinIdleSec: Int? = null,
    /** Максимальный простой перед отправкой keepalive Argon4 (с). */
    val argonKeepaliveMaxIdleSec: Int? = null,
    /** Поведенческий профиль трафика Argon4 (null/"" — выкл., "baseline", "video-call"). */
    val argonBehaviorProfile: String? = null,
    /** Режим пониженного энергопотребления для профилей Argon4. */
    val argonLowPowerMode: Boolean = false,
    /** Лимит длительности профиля video-call в секундах. */
    val argonVideoCallMaxDurationSec: Int? = null,
) {
    val protocol: VpnProtocol? get() = VpnProtocol.of(proto)
}

/** Цепочка: узлы по порядку, каждый следующий выходит в сеть через предыдущий. */
@Serializable
data class CepChain(
    val id: String = newCepId(),
    val name: String,
    /** id профилей по порядку. */
    val hops: List<String> = emptyList(),
    /** DNS устройства, пока цепочка поднята; запросы к ним идут через цепочку. */
    val dns: List<String> = listOf("1.1.1.1", "8.8.8.8"),
    val blockIPv6: Boolean = true,
    /** Сети и адреса мимо цепочки, напрямую через сеть устройства: «192.168.0.0/16, 203.0.113.7». */
    val bypass: String = "",
    /** После разрыва поднимать цепочку заново, пока пользователь не отключит её сам. */
    val autoReconnect: Boolean = true,
    /** Пока цепочка переподключается, не выпускать трафик мимо неё (кроме исключений). */
    val killSwitch: Boolean = true,
)

/** Сервер из подписки: имя (метка #… ссылки) и сама ссылка. */
@Serializable
data class CepSubscriptionServer(val name: String, val uri: String) {
    /** Протокол узла или null, если CEP такую ссылку не умеет (trojan, ss, vmess…). */
    val protocol: VpnProtocol? get() = CepLogic.linkProtocol(uri)
}

/**
 * Подписка провайдера: адрес, по которому панель (Remnawave, Marzban и т.п.) отдаёт список серверов.
 * Профиль выбирает из неё один сервер и при обновлении подписки получает его свежую ссылку.
 */
@Serializable
data class CepSubscription(
    val id: String = newCepId(),
    val name: String,
    val url: String,
    /** HWID вместо идентификатора этого телефона — чтобы не занимать новое место в лимите устройств. Пусто — свой. */
    val hwid: String = "",
    val servers: List<CepSubscriptionServer> = emptyList(),
    /** Время обновления в секундах от 01.01.2001 — так дату пишет JSONEncoder на macOS. */
    val updatedAt: Double? = null,
    /** Трафик и срок действия из заголовка subscription-userinfo. */
    val info: String? = null,
)

/** Содержимое profiles.json. */
@Serializable
data class CepStore(
    val profiles: List<CepProfile> = emptyList(),
    val chains: List<CepChain> = emptyList(),
    val subscriptions: List<CepSubscription> = emptyList(),
)

/** Задание движку: цепочка с развёрнутыми профилями (как Plan в CEPCore на macOS). */
@Serializable
data class CepPlan(
    val chainName: String,
    val hops: List<CepProfile>,
    val dns: List<String>,
    val blockIPv6: Boolean,
    val bypass: String = "",
    val autoReconnect: Boolean = true,
    val killSwitch: Boolean = true,
    /** Адреса серверов с прошлого подключения — для переподключения под kill switch, когда DNS недоступен. */
    val serverIPs: List<String>? = null,
)

/** UUID в верхнем регистре — так их пишет JSONEncoder на macOS. */
fun newCepId(): String = UUID.randomUUID().toString().uppercase()

/** Результат подбора оптимального внешнего MTU для узла цепочки (ответ Go-движка). */
@Serializable
data class MtuProbeResult(
    val ok: Boolean = false,
    val proto: String = "",
    val server: String = "",
    @SerialName("optimal_path_mtu") val optimalPathMtu: Int = 0,
    @SerialName("inner_tun_mtu") val innerTunMtu: Int = 0,
    @SerialName("effective_outer_udp") val effectiveOuterUdp: Int = 0,
    @SerialName("interface_mtu") val interfaceMtu: Int = 0,
    @SerialName("tcp_mss") val tcpMss: Int = 0,
    @SerialName("rtt_ms") val rttMs: Long = 0,
    val method: String = "",
    val message: String = "",
    @SerialName("preceding_budget") val precedingBudget: Int = 0,
    @SerialName("supports_in_band") val supportsInBand: Boolean = false,
    val error: String = "",
)
