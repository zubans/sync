package com.example.contactsync.launcher

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.io.File
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

/**
 * Включение и выключение туннеля. Root приложению не выдаётся, поэтому оно только пишет желаемое
 * состояние в свой файл `vpn-wanted`, а служба под root (`tablet/vpnctl.sh`) применяет его через
 * `occtl.sh` и раз в 2 с обновляет время изменения `vpn-watch`. Нет свежей отметки — служба не запущена,
 * и управлять туннелем отсюда нельзя.
 */
class VpnControl(private val dir: File) {

    /** Что пользователь выбрал последним; null — ни разу не переключал. */
    fun wanted(): Boolean? = when (read(WANTED)) {
        "on" -> true
        "off" -> false
        else -> null
    }

    fun request(on: Boolean) {
        // Через временный файл: служба не должна прочитать недописанное значение.
        val tmp = File(dir, "$WANTED.tmp")
        tmp.writeText(if (on) "on" else "off")
        tmp.renameTo(File(dir, WANTED))
    }

    /** Служба отмечалась недавно: она обновляет время изменения `vpn-watch` (без внешних команд — дешевле). */
    fun available(nowSeconds: Long): Boolean =
        VpnLamp.controlAvailable(File(dir, WATCH).lastModified().takeIf { it > 0 }?.div(1000), nowSeconds)

    private fun read(name: String): String? = runCatching { File(dir, name).readText().trim() }.getOrNull()

    private companion object {
        const val WANTED = "vpn-wanted"
        const val WATCH = "vpn-watch"
    }
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

    /** Служба отмечается раз в 2 с; 10 с без отметки — считаем, что её нет. */
    fun controlAvailable(heartbeatSeconds: Long?, nowSeconds: Long): Boolean =
        heartbeatSeconds != null && nowSeconds - heartbeatSeconds in -5..10

    /**
     * Переключение ещё не дошло до туннеля: попросили включить, а его нет, или выключить, а он есть.
     * Пока так — проверяем туннель чаще, чтобы лампочка сменилась сразу.
     */
    fun switching(wanted: Boolean?, state: VpnState): Boolean = when (wanted) {
        true -> state == VpnState.DOWN
        false -> state != VpnState.DOWN
        null -> false
    }

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
