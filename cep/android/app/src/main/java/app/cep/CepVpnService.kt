package app.cep

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
import android.os.ParcelFileDescriptor
import android.system.OsConstants
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import java.net.Inet4Address
import java.net.NetworkInterface
import kotlin.concurrent.thread

/**
 * VPN приложения. Вся цепочка — один экземпляр sing-box из движка cepcore:
 * узел N выходит в сеть через узел N-1, весь трафик TUN уходит в последний узел.
 */
class CepVpnService : VpnService(), cepcore.Platform {
    companion object {
        const val ACTION_START = "app.cep.START"
        const val ACTION_STOP = "app.cep.STOP"
        const val EXTRA_PLAN = "plan"
        private const val CHANNEL = "vpn"
        private const val NOTIFICATION_ID = 1
    }

    @Serializable
    private data class TunSettings(
        val address: String,
        val prefix: Int,
        val mtu: Int,
        val dns: List<String>,
        val blockIPv6: Boolean,
    )

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
    private var stopping = false
    private var tun: ParcelFileDescriptor? = null
    private var defaultNetwork: Network? = null
    private val connectivity by lazy { getSystemService(ConnectivityManager::class.java) }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> {
                val plan = intent.getStringExtra(EXTRA_PLAN) ?: return START_NOT_STICKY
                start(json.decodeFromString<Plan>(plan), plan)
            }
            ACTION_STOP -> stop()
        }
        return START_NOT_STICKY
    }

    override fun onRevoke() = stop()

    override fun onDestroy() {
        shutdown()
        super.onDestroy()
    }

    private fun start(plan: Plan, planJSON: String) {
        startForeground(plan.chainName)
        Engine.set { EngineState(Phase.CONNECTING, plan.chainName, plan.hops) }
        Engine.log("подключение «${plan.chainName}»: ${plan.hops.joinToString(" → ") { it.name }}")
        watchDefaultNetwork()
        stopping = false
        thread(name = "cep-start") {
            try {
                val started = cepcore.Cepcore.start(planJSON, filesDir.absolutePath, this)
                synchronized(this) {
                    // «Отключить» нажали, пока цепочка поднималась
                    if (stopping) {
                        started.close()
                        return@thread
                    }
                    engine = started
                }
                Engine.set { it.copy(phase = Phase.CONNECTED, error = null) }
                Engine.log("цепочка запущена")
            } catch (e: Exception) {
                Engine.log("ошибка: ${e.message}")
                shutdown()
                Engine.set { it.copy(phase = Phase.FAILED, error = e.message) }
                stopSelf()
            }
        }
    }

    private fun stop() {
        synchronized(this) { stopping = true }
        Engine.set { it.copy(phase = Phase.STOPPING) }
        thread(name = "cep-stop") {
            shutdown()
            Engine.set { it.copy(phase = Phase.STOPPED) }
            Engine.log("остановлено")
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
        if (prepare(this) != null) throw IllegalStateException("нет разрешения на VPN — откройте приложение")
        val s = json.decodeFromString<TunSettings>(settingsJSON)
        val builder = Builder()
            .setSession(Engine.state.value.chainName)
            .setMtu(s.mtu)
            .addAddress(s.address, s.prefix)
            .addRoute("0.0.0.0", 0)
            .setConfigureIntent(
                PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE),
            )
        s.dns.forEach { builder.addDnsServer(it) }
        // без адреса и маршрутов IPv6 Android блокирует IPv6 целиком — это и есть «блокировать IPv6»
        if (!s.blockIPv6) builder.allowFamily(OsConstants.AF_INET6)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) builder.setMetered(false)
        val pfd = builder.establish() ?: throw IllegalStateException("VPN не поднят: нет разрешения")
        tun?.close()
        tun = pfd
        Engine.log("VPN: ${s.address}/${s.prefix}, MTU ${s.mtu}, DNS ${s.dns.joinToString(", ")}")
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
        return json.encodeToString(result)
    }

    override fun log(message: String) = Engine.log(message)

    // Основная сеть устройства (не VPN): о её смене узнаёт sing-box

    private val networkCallback = object : ConnectivityManager.NetworkCallback() {
        override fun onAvailable(network: Network) {
            defaultNetwork = network
            report(network, connectivity.getLinkProperties(network))
        }

        override fun onLinkPropertiesChanged(network: Network, lp: LinkProperties) {
            if (network == defaultNetwork) report(network, lp)
        }

        override fun onLost(network: Network) {
            if (network != defaultNetwork) return
            defaultNetwork = null
            engine?.updateDefaultInterface("", -1)
        }
    }

    private fun report(network: Network, lp: LinkProperties?) {
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
                connectivity.registerBestMatchingNetworkCallback(request, networkCallback, android.os.Handler(android.os.Looper.getMainLooper()))
            } else {
                connectivity.requestNetwork(request, networkCallback)
            }
        }
    }

    // Уведомление

    private fun startForeground(chainName: String) {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL, "VPN", NotificationManager.IMPORTANCE_LOW))
        val open = PendingIntent.getActivity(
            this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE,
        )
        val stop = PendingIntent.getService(
            this, 1, Intent(this, CepVpnService::class.java).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE,
        )
        val notification = Notification.Builder(this, CHANNEL)
            .setSmallIcon(R.drawable.ic_vpn)
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
}
