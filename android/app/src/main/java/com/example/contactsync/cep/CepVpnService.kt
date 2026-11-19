package com.example.contactsync.cep

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.ServiceInfo
import android.net.ConnectivityManager
import android.net.LinkProperties
import android.net.Network
import android.net.NetworkCapabilities
import android.net.NetworkRequest
import android.net.VpnService
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.os.ParcelFileDescriptor
import android.system.OsConstants
import com.example.contactsync.R
import kotlinx.serialization.Serializable
import java.io.File
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.Inet4Address
import java.net.InetAddress
import java.net.NetworkInterface
import kotlin.random.Random
import kotlin.concurrent.thread

/**
 * VPN цепочки CEP. Вся цепочка — один экземпляр sing-box из движка cepcore:
 * узел N выходит в сеть через узел N-1, весь трафик VPN уходит в последний узел.
 * Сокеты движка выводятся из VPN через [protect], иначе узлы зациклятся сами на себя.
 */
class CepVpnService : VpnService(), cepcore.Platform {

    /** Параметры VPN от движка (TunSettings в cep-engine/service.go). */
    @Serializable
    private data class TunSettings(
        val address: String,
        val prefix: Int,
        val mtu: Int,
        val dns: List<String>,
        val blockIPv6: Boolean,
        /** Что заворачивать в VPN: 0.0.0.0/0 без исключений цепочки. */
        val routes: List<String> = listOf("0.0.0.0/0"),
    )

    /** Сетевой интерфейс для движка: Go на Android не может прочитать их сам через netlink. */
    @Serializable
    private data class NetInterface(
        val name: String,
        val index: Int,
        val mtu: Int,
        val addresses: List<String>,
        val dns: List<String>,
        val type: String,
        val metered: Boolean,
        val default: Boolean,
    )

    private var engine: cepcore.Service? = null
    private var tun: ParcelFileDescriptor? = null
    @Volatile private var stopping = false
    /** Адреса серверов с последнего удачного подключения: под kill switch DNS устройства недоступен. */
    @Volatile private var serverIPs: List<String>? = null
    private var defaultNetwork: Network? = null
    private val connectivity by lazy { getSystemService(ConnectivityManager::class.java) }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> start(intent.getStringExtra(EXTRA_CHAIN_ID).orEmpty())
            ACTION_STOP -> stop()
        }
        return START_NOT_STICKY
    }

    override fun onRevoke() = stop()

    override fun onDestroy() {
        shutdown()
        super.onDestroy()
    }

    private fun start(chainId: String) {
        val plan = runCatching {
            val store = CepLogic.decode(File(filesDir, CepViewModel.STORE_FILE).readText())
            store.chains.firstOrNull { it.id == chainId }?.let { CepLogic.plan(it, store.profiles) }
        }.getOrNull()
        if (plan == null) {
            CepEngine.set { it.copy(phase = CepPhase.FAILED, error = "цепочка не найдена") }
            stopSelf()
            return
        }
        startForeground(plan.chainName)
        CepEngine.set { CepEngineState(CepPhase.CONNECTING, plan.chainName, plan.hops) }
        CepEngine.log("подключение «${plan.chainName}»: ${plan.hops.joinToString(" → ") { it.name }}")
        watchDefaultNetwork()
        synchronized(this) { stopping = false }
        serverIPs = null
        thread(name = "cep-chain") { runChain(plan) }
    }

    /**
     * Поднимает цепочку и держит её. После разрыва уже работавшей цепочки закрывает движок и поднимает его
     * заново с нарастающей паузой, пока не нажмут «Отключить». Если не удалось первое подключение, повтора нет:
     * это почти всегда настройки, и пользователь как раз смотрит на экран.
     */
    private fun runChain(plan: CepPlan) {
        var wasConnected = false
        var attempt = 0
        while (!stopping) {
            try {
                val cached = serverIPs
                val p = if (attempt > 0 && plan.killSwitch && cached != null) plan.copy(serverIPs = cached) else plan
                val started = cepcore.Cepcore.start(CepLogic.json.encodeToString(CepPlan.serializer(), p), filesDir.absolutePath, this)
                synchronized(this) {
                    // «Отключить» нажали, пока цепочка поднималась
                    if (stopping) {
                        started.close()
                        tun?.close()
                        tun = null
                        return
                    }
                    engine = started
                }
                serverIPs = started.serverIPs().split(',').filter { it.isNotEmpty() }
                defaultNetwork?.let { report(connectivity.getLinkProperties(it)) }
                val reconnected = attempt > 0
                wasConnected = true
                attempt = 0
                CepEngine.set {
                    it.copy(
                        phase = CepPhase.CONNECTED, error = null, reconnectAttempt = null, nextAttemptAt = null,
                        killSwitch = false, reconnects = it.reconnects + if (reconnected) 1 else 0,
                    )
                }
                CepEngine.log(if (reconnected) "цепочка поднята заново" else "цепочка запущена")
                if (!plan.autoReconnect) return
                val broken = watch(plan) ?: return
                throw IllegalStateException(broken)
            } catch (e: Exception) {
                if (stopping) return
                CepEngine.log("ошибка: ${e.message}")
                if (!wasConnected || !plan.autoReconnect) {
                    shutdown()
                    CepEngine.set { it.copy(phase = CepPhase.FAILED, error = e.message, killSwitch = false) }
                    stopSelf()
                    return
                }
                closeEngine(keepTun = plan.killSwitch)
                attempt++
                val delay = CepLogic.reconnectDelayMs(attempt)
                CepEngine.set {
                    it.copy(
                        phase = CepPhase.RECONNECTING, error = e.message, reconnectAttempt = attempt,
                        nextAttemptAt = System.currentTimeMillis() + delay, killSwitch = plan.killSwitch,
                    )
                }
                CepEngine.log(
                    "переподключение: попытка $attempt через ${delay / 1000} с" +
                        if (plan.killSwitch) " (kill switch: трафик мимо цепочки заблокирован)" else "",
                )
                if (!pause(delay)) return
            }
        }
    }

    /**
     * Следит за живостью цепочки: каждые 15 с (первый раз через 30 с) шлёт через VPN DNS-запрос к DNS цепочки.
     * Три неудачи подряд — разрыв, возвращает причину; null — пришла команда остановки.
     * Проверка по UDP: TCP sing-box принимает сам, ещё до выхода в сеть.
     */
    private fun watch(plan: CepPlan): String? {
        val bypass = CepLogic.parseCidrs(plan.bypass).getOrDefault(emptyList())
        val targets = CepLogic.healthTargets(plan.dns, bypass)
        if (targets.isEmpty()) CepEngine.log("проверка связи выключена: DNS цепочки только в локальной сети или исключениях")
        var failures = 0
        if (!pause(30_000)) return null
        while (!stopping) {
            if (targets.isNotEmpty()) {
                if (targets.any(::dnsAnswers)) {
                    failures = 0
                } else {
                    failures++
                    CepEngine.log("нет ответа через цепочку от ${targets.joinToString(", ")} ($failures/3)")
                    if (failures >= 3) return "цепочка не отвечает: нет ответа DNS от ${targets.joinToString(", ")} за 45 с"
                }
            }
            if (!pause(15_000)) return null
        }
        return null
    }

    /** Сокет не защищён через protect, поэтому запрос идёт через VPN, то есть через всю цепочку. */
    private fun dnsAnswers(server: String): Boolean = runCatching {
        DatagramSocket().use { socket ->
            socket.soTimeout = 2000
            val id = Random.nextInt(1, 0xFFFF)
            val query = CepLogic.dnsProbeQuery(id)
            socket.send(DatagramPacket(query, query.size, InetAddress.getByName(server), 53))
            val buf = ByteArray(1500)
            val deadline = System.currentTimeMillis() + 2000
            while (System.currentTimeMillis() < deadline) {
                val packet = DatagramPacket(buf, buf.size)
                socket.receive(packet)
                if (CepLogic.isDnsProbeAnswer(id, buf, packet.length)) return@use true
            }
            false
        }
    }.getOrDefault(false)

    /** Пауза с проверкой «Отключить»; false — цепочку отключают. */
    private fun pause(ms: Long): Boolean {
        val deadline = System.currentTimeMillis() + ms
        while (System.currentTimeMillis() < deadline) {
            if (stopping) return false
            Thread.sleep(250)
        }
        return !stopping
    }

    /**
     * Закрывает движок перед новой попыткой. keepTun — kill switch: VPN остаётся поднятым, но читать его
     * некому, и трафик мимо цепочки не уходит; исключения в VPN не попадают и работают.
     */
    @Synchronized
    private fun closeEngine(keepTun: Boolean) {
        engine?.close()
        engine = null
        if (!keepTun) {
            tun?.close()
            tun = null
        }
    }

    private fun stop() {
        synchronized(this) { stopping = true }
        CepEngine.set { it.copy(phase = CepPhase.STOPPING) }
        thread(name = "cep-stop") {
            shutdown()
            CepEngine.set { it.copy(phase = CepPhase.STOPPED, reconnectAttempt = null, nextAttemptAt = null, killSwitch = false) }
            CepEngine.log("остановлено")
            stopSelf()
        }
    }

    @Synchronized
    private fun shutdown() {
        runCatching { connectivity.unregisterNetworkCallback(networkCallback) }
        engine?.close()
        engine = null
        tun?.close()
        tun = null
        stopForeground(STOP_FOREGROUND_REMOVE)
    }

    // cepcore.Platform

    override fun protect(fd: Int): Boolean = super.protect(fd)

    override fun openTun(settingsJSON: String): Int {
        if (stopping) throw IllegalStateException("цепочку отключают")
        if (prepare(this) != null) throw IllegalStateException("нет разрешения на VPN — откройте CEP")
        val s = CepLogic.json.decodeFromString(TunSettings.serializer(), settingsJSON)
        val builder = Builder()
            .setSession(CepEngine.state.value.chainName)
            .setMtu(s.mtu)
            .addAddress(s.address, s.prefix)
            .setConfigureIntent(
                PendingIntent.getActivity(this, 0, Intent(this, CepActivity::class.java), PendingIntent.FLAG_IMMUTABLE),
            )
        // Исключения цепочки в маршруты VPN не входят — их трафик идёт напрямую через сеть устройства.
        s.routes.forEach { builder.addRoute(it.substringBefore('/'), it.substringAfter('/').toInt()) }
        s.dns.forEach { builder.addDnsServer(it) }
        // Без адреса и маршрутов IPv6 Android блокирует IPv6 целиком — это и есть «блокировать IPv6».
        if (!s.blockIPv6) builder.allowFamily(OsConstants.AF_INET6)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) builder.setMetered(false)
        val pfd = builder.establish() ?: throw IllegalStateException("VPN не поднят: нет разрешения")
        tun?.close()
        tun = pfd
        CepEngine.log("VPN: ${s.address}/${s.prefix}, MTU ${s.mtu}, DNS ${s.dns.joinToString(", ")}, маршрутов ${s.routes.size}")
        return pfd.fd
    }

    override fun interfaces(): String {
        val result = mutableListOf<NetInterface>()
        for (network in connectivity.allNetworks) {
            val caps = connectivity.getNetworkCapabilities(network) ?: continue
            if (caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN)) continue
            val lp = connectivity.getLinkProperties(network) ?: continue
            val name = lp.interfaceName ?: continue
            val ni = runCatching { NetworkInterface.getByName(name) }.getOrNull() ?: continue
            result += NetInterface(
                name = name,
                index = ni.index,
                mtu = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q && lp.mtu > 0) lp.mtu else ni.mtu,
                addresses = lp.linkAddresses.map { "${it.address.hostAddress?.substringBefore('%')}/${it.prefixLength}" },
                dns = lp.dnsServers.mapNotNull { it.hostAddress },
                type = when {
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> "wifi"
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> "cellular"
                    caps.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> "ethernet"
                    else -> "other"
                },
                metered = !caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_NOT_METERED),
                default = network == defaultNetwork,
            )
        }
        return CepLogic.json.encodeToString(kotlinx.serialization.builtins.ListSerializer(NetInterface.serializer()), result)
    }

    override fun log(message: String) = CepEngine.log(message)

    // Основная сеть устройства (не VPN): о её смене (Wi-Fi ↔ мобильная) узнаёт sing-box.

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            defaultNetwork = network
            report(connectivity.getLinkProperties(network))
        }

        override fun onLinkPropertiesChanged(network: Network, lp: LinkProperties) {
            if (network == defaultNetwork) report(lp)
        }

        override fun onLost(network: Network) {
            if (network != defaultNetwork) return
            defaultNetwork = null
            engine?.updateDefaultInterface("", -1)
        }
    }

    private fun report(lp: LinkProperties?) {
        val name = lp?.interfaceName ?: return
        val index = runCatching { NetworkInterface.getByName(name)?.index }.getOrNull() ?: return
        engine?.updateDefaultInterface(name, index)
    }

    private fun watchDefaultNetwork() {
        defaultNetwork = connectivity.allNetworks.firstOrNull { n ->
            val caps = connectivity.getNetworkCapabilities(n)
            caps != null && !caps.hasTransport(NetworkCapabilities.TRANSPORT_VPN) &&
                caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
                connectivity.getLinkProperties(n)?.linkAddresses?.any { it.address is Inet4Address } == true
        }
        val request = NetworkRequest.Builder()
            .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
            .addCapability(NetworkCapabilities.NET_CAPABILITY_NOT_VPN)
            .build()
        runCatching {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                connectivity.registerBestMatchingNetworkCallback(request, networkCallback, Handler(Looper.getMainLooper()))
            } else {
                connectivity.requestNetwork(request, networkCallback)
            }
        }
    }

    // Уведомление с кнопкой «Отключить»

    private fun startForeground(chainName: String) {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "CEP: цепочка VPN", NotificationManager.IMPORTANCE_LOW))
        val open = PendingIntent.getActivity(this, 0, Intent(this, CepActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val stop = PendingIntent.getService(
            this, 1, Intent(this, CepVpnService::class.java).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = Notification.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_cep_notification)
            .setContentTitle("CEP: $chainName")
            .setContentText("Цепочка VPN")
            .setContentIntent(open)
            .addAction(Notification.Action.Builder(null, "Отключить", stop).build())
            .setOngoing(true)
            .build()
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    companion object {
        const val ACTION_START = "com.example.contactsync.cep.START"
        const val ACTION_STOP = "com.example.contactsync.cep.STOP"
        const val EXTRA_CHAIN_ID = "chainId"
        private const val CHANNEL = "cep_vpn"
        private const val NOTIFICATION_ID = 4710
    }
}
