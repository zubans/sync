package com.example.contactsync.cep

import kotlinx.serialization.Serializable
import java.util.UUID

/** Протоколы узлов — те же, что в CEP для macOS: значения совпадают с полем proto в profiles.json. */
enum class VpnProtocol(val raw: String, val title: String) {
    VLESS("vless", "VLESS"),
    HYSTERIA2("hysteria2", "Hysteria 2"),
    HYSTERIA("hysteria", "Hysteria (v1)"),
    AMNEZIAWG("amneziawg", "AmneziaWG"),
    OPENVPN("openvpn", "OpenVPN"),
    OPENCONNECT("openconnect", "OpenConnect"),
    SSTP("sstp", "SSTP");

    /** Профиль задаётся ссылкой vless:// hysteria2:// hysteria://. */
    val isLink: Boolean get() = this == VLESS || this == HYSTERIA2 || this == HYSTERIA

    /** Почему протокол не работает на Android, или null. Совпадает с cepcore.Supported: в sing-box этих протоколов нет. */
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

/** Профиль узла. Поля — как в profiles.json CEP для macOS, файл переносится между Mac и телефоном как есть. */
@Serializable
data class CepProfile(
    val id: String = newCepId(),
    val name: String,
    val proto: String,
    /** Ссылка vless:// hysteria2:// hy2:// hysteria:// */
    val uri: String = "",
    /** Текст .ovpn или .conf AmneziaWG. */
    val config: String = "",
    /** host, host:port или https://host:port — для OpenConnect и SSTP. */
    val server: String = "",
    val username: String = "",
    val password: String = "",
    val openconnectFlavor: String = "anyconnect",
    /** pin-sha256:… или sha1/sha256-отпечаток сертификата сервера OpenConnect. */
    val serverCert: String = "",
    val authGroup: String = "",
    val ignoreCertErrors: Boolean = false,
    /** Сети, которые идут через этот узел, а не дальше по цепочке: «192.168.1.119, 10.0.0.0/8». */
    val routes: String = "",
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
)

/** Содержимое profiles.json. */
@Serializable
data class CepStore(
    val profiles: List<CepProfile> = emptyList(),
    val chains: List<CepChain> = emptyList(),
)

/** Задание движку: цепочка с развёрнутыми профилями (как Plan в CEPCore на macOS). */
@Serializable
data class CepPlan(
    val chainName: String,
    val hops: List<CepProfile>,
    val dns: List<String>,
    val blockIPv6: Boolean,
)

/** UUID в верхнем регистре — так их пишет JSONEncoder на macOS. */
fun newCepId(): String = UUID.randomUUID().toString().uppercase()
