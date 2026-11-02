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
import java.net.Inet4Address
import java.net.NetworkInterface
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
    private var stopping = false
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
        val planJson = CepLogic.json.encodeToString(CepPlan.serializer(), plan)
        thread(name = "cep-start") {
            try {
                val started = cepcore.Cepcore.start(planJson, filesDir.absolutePath, this)
                synchronized(this) {
                    // «Отключить» нажали, пока цепочка поднималась
                    if (stopping) {
                        started.close()
                        return@thread
                    }
                    engine = started
                }
                CepEngine.set { it.copy(phase = CepPhase.CONNECTED, error = null) }
                CepEngine.log("цепочка запущена")
            } catch (e: Exception) {
                CepEngine.log("ошибка: ${e.message}")
                shutdown()
                CepEngine.set { it.copy(phase = CepPhase.FAILED, error = e.message) }
                stopSelf()
            }
        }
    }

    private fun stop() {
        synchronized(this) { stopping = true }
        CepEngine.set { it.copy(phase = CepPhase.STOPPING) }
        thread(name = "cep-stop") {
            shutdown()
            CepEngine.set { it.copy(phase = CepPhase.STOPPED) }
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
        if (prepare(this) != null) throw IllegalStateException("нет разрешения на VPN — откройте CEP")
        val s = CepLogic.json.decodeFromString(TunSettings.serializer(), settingsJSON)
        val builder = Builder()
            .setSession(CepEngine.state.value.chainName)
            .setMtu(s.mtu)
            .addAddress(s.address, s.prefix)
            .addRoute("0.0.0.0", 0)
            .setConfigureIntent(
                PendingIntent.getActivity(this, 0, Intent(this, CepActivity::class.java), PendingIntent.FLAG_IMMUTABLE),
            )
        s.dns.forEach { builder.addDnsServer(it) }
        // Без адреса и маршрутов IPv6 Android блокирует IPv6 целиком — это и есть «блокировать IPv6».
        if (!s.blockIPv6) builder.allowFamily(OsConstants.AF_INET6)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) builder.setMetered(false)
        val pfd = builder.establish() ?: throw IllegalStateException("VPN не поднят: нет разрешения")
        tun?.close()
        tun = pfd
        CepEngine.log("VPN: ${s.address}/${s.prefix}, MTU ${s.mtu}, DNS ${s.dns.joinToString(", ")}")
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
