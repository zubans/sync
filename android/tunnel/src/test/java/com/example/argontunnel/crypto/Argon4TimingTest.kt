package com.example.argontunnel.crypto

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.async
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.yield
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.SocketException
import java.net.SocketTimeoutException
import java.util.concurrent.atomic.AtomicInteger

private class FakeArgonClock : ArgonClock {
    private data class Waiter(
        val wakeAtMs: Long,
        val signal: CompletableDeferred<Unit>,
    )

    private val lock = Any()
    private var currentMs = 0L
    private val waiters = mutableListOf<Waiter>()

    override fun nowMs(): Long = synchronized(lock) { currentMs }

    override suspend fun delayMs(durationMs: Long) {
        if (durationMs <= 0L) return
        val signal = CompletableDeferred<Unit>()
        val waiter = Waiter(
            wakeAtMs = synchronized(lock) { currentMs + durationMs },
            signal = signal,
        )
        synchronized(lock) { waiters += waiter }
        try {
            signal.await()
        } finally {
            synchronized(lock) { waiters.remove(waiter) }
        }
    }

    fun advanceSync(deltaMs: Long) {
        if (deltaMs <= 0L) return
        synchronized(lock) { currentMs += deltaMs }
    }

    fun activeWaiters(): Int = synchronized(lock) { waiters.size }

    suspend fun advanceNextWaiter(): Boolean {
        val next = synchronized(lock) {
            waiters.minByOrNull { it.wakeAtMs }?.also {
                if (it.wakeAtMs > currentMs) {
                    currentMs = it.wakeAtMs
                }
                waiters.remove(it)
            }
        } ?: return false
        next.signal.complete(Unit)
        yield()
        return true
    }

    suspend fun awaitWaiters(minCount: Int) {
        repeat(64) {
            if (activeWaiters() >= minCount) return
            yield()
        }
    }

    suspend fun advanceBy(deltaMs: Long) {
        val targetMs = synchronized(lock) { currentMs + deltaMs }
        yield()
        while (true) {
            val next = synchronized(lock) {
                waiters.filter { it.wakeAtMs <= targetMs }.minByOrNull { it.wakeAtMs }
            } ?: break
            synchronized(lock) {
                if (next.wakeAtMs > currentMs) {
                    currentMs = next.wakeAtMs
                }
                waiters.remove(next)
            }
            next.signal.complete(Unit)
            yield()
        }
        synchronized(lock) {
            if (targetMs > currentMs) {
                currentMs = targetMs
            }
        }
        yield()
    }
}

class Argon4TimingTest {
    private val json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
    }

    @Test
    fun testTimingConfigValidation() {
        val defaults = Argon4Timing.normalizeConfig()
        assertEquals(5, defaults.handshakeMaxAttempts)
        assertEquals(16_000L, defaults.keepaliveMinIdleMs)
        assertEquals(28_000L, defaults.keepaliveMaxIdleMs)
        assertEquals(600_000L, defaults.sessionIdleTimeoutMs)

        val invalidCases = listOf(
            ArgonTimingConfig(handshakeMaxAttempts = -1),
            ArgonTimingConfig(handshakeMaxAttempts = 21),
            ArgonTimingConfig(handshakeInitialTimeoutMs = 10L),
            ArgonTimingConfig(handshakeInitialTimeoutMs = 2_000L, handshakeMaxTimeoutMs = 1_000L),
            ArgonTimingConfig(handshakeBackoffFactor = 1.0),
            ArgonTimingConfig(handshakeJitterRatio = 0.01),
            ArgonTimingConfig(keepaliveMinIdleMs = 20_000L, keepaliveMaxIdleMs = 20_000L),
            ArgonTimingConfig(keepaliveMinIdleMs = 25_000L, keepaliveMaxIdleMs = 15_000L),
            ArgonTimingConfig(keepaliveMinIdleMs = 400_000L, keepaliveMaxIdleMs = 500_000L, sessionIdleTimeoutMs = 600_000L),
        )
        invalidCases.forEach { bad ->
            try {
                Argon4Timing.normalizeConfig(bad)
                fail("Ожидалась ошибка валидации для $bad")
            } catch (_: IllegalArgumentException) {
                // Ожидаемо
            }
        }
    }

    @Test
    fun testIdleKeepaliveSuppressedDuringTrafficAndSentBeforeTimeout() = runBlocking {
        val clock = FakeArgonClock()
        val tracker = ActivityTracker(clock)
        val cfg = ArgonTimingConfig(
            keepaliveMinIdleMs = 16_000L,
            keepaliveMaxIdleMs = 28_000L,
            sessionIdleTimeoutMs = 600_000L,
        )
        val sentAt = mutableListOf<Long>()

        val job = launch {
            Argon4Timing.runIdleKeepalive(
                config = cfg,
                clock = clock,
                tracker = tracker,
                randUnit = { 0.5 },
            ) {
                sentAt += clock.nowMs()
            }
        }
        clock.awaitWaiters(1)

        // Каждые 10 секунд есть реальный исходящий трафик (меньше minIdle=16с) -> keepalive подавлен.
        repeat(6) {
            clock.advanceBy(10_000L)
            tracker.touch()
            clock.advanceBy(1L)
        }
        assertEquals("Keepalive не должен отправляться при активном трафике", 0, sentAt.size)

        // После прекращения трафика должен уйти ровно один keepalive внутри [16с..28с], и второй при дальнейшем простое.
        clock.advanceBy(30_000L)
        assertEquals("Ожидался ровно 1 keepalive за 30с простоя", 1, sentAt.size)
        val firstIdleGap = sentAt[0] - tracker.lastActiveMs()
        assertTrue(
            "Первый keepalive отправлен через $firstIdleGap мс, ожидалось [16000..28000]",
            firstIdleGap in 16_000L..28_000L,
        )

        clock.advanceBy(30_000L)
        assertEquals("Ожидался второй keepalive при продолжении простоя", 2, sentAt.size)

        job.cancel()
        job.join()
        assertEquals("Таймеры не должны утекать после отмены", 0, clock.activeWaiters())
    }

    @Test
    fun testIdleKeepaliveStopsCleanlyOnSocketErrorAndCancellation() = runBlocking {
        val clock = FakeArgonClock()
        val tracker = ActivityTracker(clock)
        val cfg = ArgonTimingConfig(
            keepaliveMinIdleMs = 1_000L,
            keepaliveMaxIdleMs = 2_000L,
            sessionIdleTimeoutMs = 10_000L,
        )
        var onErrorCalls = 0
        var sendAttempts = 0

        val errJob = launch {
            Argon4Timing.runIdleKeepalive(
                config = cfg,
                clock = clock,
                tracker = tracker,
                randUnit = { 0.25 },
                onError = { onErrorCalls++ },
            ) {
                sendAttempts++
                throw SocketException("Socket closed")
            }
        }
        clock.awaitWaiters(1)
        clock.advanceBy(2_500L)
        errJob.join()

        assertEquals(1, sendAttempts)
        assertEquals(1, onErrorCalls)
        assertEquals(0, clock.activeWaiters())

        // Отмена корутины во время ожидания также очищает все waiters.
        val cancelJob = launch {
            Argon4Timing.runIdleKeepalive(
                config = cfg,
                clock = clock,
                tracker = tracker,
            ) {
                // no-op
            }
        }
        clock.awaitWaiters(1)
        cancelJob.cancel()
        cancelJob.join()
        assertEquals(0, clock.activeWaiters())
    }

    @Test
    fun testHandshakeRandomizedExponentialBackoffAndDowngradeStop() = runBlocking {
        val clock = FakeArgonClock()
        val cfg = ArgonTimingConfig(
            handshakeMaxAttempts = 4,
            handshakeInitialTimeoutMs = 1_000L,
            handshakeMaxTimeoutMs = 4_000L,
            handshakeMaxElapsedMs = 20_000L,
            handshakeBackoffFactor = 2.0,
            handshakeRetryMinPauseMs = 100L,
            handshakeRetryMaxPauseMs = 200L,
            handshakeJitterRatio = 0.25,
            keepaliveMinIdleMs = 16_000L,
            keepaliveMaxIdleMs = 28_000L,
            sessionIdleTimeoutMs = 600_000L,
        )

        val wireReq = byteArrayOf(0x05, 0x01, 0x02, 0x03)
        val sentPayloads = mutableListOf<ByteArray>()
        val sentAtMs = mutableListOf<Long>()
        val timeoutsApplied = mutableListOf<Int>()
        var currentTimeoutMs = 0
        var recvStep = 0

        val resultDeferred = async {
            Argon4Timing.runHandshakeWithBackoff(
                config = cfg,
                clock = clock,
                randUnit = { 0.5 }, // Нейтральный jitter, проверяем чистую геометрию и отсутствие констант.
                wireRequest = wireReq,
                setReadTimeoutMs = { ms ->
                    currentTimeoutMs = ms
                    timeoutsApplied += ms
                },
                sendPacket = { pkt ->
                    sentPayloads += pkt.copyOf()
                    sentAtMs += clock.nowMs()
                },
                receivePacket = {
                    recvStep++
                    if (recvStep <= 2) {
                        clock.advanceSync(currentTimeoutMs.toLong())
                        throw SocketTimeoutException("timeout-$recvStep")
                    }
                    byteArrayOf(0x05, 0x02, 0x77)
                },
                parseResponse = { pkt ->
                    if (pkt.contentEquals(byteArrayOf(0x05, 0x02, 0x77))) "ok-handshake" else null
                },
            )
        }
        while (!resultDeferred.isCompleted) {
            yield()
            if (!clock.advanceNextWaiter() && !resultDeferred.isCompleted) {
                yield()
            }
        }
        val result = resultDeferred.await()

        assertEquals("ok-handshake", result)
        assertEquals(3, sentPayloads.size)
        sentPayloads.forEach { assertArrayEquals("Повторный HandshakeReq должен быть побайтно идентичен", wireReq, it) }
        assertTrue("Ожидался экспоненциальный рост таймаута: $timeoutsApplied", timeoutsApplied[1] > timeoutsApplied[0])
        assertTrue("Ожидался рост интервала попыток: $sentAtMs", (sentAtMs[2] - sentAtMs[1]) > (sentAtMs[1] - sentAtMs[0]))
        assertEquals(0, clock.activeWaiters())

        // Анти-downgrade: ошибка DOWNGRADE_REJECTED немедленно останавливает цикл без ретраев.
        var downgradeAttempts = 0
        try {
            Argon4Timing.runHandshakeWithBackoff(
                config = cfg,
                clock = clock,
                wireRequest = wireReq,
                setReadTimeoutMs = {},
                sendPacket = { downgradeAttempts++ },
                receivePacket = { byteArrayOf(0x04, 0x02) },
                parseResponse = {
                    throw Argon4ProtocolException(
                        Argon4ErrorKind.DOWNGRADE_REJECTED,
                        "Отклонена попытка понижения версии",
                    )
                },
            )
            fail("Ожидалось Argon4ProtocolException(DOWNGRADE_REJECTED)")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.DOWNGRADE_REJECTED, ex.kind)
            assertEquals("При downgrade повторные попытки запрещены", 1, downgradeAttempts)
        }
    }

    @Test
    fun testUdpSocketHandshakeRetryWithIdenticalPayloadAndV5Keys() = runBlocking {
        val password = "kotlin-timing-e2e-password"
        val psk = Argon4Crypto.derivePSK(password)
        val salt = Argon4Crypto.newSalt()
        val hsKey = Argon4Crypto.deriveKey(password, salt)
        val cliKp = Argon4Crypto.generateX25519Keypair()
        val srvKp = Argon4Crypto.generateX25519Keypair()

        val serverSock = DatagramSocket(0, InetAddress.getByName("127.0.0.1"))
        serverSock.soTimeout = 3000
        val clientSock = DatagramSocket(0, InetAddress.getByName("127.0.0.1"))

        val packetsSeen = mutableListOf<ByteArray>()
        val attemptCount = AtomicInteger(0)
        val serverErr = AtomicInteger(0)

        val srvThread = Thread {
            try {
                val buf = ByteArray(4096)
                while (attemptCount.get() < 3) {
                    val dp = DatagramPacket(buf, buf.size)
                    serverSock.receive(dp)
                    val copy = dp.data.copyOf(dp.length)
                    val hdr = Argon4Crypto.parseAndVerifyHeader(copy, copy.size, psk)
                    if (hdr.frameType != Argon4Crypto.FRAME_HANDSHAKE_REQ) continue
                    packetsSeen += copy
                    val count = attemptCount.incrementAndGet()
                    if (count < 3) continue

                    val resp = HandshakeResponse(
                        serverTime = 1700000000L,
                        serverPub = Argon4Crypto.bytesToHex(srvKp.publicKey),
                        assignedIp = "10.77.0.2",
                        serverIp = "10.77.0.1",
                        subnetCidr = 24,
                        mtu = 1380,
                        protocolVersion = 5,
                        capabilities = listOf("pad-v5"),
                    )
                    val wireResp = Argon4Crypto.sealPacketWithOptions(
                        frameType = Argon4Crypto.FRAME_HANDSHAKE_RESP,
                        salt = salt,
                        psk = psk,
                        key = hsKey,
                        seq = 1L,
                        plaintext = json.encodeToString(resp).toByteArray(Charsets.UTF_8),
                        options = SealOptions(
                            version = Argon4Crypto.PROTOCOL_VERSION_V5,
                            padding = Argon4Crypto.defaultPaddingConfig(),
                            maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                        ),
                    )
                    serverSock.send(DatagramPacket(wireResp, wireResp.size, dp.address, dp.port))
                }
            } catch (_: Throwable) {
                serverErr.incrementAndGet()
            }
        }
        srvThread.start()

        try {
            val req = HandshakeRequest(
                clientId = "kotlin-retry",
                clientAuth = Argon4Crypto.clientAuthToken(psk, "kotlin-retry", "sec"),
                clientPub = Argon4Crypto.bytesToHex(cliKp.publicKey),
                apps = emptyList(),
                timestamp = System.currentTimeMillis() / 1000,
                protocolVersion = 5,
                capabilities = listOf("pad-v5"),
                paddingProfile = "balanced",
            )
            val wireReq = Argon4Crypto.sealPacketWithOptions(
                frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                salt = salt,
                psk = psk,
                key = hsKey,
                seq = 1L,
                plaintext = json.encodeToString(req).toByteArray(Charsets.UTF_8),
                options = SealOptions(
                    version = Argon4Crypto.PROTOCOL_VERSION_V5,
                    padding = Argon4Crypto.defaultPaddingConfig(),
                    maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                ),
            )
            val serverAddr = InetSocketAddress("127.0.0.1", serverSock.localPort)
            val recvBuf = ByteArray(4096)

            val parsed = Argon4Timing.runHandshakeWithBackoff(
                config = ArgonTimingConfig(
                    handshakeMaxAttempts = 4,
                    handshakeInitialTimeoutMs = 60L,
                    handshakeMaxTimeoutMs = 300L,
                    handshakeMaxElapsedMs = 3_000L,
                    handshakeBackoffFactor = 1.6,
                    handshakeRetryMinPauseMs = 15L,
                    handshakeRetryMaxPauseMs = 40L,
                    handshakeJitterRatio = 0.25,
                    keepaliveMinIdleMs = 16_000L,
                    keepaliveMaxIdleMs = 28_000L,
                    sessionIdleTimeoutMs = 600_000L,
                ),
                clock = SystemArgonClock,
                wireRequest = wireReq,
                setReadTimeoutMs = { ms -> clientSock.soTimeout = ms },
                sendPacket = { pkt -> clientSock.send(DatagramPacket(pkt, pkt.size, serverAddr)) },
                receivePacket = {
                    val dp = DatagramPacket(recvBuf, recvBuf.size)
                    clientSock.receive(dp)
                    recvBuf.copyOf(dp.length)
                },
                parseResponse = { pkt ->
                    val opened = Argon4Crypto.openPacketWithOptions(
                        packet = pkt,
                        length = pkt.size,
                        psk = psk,
                        key = hsKey,
                        options = OpenOptions(
                            expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5,
                            allowV4 = false,
                        ),
                    )
                    json.decodeFromString<HandshakeResponse>(opened.plaintext.decodeToString())
                },
            )

            srvThread.join(2000)
            assertEquals(0, serverErr.get())
            assertEquals("10.77.0.2", parsed.assignedIp)
            assertEquals(3, packetsSeen.size)
            for (i in 1 until packetsSeen.size) {
                assertArrayEquals("UDP-ретрай #$i должен быть побайтно идентичен", packetsSeen[0], packetsSeen[i])
            }
        } finally {
            clientSock.close()
            serverSock.close()
            cliKp.privateKey.fill(0)
            srvKp.privateKey.fill(0)
        }
    }
}
