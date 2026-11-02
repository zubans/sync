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
import com.example.argontunnel.crypto.ReplayWindow
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
    /** Сетевой интерфейс, через который выходят UDP-пакеты. */
    val boundInterface: String = "авто",
    /** Число приложений, трафик которых направлен в туннель (-1 = все). */
    val routedAppsCount: Int = 0,
    /** Отправлено пакетов. */
    val txPackets: Long = 0,
    /** Принято пакетов. */
    val rxPackets: Long = 0,
    /** Отправлено байт шифротекста. */
    val txBytes: Long = 0,
    /** Принято байт шифротекста. */
    val rxBytes: Long = 0,
    /** Последнее сообщение об ошибке. */
    val lastError: String? = null,
)

/**
 * Android VPN-сервис туннеля Argon4.
 *
 * Перехватывает IP-пакеты выбранных приложений (по умолчанию Contact Sync),
 * шифрует их надстройкой Argon4 (Argon2id + AES-256-GCM + PSK) и отправляет по UDP на Ubuntu-сервер.
 */
class ArgonVpnService : VpnService() {

    private val serviceScope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
    private var tunnelJob: Job? = null
    private var udpSocket: DatagramSocket? = null
    private var tunPfd: ParcelFileDescriptor? = null

    private val json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> startTunnel()
            ACTION_STOP -> stopTunnel()
            ACTION_SEND_TEST_PAYLOAD -> {
                val material = intent.getStringExtra("material").orEmpty()
                serviceScope.launch { sendTestMaterial(material) }
            }
        }
        return START_NOT_STICKY
    }

    override fun onDestroy() {
        super.onDestroy()
        stopTunnel()
        serviceScope.cancel()
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
                val psk = Argon4Crypto.derivePSK(password)
                val key = Argon4Crypto.deriveKey(password, salt)
                val socket = createBoundSocket(settings.bindInterface)
                udpSocket = socket

                val serverAddr = InetSocketAddress(InetAddress.getByName(settings.serverHost), settings.serverPort)
                val appsList = if (settings.routingMode == RoutingMode.SELECTED_APPS) {
                    settings.selectedPackages.toList()
                } else {
                    emptyList()
                }

                val (handshake, sessionKeys) = performHandshake(
                    socket = socket,
                    serverAddr = serverAddr,
                    salt = salt,
                    psk = psk,
                    key = key,
                    clientId = settings.clientId,
                    clientSecret = settings.clientSecret,
                    apps = appsList,
                )

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

                runPacketLoops(
                    pfd, socket, serverAddr, salt, psk,
                    sessionKeys.c2sKey, sessionKeys.s2cKey,
                )
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
            val psk = Argon4Crypto.derivePSK(password)
            val key = Argon4Crypto.deriveKey(password, salt)
            val socket = createBoundSocket(settings.bindInterface)
            socket.use { sock ->
                val serverAddr = InetSocketAddress(InetAddress.getByName(settings.serverHost), settings.serverPort)
                val packetBytes = Argon4Crypto.sealPacketWithSeq(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    salt = salt,
                    psk = psk,
                    key = key,
                    seq = 1L,
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
        psk: ByteArray,
        key: SecretKey,
        clientId: String,
        clientSecret: String,
        apps: List<String>,
    ): Pair<HandshakeResponse, Argon4Crypto.SessionKeys> {
        val keyPair = Argon4Crypto.generateX25519Keypair()
        try {
            val clientAuth = Argon4Crypto.clientAuthToken(psk, clientId, clientSecret)
            val req = HandshakeRequest(
                clientId = clientId,
                clientAuth = clientAuth,
                clientPub = Argon4Crypto.bytesToHex(keyPair.publicKey),
                apps = apps,
                timestamp = System.currentTimeMillis() / 1000,
            )
            val reqJson = json.encodeToString(req).toByteArray(Charsets.UTF_8)
            val wireReq = Argon4Crypto.sealPacketWithSeq(
                frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                salt = salt,
                psk = psk,
                key = key,
                seq = 1L,
                plaintext = reqJson,
            )

            val recvBuf = ByteArray(4096)
            var lastErr: Throwable? = null
            repeat(3) {
                try {
                    socket.send(DatagramPacket(wireReq, wireReq.size, serverAddr))
                    val dp = DatagramPacket(recvBuf, recvBuf.size)
                    socket.receive(dp)
                    val opened = Argon4Crypto.openPacket(dp.data, dp.length, psk, key)
                    if (opened.frameType == Argon4Crypto.FRAME_HANDSHAKE_RESP) {
                        val response = json.decodeFromString<HandshakeResponse>(opened.plaintext.decodeToString())
                        val sharedSecret = Argon4Crypto.computeX25519Shared(
                            keyPair.privateKey,
                            Argon4Crypto.hexToBytes(response.serverPub),
                        )
                        try {
                            return response to Argon4Crypto.deriveSessionKeys(key, sharedSecret, salt)
                        } finally {
                            sharedSecret.fill(0)
                        }
                    }
                } catch (t: Throwable) {
                    lastErr = t
                }
            }

            throw IllegalStateException(
                "Сервер не ответил на рукопожатие туннеля (проверьте мастер-пароль, адрес и порт): ${lastErr?.message ?: "таймаут"}",
                lastErr
            )
        } finally {
            keyPair.privateKey.fill(0)
        }
    }

    private fun establishTun(settings: TunnelSettings, hs: HandshakeResponse): ParcelFileDescriptor {
        val builder = Builder()
            .setSession(settings.tunInterfaceName.ifBlank { "argon0" })
            .setMtu(hs.mtu.coerceIn(1280, 1500))
            .addAddress(hs.assignedIp, hs.subnetCidr)

        // DNS-серверы из настроек сервера или публичные 1.1.1.1
        val dnsList = hs.dns.ifEmpty { listOf("1.1.1.1", "8.8.8.8") }
        dnsList.forEach { dns ->
            runCatching { builder.addDnsServer(dns) }
        }

        // Заворачиваемые маршруты
        val routesList = hs.routes.ifEmpty { listOf("0.0.0.0/0") }
        routesList.forEach { route ->
            val parts = route.split("/")
            if (parts.isNotEmpty()) {
                val ip = parts[0]
                val prefix = parts.getOrNull(1)?.toIntOrNull() ?: 32
                runCatching { builder.addRoute(ip, prefix) }
            }
        }

        // Выборочная маршрутизация пакетов (Per-App VPN)
        if (settings.routingMode == RoutingMode.SELECTED_APPS) {
            val selected = settings.selectedPackages
            selected.forEach { pkg ->
                runCatching { builder.addAllowedApplication(pkg) }
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
        psk: ByteArray,
        c2sKey: SecretKey,
        s2cKey: SecretKey,
    ) {
        socket.soTimeout = 0
        val seqCounter = AtomicLong(1L)
        val txPkts = AtomicLong(0)
        val rxPkts = AtomicLong(0)
        val txBytes = AtomicLong(0)
        val rxBytes = AtomicLong(0)
        val replayWindow = ReplayWindow()

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
                        psk = psk,
                        key = c2sKey,
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

        // 2. Приём зашифрованных пакетов от Ubuntu-сервера -> проверка replay -> расшифровка -> запись в TUN
        val udpToTun = scope.launch {
            FileOutputStream(pfd.fileDescriptor).use { tunOut ->
                val buf = ByteArray(65535)
                while (isActive) {
                    val dp = DatagramPacket(buf, buf.size)
                    socket.receive(dp)
                    val opened = runCatching {
                        Argon4Crypto.openPacket(dp.data, dp.length, psk, s2cKey)
                    }.getOrNull() ?: continue

                    if (!replayWindow.accept(opened.seq)) {
                        continue
                    }

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
                    val seq = seqCounter.getAndIncrement()
                    val ka = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_KEEPALIVE,
                        salt = salt,
                        psk = psk,
                        key = c2sKey,
                        seq = seq,
                        plaintext = "ping".toByteArray(Charsets.UTF_8),
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
