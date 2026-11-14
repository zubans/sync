package com.example.argontunnel.service

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.net.VpnService
import android.os.Build
import android.os.ParcelFileDescriptor
import com.example.argontunnel.crypto.ActivityTracker
import com.example.argontunnel.crypto.Argon4Crypto
import com.example.argontunnel.crypto.Argon4ErrorKind
import com.example.argontunnel.crypto.Argon4ProtocolException
import com.example.argontunnel.crypto.Argon4Timing
import com.example.argontunnel.crypto.ArgonTimingConfig
import com.example.argontunnel.crypto.HandshakeRequest
import com.example.argontunnel.crypto.HandshakeResponse
import com.example.argontunnel.crypto.ReplayWindow
import com.example.argontunnel.crypto.SystemArgonClock
import com.example.argontunnel.data.RoutingMode
import com.example.argontunnel.data.TunnelSettings
import com.example.argontunnel.ui.TunnelMainActivity
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
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

                val transport = resolveTransportConfig(settings)
                val (handshake, sessionKeys) = performHandshake(
                    socket = socket,
                    serverAddr = serverAddr,
                    salt = salt,
                    psk = psk,
                    key = key,
                    clientId = settings.clientId,
                    clientSecret = settings.clientSecret,
                    apps = appsList,
                    transport = transport,
                )

                val pfd = establishTun(settings, handshake, transport)
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
                    transport,
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

    private data class ResolvedTransportConfig(
        val version: Byte,
        val padding: com.example.argontunnel.crypto.PaddingConfig,
        val firstPacketPadding: com.example.argontunnel.crypto.PaddingConfig,
        val maxOuterBytes: Int,
        val maxPayloadBytes: Int,
        val timing: ArgonTimingConfig,
    )

    private fun resolveTransportConfig(settings: TunnelSettings): ResolvedTransportConfig {
        val version = Argon4Crypto.resolveVersion(settings.protocolVersion)
        val padding = Argon4Crypto.normalizePaddingConfig(
            Argon4Crypto.presetPaddingConfig(settings.paddingProfile, settings.pathMtu),
            isUdp = true,
        )
        val firstPadding = if (settings.firstPacketProfile.isNotBlank()) {
            Argon4Crypto.normalizePaddingConfig(
                Argon4Crypto.presetPaddingConfig(settings.firstPacketProfile, padding.pathMtu),
                isUdp = true,
            )
        } else {
            padding
        }
        val maxOuterBytes = Argon4Crypto.maxOuterBytesForPathMtu(padding.pathMtu)
        val overhead = if (version == Argon4Crypto.PROTOCOL_VERSION_V4) {
            Argon4Crypto.OVERHEAD_V4
        } else {
            Argon4Crypto.OVERHEAD_V5
        }
        return ResolvedTransportConfig(
            version = version,
            padding = padding,
            firstPacketPadding = firstPadding,
            maxOuterBytes = maxOuterBytes,
            maxPayloadBytes = (maxOuterBytes - overhead).coerceAtLeast(0),
            timing = settings.timingConfig(),
        )
    }

    private suspend fun sendTestMaterial(material: String) {
        val settings = TunnelSettings(applicationContext)
        val password = settings.masterPassword
        if (password.isBlank()) {
            _status.value = _status.value.copy(lastError = "Укажите мастер-пароль перед отправкой")
            return
        }
        runCatching {
            val transport = resolveTransportConfig(settings)
            val salt = Argon4Crypto.newSalt()
            val psk = Argon4Crypto.derivePSK(password)
            val key = Argon4Crypto.deriveKey(password, salt)
            val socket = createBoundSocket(settings.bindInterface)
            socket.use { sock ->
                val serverAddr = InetSocketAddress(InetAddress.getByName(settings.serverHost), settings.serverPort)
                val rawMaterial = material.toByteArray(Charsets.UTF_8)
                val packetBytes = Argon4Crypto.sealPacketWithOptions(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    salt = salt,
                    psk = psk,
                    key = key,
                    seq = 1L,
                    plaintext = rawMaterial,
                    options = com.example.argontunnel.crypto.SealOptions(
                        version = transport.version,
                        padding = transport.firstPacketPadding,
                        maxOuterBytes = transport.maxOuterBytes,
                    ),
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

    private suspend fun performHandshake(
        socket: DatagramSocket,
        serverAddr: InetSocketAddress,
        salt: ByteArray,
        psk: ByteArray,
        key: SecretKey,
        clientId: String,
        clientSecret: String,
        apps: List<String>,
        transport: ResolvedTransportConfig,
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
                protocolVersion = transport.version.toInt(),
                capabilities = Argon4Crypto.capabilitiesForVersion(transport.version),
                paddingProfile = transport.padding.profile,
            )
            val reqJson = json.encodeToString(req).toByteArray(Charsets.UTF_8)
            val wireReq = Argon4Crypto.sealPacketWithOptions(
                frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                salt = salt,
                psk = psk,
                key = key,
                seq = 1L,
                plaintext = reqJson,
                options = com.example.argontunnel.crypto.SealOptions(
                    version = transport.version,
                    padding = transport.firstPacketPadding,
                    maxOuterBytes = transport.maxOuterBytes,
                ),
            )

            val recvBuf = ByteArray(4096)
            return Argon4Timing.runHandshakeWithBackoff(
                config = transport.timing,
                clock = SystemArgonClock,
                wireRequest = wireReq,
                setReadTimeoutMs = { timeoutMs -> socket.soTimeout = timeoutMs },
                sendPacket = { packet -> socket.send(DatagramPacket(packet, packet.size, serverAddr)) },
                receivePacket = {
                    val dp = DatagramPacket(recvBuf, recvBuf.size)
                    socket.receive(dp)
                    recvBuf.copyOf(dp.length)
                },
                parseResponse = { packet ->
                    val header = runCatching {
                        Argon4Crypto.parseAndVerifyHeader(packet, packet.size, psk)
                    }.getOrNull() ?: return@runHandshakeWithBackoff null
                    if (!header.salt.contentEquals(salt) || header.frameType != Argon4Crypto.FRAME_HANDSHAKE_RESP) {
                        return@runHandshakeWithBackoff null
                    }
                    val opened = try {
                        Argon4Crypto.openPacketWithOptions(
                            packet = packet,
                            length = packet.size,
                            psk = psk,
                            key = key,
                            options = com.example.argontunnel.crypto.OpenOptions(
                                expectedVersion = transport.version,
                                allowV4 = false,
                                maxOuterBytes = transport.maxOuterBytes,
                            ),
                        )
                    } catch (ex: Argon4ProtocolException) {
                        if (Argon4Timing.isNonRetryableHandshakeError(ex)) {
                            throw ex
                        }
                        return@runHandshakeWithBackoff null
                    }
                    val response = json.decodeFromString<HandshakeResponse>(opened.plaintext.decodeToString())
                    Argon4Crypto.validateHandshakeProtocol(transport.version, response)
                    val sharedSecret = Argon4Crypto.computeX25519Shared(
                        keyPair.privateKey,
                        Argon4Crypto.hexToBytes(response.serverPub),
                    )
                    try {
                        response to Argon4Crypto.deriveSessionKeys(key, sharedSecret, salt)
                    } finally {
                        sharedSecret.fill(0)
                    }
                },
            )
        } finally {
            keyPair.privateKey.fill(0)
        }
    }

    private fun establishTun(
        settings: TunnelSettings,
        hs: HandshakeResponse,
        transport: ResolvedTransportConfig,
    ): ParcelFileDescriptor {
        val effectiveMtu = Argon4Crypto.effectiveTunMtu(hs.mtu, transport.padding.pathMtu, transport.version)
        val builder = Builder()
            .setSession(settings.tunInterfaceName.ifBlank { "argon0" })
            .setMtu(effectiveMtu.coerceIn(Argon4Crypto.MIN_PATH_MTU, 1500))
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
        transport: ResolvedTransportConfig,
    ) {
        socket.soTimeout = 0
        val seqCounter = AtomicLong(1L)
        val txPkts = AtomicLong(0)
        val rxPkts = AtomicLong(0)
        val txBytes = AtomicLong(0)
        val rxBytes = AtomicLong(0)
        val replayWindow = ReplayWindow()
        val clock = SystemArgonClock
        val tracker = ActivityTracker(clock)

        val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)
        val dataSealOptions = com.example.argontunnel.crypto.SealOptions(
            version = transport.version,
            padding = transport.padding,
            maxOuterBytes = transport.maxOuterBytes,
        )
        val dataOpenOptions = com.example.argontunnel.crypto.OpenOptions(
            expectedVersion = transport.version,
            allowV4 = false,
            maxOuterBytes = transport.maxOuterBytes,
            maxPayloadBytes = transport.maxPayloadBytes,
        )

        // 1. Чтение IP-пакетов из TUN -> шифрование Argon4 -> отправка по UDP на сервер Ubuntu
        val tunToUdp = scope.launch {
            try {
                FileInputStream(pfd.fileDescriptor).use { tunIn ->
                    val buf = ByteArray(32768)
                    while (isActive) {
                        val n = tunIn.read(buf)
                        if (n <= 0) continue
                        val seq = seqCounter.getAndIncrement()
                        val encrypted = Argon4Crypto.sealPacketWithOptions(
                            frameType = Argon4Crypto.FRAME_DATA,
                            salt = salt,
                            psk = psk,
                            key = c2sKey,
                            seq = seq,
                            plaintext = buf,
                            offset = 0,
                            length = n,
                            options = dataSealOptions,
                        )
                        socket.send(DatagramPacket(encrypted, encrypted.size, serverAddr))
                        tracker.touch()
                        val pCount = txPkts.incrementAndGet()
                        val bCount = txBytes.addAndGet(encrypted.size.toLong())
                        if (pCount % 5L == 1L) {
                            _status.value = _status.value.copy(txPackets = pCount, txBytes = bCount)
                        }
                    }
                }
            } catch (ce: CancellationException) {
                throw ce
            } catch (err: Throwable) {
                if (isActive) {
                    _status.value = _status.value.copy(lastError = "Ошибка отправки в туннель: ${err.message}")
                }
            }
        }

        // 2. Приём зашифрованных пакетов от Ubuntu-сервера -> проверка replay -> расшифровка -> запись в TUN
        val udpToTun = scope.launch {
            try {
                FileOutputStream(pfd.fileDescriptor).use { tunOut ->
                    val buf = ByteArray(65535)
                    while (isActive) {
                        val dp = DatagramPacket(buf, buf.size)
                        socket.receive(dp)
                        val header = runCatching {
                            Argon4Crypto.parseAndVerifyHeader(dp.data, dp.length, psk)
                        }.getOrNull() ?: continue
                        if (header.version != transport.version || !header.salt.contentEquals(salt)) {
                            continue
                        }
                        val opened = runCatching {
                            Argon4Crypto.openPacketWithOptions(dp.data, dp.length, psk, s2cKey, dataOpenOptions)
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
            } catch (ce: CancellationException) {
                throw ce
            } catch (err: Throwable) {
                if (isActive) {
                    _status.value = _status.value.copy(lastError = "Ошибка приёма из туннеля: ${err.message}")
                }
                runCatching { socket.close() }
                runCatching { pfd.close() }
                tunToUdp.cancel()
            }
        }

        // 3. Keepalive только в реальном простое (v5 padded ping) с рандомизированным окном и сбросом при трафике
        val keepalive = scope.launch {
            val pingBytes = "ping".toByteArray(Charsets.UTF_8)
            Argon4Timing.runIdleKeepalive(
                config = transport.timing,
                clock = clock,
                tracker = tracker,
                onError = { err ->
                    if (isActive) {
                        _status.value = _status.value.copy(lastError = "Ошибка keepalive туннеля: ${err.message}")
                    }
                    runCatching { socket.close() }
                    runCatching { pfd.close() }
                    tunToUdp.cancel()
                    udpToTun.cancel()
                    scope.cancel()
                },
                sendKeepalive = {
                    val seq = seqCounter.getAndIncrement()
                    val ka = Argon4Crypto.sealPacketWithOptions(
                        frameType = Argon4Crypto.FRAME_KEEPALIVE,
                        salt = salt,
                        psk = psk,
                        key = c2sKey,
                        seq = seq,
                        plaintext = pingBytes,
                        options = dataSealOptions,
                    )
                    socket.send(DatagramPacket(ka, ka.size, serverAddr))
                },
            )
        }

        try {
            tunToUdp.join()
        } finally {
            keepalive.cancel()
            udpToTun.cancel()
            tunToUdp.cancel()
            runCatching { socket.close() }
            runCatching { pfd.close() }
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
