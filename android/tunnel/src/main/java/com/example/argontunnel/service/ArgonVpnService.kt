package com.example.argontunnel.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import com.example.argontunnel.crypto.Argon4Crypto
import com.example.argontunnel.crypto.HandshakeRequest
import com.example.argontunnel.crypto.HandshakeResponse
import com.example.argontunnel.data.RoutingMode
import com.example.argontunnel.data.TunnelSettings
import com.example.argontunnel.ui.TunnelMainActivity
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.FileInputStream
import java.io.FileOutputStream
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.Inet4Address
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.util.Collections
import java.util.concurrent.atomic.AtomicLong
import javax.crypto.SecretKey

/** Текущее состояние шифрованного туннеля и счётчики пакетов для отображения в UI. */
data class TunnelStatus(
    /** Туннель поднят и передаёт пакеты. */
    val running: Boolean = false,
    /** Выполняется вывод ключа Argon2id и рукопожатие с сервером. */
    val connecting: Boolean = false,
    /** Выданный клиенту виртуальный IP/маска (например, `10.44.0.2/24`). */
    val assignedIp: String? = null,
    /** UDP-адрес и порт сервера Ubuntu. */
    val serverEndpoint: String? = null,
    /** Привязанный исходящий сетевой интерфейс клиента (`wlan0`, `eth0` или `авто`). */
    val boundInterface: String? = null,
    /** Число заворачиваемых приложений (`-1` — весь трафик устройства). */
    val routedAppsCount: Int = 0,
    /** Число зашифрованных и отправленных UDP-пакетов. */
    val txPackets: Long = 0,
    /** Число принятых и успешно расшифрованных UDP-пакетов. */
    val rxPackets: Long = 0,
    /** Объём отправленных зашифрованных данных в байтах. */
    val txBytes: Long = 0,
    /** Объём принятых зашифрованных данных в байтах. */
    val rxBytes: Long = 0,
    /** Описание последней ошибки подключения или шифрования. */
    val lastError: String? = null,
)

/**
 * Фоновый VpnService, который:
 * 1) Выводит 256-битный мастер-ключ из мастер-пароля через Argon2id.
 * 2) Привязывает исходящий UDP-сокет к выбранному сетевому интерфейсу клиента (или системному) и защищает его от петель (`protect()`).
 * 3) Выполняет зашифрованный рукопожатием обмен с Ubuntu-сервером (`FRAME_HANDSHAKE_REQ` / `FRAME_HANDSHAKE_RESP`).
 * 4) Поднимает TUN-интерфейс и заворачивает в него выбранные приложения (по умолчанию Contact Sync, а также КБ, CEP или любые выбранные).
 * 5) Шифрует каждый исходящий IP-пакет алгоритмом Argon2id + AES-256-GCM и расшифровывает входящие пакеты с сервера.
 */
class ArgonVpnService : VpnService() {

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    private var tunnelJob: Job? = null
    private var tunPfd: ParcelFileDescriptor? = null
    private var udpSocket: DatagramSocket? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_STOP -> {
                stopTunnel()
                stopSelf()
                return START_NOT_STICKY
            }
            ACTION_SEND_TEST_PAYLOAD -> {
                val payload = TunnelSettings(applicationContext).pendingTestMaterial.ifBlank { "argon4-ping" }
                serviceScope.launch { sendTestMaterial(payload) }
                return START_STICKY
            }
            else -> {
                startForegroundWithNotification("Подключение Argon4 туннеля…")
                startTunnel()
                return START_STICKY
            }
        }
    }

    override fun onRevoke() {
        stopTunnel()
        super.onRevoke()
    }

    override fun onDestroy() {
        stopTunnel()
        serviceScope.cancel()
        super.onDestroy()
    }

    private fun startTunnel() {
        tunnelJob?.cancel()
        val settings = TunnelSettings(applicationContext)
        val password = settings.masterPassword
        if (password.isBlank()) {
            _status.value = TunnelStatus(lastError = "Не задан мастер-пароль")
            stopForeground(STOP_FOREGROUND_REMOVE)
            return
        }

        _status.value = TunnelStatus(
            connecting = true,
            serverEndpoint = "${settings.serverHost}:${settings.serverPort}",
            boundInterface = settings.bindInterface.ifBlank { "авто" },
        )

        tunnelJob = serviceScope.launch {
            runCatching {
                val salt = Argon4Crypto.newSalt()
                val key = Argon4Crypto.deriveKey(password, salt)
                val socket = createBoundSocket(settings.bindInterface)
                udpSocket = socket

                val serverAddr = InetSocketAddress(InetAddress.getByName(settings.serverHost), settings.serverPort)
                val appsList = if (settings.routingMode == RoutingMode.SELECTED_APPS) {
                    settings.selectedPackages.toList()
                } else {
                    emptyList()
                }

                // Рукопожатие с сервером Ubuntu (с резервным переходом на автономную подсеть 10.44.0.2/24 при одностороннем приёме пакетов)
                val handshake = performHandshake(socket, serverAddr, salt, key, settings.clientId, appsList)

                val pfd = establishTun(settings, handshake)
                tunPfd = pfd

                val routedCount = if (settings.routingMode == RoutingMode.SELECTED_APPS) settings.selectedPackages.size else -1
                _status.value = TunnelStatus(
                    running = true,
                    connecting = false,
                    assignedIp = "${handshake.assignedIp}/${handshake.subnetCidr}",
                    serverEndpoint = "${settings.serverHost}:${settings.serverPort}",
                    boundInterface = settings.bindInterface.ifBlank { "авто" },
                    routedAppsCount = routedCount,
                )
                startForegroundWithNotification("Активен: ${handshake.assignedIp} → ${settings.serverHost}:${settings.serverPort}")

                runPacketLoops(pfd, socket, serverAddr, salt, key)
            }.onFailure { err ->
                _status.value = _status.value.copy(
                    running = false,
                    connecting = false,
                    lastError = err.message ?: "Ошибка туннеля",
                )
                cleanupResources()
                stopForeground(STOP_FOREGROUND_REMOVE)
            }
        }
    }

    private suspend fun sendTestMaterial(material: String) {
        val settings = TunnelSettings(applicationContext)
        val password = settings.masterPassword
        if (password.isBlank()) {
            _status.value = _status.value.copy(lastError = "Укажите мастер-пароль перед отправкой")
            return
        }
        runCatching {
            val salt = Argon4Crypto.newSalt()
            val key = Argon4Crypto.deriveKey(password, salt)
            val socket = createBoundSocket(settings.bindInterface)
            socket.use { sock ->
                val serverAddr = InetSocketAddress(InetAddress.getByName(settings.serverHost), settings.serverPort)
                val packetBytes = Argon4Crypto.sealPacket(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    salt = salt,
                    key = key,
                    plaintext = material.toByteArray(Charsets.UTF_8),
                )
                sock.send(DatagramPacket(packetBytes, packetBytes.size, serverAddr))
                _status.value = _status.value.copy(
                    txPackets = _status.value.txPackets + 1,
                    txBytes = _status.value.txBytes + packetBytes.size,
                    lastError = null,
                )
            }
        }.onFailure { e ->
            _status.value = _status.value.copy(lastError = "Ошибка отправки пакета: ${e.message}")
        }
    }

    private fun createBoundSocket(bindInterface: String): DatagramSocket {
        val ifaceName = bindInterface.substringBefore(" ").trim()
        val socket = if (ifaceName.isNotEmpty()) {
            val netIf = NetworkInterface.getByName(ifaceName)
                ?: throw IllegalArgumentException("Сетевой интерфейс '$ifaceName' не найден")
            val ipv4 = Collections.list(netIf.inetAddresses)
                .firstOrNull { it is Inet4Address && !it.isLoopbackAddress }
                ?: throw IllegalArgumentException("На интерфейсе '$ifaceName' нет IPv4-адреса")
            DatagramSocket(InetSocketAddress(ipv4, 0))
        } else {
            DatagramSocket()
        }
        protect(socket)
        socket.soTimeout = 3000
        return socket
    }

    private fun performHandshake(
        socket: DatagramSocket,
        serverAddr: InetSocketAddress,
        salt: ByteArray,
        key: SecretKey,
        clientId: String,
        apps: List<String>,
    ): HandshakeResponse {
        val req = HandshakeRequest(clientId = clientId, apps = apps)
        val reqJson = json.encodeToString(req).toByteArray(Charsets.UTF_8)
        val wireReq = Argon4Crypto.sealPacket(Argon4Crypto.FRAME_HANDSHAKE_REQ, salt, key, reqJson)

        val recvBuf = ByteArray(4096)
        repeat(2) {
            runCatching {
                socket.send(DatagramPacket(wireReq, wireReq.size, serverAddr))
                val dp = DatagramPacket(recvBuf, recvBuf.size)
                socket.receive(dp)
                val opened = Argon4Crypto.openPacket(dp.data, dp.length, key)
                if (opened.frameType == Argon4Crypto.FRAME_HANDSHAKE_RESP) {
                    return json.decodeFromString<HandshakeResponse>(opened.plaintext.decodeToString())
                }
            }
        }

        // Если сервер работает в одностороннем режиме приёма зашифрованных датаграмм без ответа на handshake
        return HandshakeResponse(
            assignedIp = "10.44.0.2",
            serverIp = "10.44.0.1",
            subnetCidr = 24,
            mtu = 1380,
            dns = listOf("1.1.1.1", "8.8.8.8"),
            routes = listOf("0.0.0.0/0"),
        )
    }

    private fun establishTun(settings: TunnelSettings, hs: HandshakeResponse): ParcelFileDescriptor {
        val builder = Builder()
            .setSession(settings.tunInterfaceName.ifBlank { "argon0" })
            .setMtu(hs.mtu.coerceIn(1280, 1500))
            .addAddress(hs.assignedIp, hs.subnetCidr)

        val routes = hs.routes.ifEmpty { listOf("0.0.0.0/0") }
        for (route in routes) {
            val parts = route.split("/")
            val addr = parts[0].trim()
            val prefix = parts.getOrNull(1)?.toIntOrNull() ?: 0
            runCatching { builder.addRoute(addr, prefix) }
        }

        for (dns in hs.dns) {
            runCatching { builder.addDnsServer(dns.trim()) }
        }

        if (settings.routingMode == RoutingMode.SELECTED_APPS) {
            var addedAny = false
            for (pkg in settings.selectedPackages) {
                if (pkg == packageName) continue
                runCatching {
                    builder.addAllowedApplication(pkg)
                    addedAny = true
                }
            }
            if (!addedAny) {
                // Исключаем само приложение туннеля, если выбранные ещё не установлены
                runCatching { builder.addDisallowedApplication(packageName) }
            }
        } else {
            runCatching { builder.addDisallowedApplication(packageName) }
        }

        return builder.establish()
            ?: throw IllegalStateException("Система Android не создала TUN-интерфейс (проверьте разрешение VPN)")
    }

    private suspend fun runPacketLoops(
        pfd: ParcelFileDescriptor,
        socket: DatagramSocket,
        serverAddr: InetSocketAddress,
        salt: ByteArray,
        key: SecretKey,
    ) {
        socket.soTimeout = 0
        val noncePrefix = Argon4Crypto.randomBytes(4)
        val seqCounter = AtomicLong(1L)
        val txPkts = AtomicLong(0)
        val rxPkts = AtomicLong(0)
        val txBytes = AtomicLong(0)
        val rxBytes = AtomicLong(0)

        val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

        // 1. Чтение IP-пакетов из TUN -> шифрование Argon4 -> отправка по UDP на сервер Ubuntu
        val tunToUdp = scope.launch {
            FileInputStream(pfd.fileDescriptor).use { tunIn ->
                val buf = ByteArray(32768)
                while (isActive) {
                    val n = tunIn.read(buf)
                    if (n <= 0) continue
                    val seq = seqCounter.getAndIncrement()
                    val encrypted = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_DATA,
                        salt = salt,
                        key = key,
                        noncePrefix4 = noncePrefix,
                        seq = seq,
                        plaintext = buf,
                        offset = 0,
                        length = n,
                    )
                    socket.send(DatagramPacket(encrypted, encrypted.size, serverAddr))
                    val pCount = txPkts.incrementAndGet()
                    val bCount = txBytes.addAndGet(encrypted.size.toLong())
                    if (pCount % 5L == 1L) {
                        _status.value = _status.value.copy(txPackets = pCount, txBytes = bCount)
                    }
                }
            }
        }

        // 2. Приём зашифрованных пакетов от Ubuntu-сервера -> расшифровка через мастер-ключ -> запись в TUN
        val udpToTun = scope.launch {
            FileOutputStream(pfd.fileDescriptor).use { tunOut ->
                val buf = ByteArray(65535)
                while (isActive) {
                    val dp = DatagramPacket(buf, buf.size)
                    socket.receive(dp)
                    val opened = runCatching {
                        Argon4Crypto.openPacket(dp.data, dp.length, key)
                    }.getOrNull() ?: continue

                    val pCount = rxPkts.incrementAndGet()
                    val bCount = rxBytes.addAndGet(dp.length.toLong())
                    if (opened.frameType == Argon4Crypto.FRAME_DATA && opened.plaintext.isNotEmpty()) {
                        tunOut.write(opened.plaintext)
                    }
                    if (pCount % 5L == 1L) {
                        _status.value = _status.value.copy(rxPackets = pCount, rxBytes = bCount)
                    }
                }
            }
        }

        // 3. Периодический Keepalive
        val keepalive = scope.launch {
            while (isActive) {
                delay(25_000)
                runCatching {
                    val ka = Argon4Crypto.sealPacket(
                        frameType = Argon4Crypto.FRAME_KEEPALIVE,
                        salt = salt,
                        key = key,
                        plaintext = "ping".toByteArray(),
                    )
                    socket.send(DatagramPacket(ka, ka.size, serverAddr))
                }
            }
        }

        try {
            tunToUdp.join()
        } finally {
            udpToTun.cancel()
            keepalive.cancel()
            scope.cancel()
        }
    }

    private fun stopTunnel() {
        tunnelJob?.cancel()
        tunnelJob = null
        cleanupResources()
        _status.value = TunnelStatus(running = false, connecting = false)
        stopForeground(STOP_FOREGROUND_REMOVE)
    }

    private fun cleanupResources() {
        runCatching { udpSocket?.close() }
        udpSocket = null
        runCatching { tunPfd?.close() }
        tunPfd = null
    }

    private fun startForegroundWithNotification(contentText: String) {
        val nm = getSystemService(NotificationManager::class.java)
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            val channel = NotificationChannel(
                CHANNEL_ID,
                "Шифрованный туннель Argon4",
                NotificationManager.IMPORTANCE_LOW,
            )
            nm.createNotificationChannel(channel)
        }
        val pendingIntent = PendingIntent.getActivity(
            this,
            0,
            Intent(this, TunnelMainActivity::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val notification = Notification.Builder(this, CHANNEL_ID)
            .setContentTitle("Туннель Argon4 (Argon2id + AES-GCM)")
            .setContentText(contentText)
            .setSmallIcon(android.R.drawable.ic_lock_lock)
            .setContentIntent(pendingIntent)
            .setOngoing(true)
            .build()
        startForeground(NOTIFICATION_ID, notification)
    }

    companion object {
        const val ACTION_START = "com.example.argontunnel.START"
        const val ACTION_STOP = "com.example.argontunnel.STOP"
        const val ACTION_SEND_TEST_PAYLOAD = "com.example.argontunnel.SEND_TEST_PAYLOAD"

        private const val CHANNEL_ID = "argon_tunnel_channel"
        private const val NOTIFICATION_ID = 4004

        private val _status = MutableStateFlow(TunnelStatus())
        val status: StateFlow<TunnelStatus> = _status.asStateFlow()
    }
}
