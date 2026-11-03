package app.cep

import kotlinx.serialization.Serializable
import java.util.UUID

/** Протоколы те же, что в приложении для macOS: значения совпадают с полем proto в profiles.json. */
enum class VpnProtocol(val raw: String, val title: String) {
    VLESS("vless", "VLESS"),
    HYSTERIA2("hysteria2", "Hysteria 2"),
    HYSTERIA("hysteria", "Hysteria (v1)"),
    AMNEZIAWG("amneziawg", "AmneziaWG"),
    OPENVPN("openvpn", "OpenVPN"),
    OPENCONNECT("openconnect", "OpenConnect"),
    SSTP("sstp", "SSTP");

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

/** Профиль узла. Формат полей — как в profiles.json приложения для macOS, файл переносится как есть. */
@Serializable
data class Profile(
    val id: String = newId(),
    val name: String,
    val proto: String,
    val uri: String = "",
    val config: String = "",
    val server: String = "",
    val username: String = "",
    val password: String = "",
    val openconnectFlavor: String = "anyconnect",
    val serverCert: String = "",
    val authGroup: String = "",
    val ignoreCertErrors: Boolean = false,
    val routes: String = "",
) {
    val protocol: VpnProtocol? get() = VpnProtocol.of(proto)
}

@Serializable
data class Chain(
    val id: String = newId(),
    val name: String,
    val hops: List<String> = emptyList(),
    val dns: List<String> = listOf("1.1.1.1", "8.8.8.8"),
    val blockIPv6: Boolean = true,
)

@Serializable
data class Store(
    val profiles: List<Profile> = emptyList(),
    val chains: List<Chain> = emptyList(),
)

/** Задание движку: цепочка с развёрнутыми профилями (как Plan в CEPCore). */
@Serializable
data class Plan(
    val chainName: String,
    val hops: List<Profile>,
    val dns: List<String>,
    val blockIPv6: Boolean,
)

/** UUID в верхнем регистре — так их пишет JSONEncoder на macOS. */
fun newId(): String = UUID.randomUUID().toString().uppercase()
