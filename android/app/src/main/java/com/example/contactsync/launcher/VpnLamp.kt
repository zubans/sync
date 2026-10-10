package com.example.contactsync.launcher

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.net.Inet4Address
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.net.Socket

enum class VpnState(val title: String) {
    /** Туннель поднят, KB за ним отвечает. */
    UP("VPN подключён"),
    /** Интерфейс есть, но сервер за туннелем не отвечает — openconnect переподключается. */
    DEGRADED("VPN есть, KB не отвечает"),
    DOWN("VPN не подключён"),
}

/** Что увидели при проверке туннеля. */
data class VpnProbe(val interfaceUp: Boolean, val address: String?, val kbReachable: Boolean)

/**
 * Лампочка VPN. На планшете это не Android VpnService, а openconnect под root (Alpine в /data/local/oc),
 * поэтому системные API его не видят. Признаки — интерфейс `oc0` с адресом и ответ KB,
 * которая доступна только через туннель.
 */
object VpnLamp {
    const val INTERFACE = "oc0"

    fun state(probe: VpnProbe): VpnState = when {
        !probe.interfaceUp || probe.address == null -> VpnState.DOWN
        probe.kbReachable -> VpnState.UP
        else -> VpnState.DEGRADED
    }

    suspend fun probe(kbHost: String, kbPort: Int): VpnProbe = withContext(Dispatchers.IO) {
        val nic = runCatching { NetworkInterface.getByName(INTERFACE) }.getOrNull()
        val up = runCatching { nic?.isUp == true }.getOrDefault(false)
        val address = nic?.inetAddresses?.toList()?.firstOrNull { it is Inet4Address }?.hostAddress
        val reachable = up && runCatching {
            Socket().use { it.connect(InetSocketAddress(kbHost, kbPort), 3_000) }
            true
        }.getOrDefault(false)
        VpnProbe(up, address, reachable)
    }
}
