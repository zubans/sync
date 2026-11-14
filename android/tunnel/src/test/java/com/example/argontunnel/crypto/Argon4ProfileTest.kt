package com.example.argontunnel.crypto

import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.yield
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.AssumptionViolatedException
import org.junit.Test
import java.io.File
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetSocketAddress
import java.nio.file.Files
import java.util.concurrent.CopyOnWriteArrayList
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicLong

private class FakeProfileClock : ArgonClock {
    private data class Waiter(
        val wakeAtMs: Long,
        val signal: CompletableDeferred<Unit>,
    )

    private val lock = Any()
    private var currentMs = 1_000L
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

    suspend fun advanceBy(deltaMs: Long) {
        val targetMs = synchronized(lock) { currentMs + deltaMs }
        repeat(4) { yield() }
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
            repeat(6) { yield() }
        }
        synchronized(lock) {
            if (targetMs > currentMs) currentMs = targetMs
        }
        repeat(4) { yield() }
    }
}

class Argon4ProfileTest {

    @Test
    fun validateProfileRequestEnforcesV5UdpAndExplicitOptIn() {
        val (noneName, noneVer) = Argon4Profile.validateProfileRequest(
            version = Argon4Crypto.PROTOCOL_VERSION_V5,
            cfg = ArgonProfileConfig(name = "off"),
        )
        assertEquals("", noneName)
        assertEquals(0, noneVer)

        val (baseName, baseVer) = Argon4Profile.validateProfileRequest(
            version = Argon4Crypto.PROTOCOL_VERSION_V5,
            cfg = ArgonProfileConfig(name = "baseline"),
        )
        assertEquals(Argon4Profile.PROFILE_BASELINE, baseName)
        assertEquals(Argon4Profile.BEHAVIOR_PROFILE_VERSION, baseVer)

        // v4 + любой профиль отвергается явно
        try {
            Argon4Profile.validateProfileRequest(
                version = Argon4Crypto.PROTOCOL_VERSION_V4,
                cfg = ArgonProfileConfig(name = Argon4Profile.PROFILE_VIDEO_CALL, version = 1),
            )
            fail("Ожидалась ошибка v4 + profile")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.PROFILE_UNSUPPORTED, ex.kind)
        }

        // video-call поверх TCP-транзита отвергается
        try {
            Argon4Profile.validateProfileRequest(
                version = Argon4Crypto.PROTOCOL_VERSION_V5,
                mode = Argon4Profile.MODE_TRANSIT,
                proto = Argon4Profile.PROTO_TCP,
                cfg = ArgonProfileConfig(name = Argon4Profile.PROFILE_VIDEO_CALL),
            )
            fail("Ожидалась ошибка video-call over TCP")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.PROFILE_UNSUPPORTED, ex.kind)
        }

        // Неизвестный профиль отвергается
        try {
            Argon4Profile.validateProfileRequest(
                version = Argon4Crypto.PROTOCOL_VERSION_V5,
                cfg = ArgonProfileConfig(name = "quantum-stealth"),
            )
            fail("Ожидалась ошибка неизвестного профиля")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.PROFILE_UNSUPPORTED, ex.kind)
        }
    }

    @Test
    fun verifyClientProfileResponseRejectsSilentDowngradeAndMismatch() {
        val pad = Argon4Crypto.defaultPaddingConfig()
        val timing = ArgonTimingConfig()
        val okResp = HandshakeResponse(
            assignedIp = "10.44.0.2",
            serverIp = "10.44.0.1",
            subnetCidr = 24,
            serverPub = "00".repeat(32),
            protocolVersion = Argon4Crypto.PROTOCOL_VERSION_V5.toInt(),
            capabilities = listOf("pad-v5", "profile-v1"),
            behaviorProfile = Argon4Profile.PROFILE_VIDEO_CALL,
            profileVersion = Argon4Profile.BEHAVIOR_PROFILE_VERSION,
        )
        val accepted = Argon4Profile.verifyClientProfileResponse(
            reqName = Argon4Profile.PROFILE_VIDEO_CALL,
            reqVersion = 1,
            resp = okResp,
            pad = pad,
            timing = timing,
            cfg = ArgonProfileConfig(name = Argon4Profile.PROFILE_VIDEO_CALL, version = 1),
        )
        assertTrue(accepted.isActive())
        assertEquals(Argon4Profile.PROFILE_VIDEO_CALL, accepted.name)
        assertTrue("recommendedTunMtu=${accepted.recommendedTunMtu}", accepted.recommendedTunMtu in 1100..1160)

        // Молчаливый откат сервером на "" или "baseline" запрещён
        for (badResp in listOf(
            okResp.copy(behaviorProfile = "", profileVersion = 0),
            okResp.copy(behaviorProfile = Argon4Profile.PROFILE_BASELINE),
            okResp.copy(capabilities = listOf("pad-v5")),
            okResp.copy(protocolVersion = Argon4Crypto.PROTOCOL_VERSION_V4.toInt()),
        )) {
            try {
                Argon4Profile.verifyClientProfileResponse(
                    reqName = Argon4Profile.PROFILE_VIDEO_CALL,
                    reqVersion = 1,
                    resp = badResp,
                    pad = pad,
                    timing = timing,
                    cfg = ArgonProfileConfig(name = Argon4Profile.PROFILE_VIDEO_CALL, version = 1),
                )
                fail("Ожидался отказ при несогласованном ответе сервера: $badResp")
            } catch (ex: Argon4ProtocolException) {
                assertEquals(Argon4ErrorKind.PROFILE_UNSUPPORTED, ex.kind)
            }
        }
    }

    @Test
    fun packAndUnpackBatchPlaintextValidatesBounds() {
        val p1 = byteArrayOf(0x45, 0x00, 0x00, 0x20, 1, 2, 3)
        val p2 = byteArrayOf(0x45, 0x00, 0x00, 0x24, 4, 5, 6, 7, 8)
        val packed = Argon4Profile.packBatchPlaintext(listOf(p1, p2))
        val unpacked = Argon4Profile.unpackBatchPlaintext(packed, maxPayloadLen = 1400)
        assertEquals(2, unpacked.size)
        assertArrayEquals(p1, unpacked[0])
        assertArrayEquals(p2, unpacked[1])

        // Лишние байты в конце контейнера отвергаются
        val withTrailing = packed + byteArrayOf(0x7f)
        try {
            Argon4Profile.unpackBatchPlaintext(withTrailing)
            fail("Ожидалась ошибка TRAILING_DATA")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.TRAILING_DATA, ex.kind)
        }

        // Менее 2 пакетов запрещено
        try {
            Argon4Profile.packBatchPlaintext(listOf(p1))
            fail("Ожидалась ошибка при размере batch=1")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.INVALID_ENVELOPE, ex.kind)
        }
    }

    @Test
    fun shaperCoalescesPrioritizesRealPacketsHandlesBackpressureAndStepsDown() = runBlocking {
        val clock = FakeProfileClock()
        val salt = Argon4Crypto.newSalt()
        val psk = Argon4Crypto.derivePSK("test-password")
        val key = Argon4Crypto.deriveKey("test-password", salt)
        val seqGen = AtomicLong(1L)
        val wireOut = CopyOnWriteArrayList<DecryptedPacket>()
        val downgradeMsgs = CopyOnWriteArrayList<String>()

        val profile = Argon4Profile.buildSessionProfile(
            cfg = ArgonProfileConfig(
                name = Argon4Profile.PROFILE_VIDEO_CALL,
                version = 1,
                archetype = "telemost-av-active",
                maxDurationMs = 60_000L,
                maxQueuePackets = 4,
                maxAddedDelayMs = 15L,
                coalesceWindowMs = 6L,
            ),
            randIntFn = { 0 },
        )

        val scope = CoroutineScope(coroutineContext + SupervisorJob(coroutineContext[Job]))
        val shaper = ArgonTrafficShaper(
            profile = profile,
            salt = salt,
            psk = psk,
            key = key,
            clock = clock,
            randUnit = { 0.0 },
            randIntFn = { 0 },
            nextSeq = { seqGen.getAndIncrement() },
            writeWire = { wire ->
                wireOut.add(Argon4Crypto.openPacketWithOptions(wire, wire.size, psk, key))
            },
            onDowngrade = { downgradeMsgs.add(it) },
        )
        shaper.start(scope)
        try {
            // Служебный кадр в очереди должен уступить паре реальных мелких пакетов, которые соберутся в FRAME_BATCH.
            shaper.requestControl(Argon4Profile.KEEPALIVE_FEEDBACK_PAYLOAD)
            shaper.enqueueReal(ByteArray(44) { 0x11 })
            shaper.enqueueReal(ByteArray(48) { 0x22 })
            shaper.enqueueReal(ByteArray(52) { 0x33 })
            shaper.enqueueReal(ByteArray(56) { 0x44 })

            // Пятый пакет превышает лимит очереди (maxQueuePackets=4) -> backpressure без утечки памяти.
            var caughtQueueFull = false
            try {
                shaper.enqueueReal(ByteArray(40) { 0x55 })
            } catch (_: QueueFullException) {
                caughtQueueFull = true
            }
            assertTrue("Переполнение очереди должно вызывать QueueFullException", caughtQueueFull)

            // Прокручиваем окно коалесинга/отправки.
            clock.advanceBy(25L)

            val statsAfterBurst = shaper.stats()
            assertEquals(1L, statsAfterBurst.droppedBackpressure)
            assertTrue("Ожидалась агрегация в FRAME_BATCH: $statsAfterBurst", statsAfterBurst.sentBatchFrames >= 1L)
            assertEquals(4L, statsAfterBurst.coalescedPackets)

            // Первым отправленным кадром должен быть FRAME_BATCH (реальные данные выше служебного "fb"),
            // а seq строго монотонным.
            assertTrue(wireOut.size >= 2)
            assertEquals(Argon4Crypto.FRAME_BATCH, wireOut[0].frameType)
            assertEquals(Argon4Crypto.FRAME_KEEPALIVE, wireOut.last().frameType)
            for (i in 1 until wireOut.size) {
                assertTrue("seq должен строго расти", wireOut[i].seq > wireOut[i - 1].seq)
            }

            // По истечении maxDurationMs профиль автоматически снижается до baseline.
            clock.advanceBy(60_050L)
            val downgraded = shaper.stats()
            assertTrue("Ожидалось снижение профиля после maxDuration", downgraded.profileDowngraded)
            assertEquals(Argon4Profile.PROFILE_BASELINE, downgraded.activeProfile)
            assertTrue(downgraded.activeArchetype.startsWith("baseline-"))
            assertEquals(1, downgradeMsgs.size)
        } finally {
            shaper.close()
            scope.cancel()
        }
    }

    @Test
    fun videoCallExpirySwitchesPaddingCoalescingAndStatsToBaseline() = runBlocking {
        val clock = FakeProfileClock()
        val salt = Argon4Crypto.newSalt()
        val psk = Argon4Crypto.derivePSK("vc-expiry-password")
        val key = Argon4Crypto.deriveKey("vc-expiry-password", salt)
        val seqGen = AtomicLong(1L)
        val emitted = CopyOnWriteArrayList<Pair<Int, DecryptedPacket>>()
        val downgradeMsgs = CopyOnWriteArrayList<String>()

        val profile = Argon4Profile.buildSessionProfile(
            cfg = ArgonProfileConfig(
                name = Argon4Profile.PROFILE_VIDEO_CALL,
                version = 1,
                maxDurationMs = 2_000L,
            ),
            randIntFn = { 0 },
        )

        val scope = CoroutineScope(coroutineContext + SupervisorJob(coroutineContext[Job]))
        val shaper = ArgonTrafficShaper(
            profile = profile,
            salt = salt,
            psk = psk,
            key = key,
            maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
            keepaliveEnabled = false,
            clock = clock,
            randUnit = { 0.5 },
            randIntFn = { 0 },
            nextSeq = { seqGen.getAndIncrement() },
            writeWire = { wire ->
                emitted.add(wire.size to Argon4Crypto.openPacketWithOptions(wire, wire.size, psk, key))
            },
            onDowngrade = { downgradeMsgs.add(it) },
        )
        shaper.start(scope)
        try {
            assertEquals(Argon4Profile.PROFILE_VIDEO_CALL, shaper.stats().activeProfile)

            // 1. До истечения срока пакет 350 Б попадает в MediumBins video-call (>= 620 Б).
            val medBefore = ByteArray(350) { 0x45 }
            shaper.enqueueReal(medBefore)
            clock.advanceBy(30L)
            assertEquals(1, emitted.size)
            assertEquals(Argon4Crypto.FRAME_DATA, emitted[0].second.frameType)
            assertTrue("До истечения срока ожидался внешний кадр >= 620 Б, было ${emitted[0].first}", emitted[0].first >= 620)

            // 2. Истекает maxDurationMs -> профиль, padding, агрегация и отсутствие новых "fb" переходят на baseline.
            clock.advanceBy(2_100L)
            val downgraded = shaper.stats()
            assertTrue("Ожидалось снижение профиля после maxDurationMs", downgraded.profileDowngraded)
            assertEquals(Argon4Profile.PROFILE_BASELINE, downgraded.activeProfile)
            assertTrue(downgraded.activeArchetype.startsWith("baseline-"))
            assertEquals(Argon4Profile.PROFILE_BASELINE, shaper.effectiveProfile().name)
            assertTrue(shaper.effectiveProfile().mediumBins.isEmpty())
            assertEquals(1, downgradeMsgs.size)

            val countAtExpiry = emitted.size
            // Дополнительный простой после истечения срока не должен порождать новые кадры обратной связи "fb".
            clock.advanceBy(1_500L)
            assertEquals("После снижения до baseline фоновые кадры 'fb' не должны отправляться", countAtExpiry, emitted.size)

            // После снижения пакет 350 Б не использует MediumBins (>= 620 Б).
            val medAfter = ByteArray(350) { 0x46 }
            shaper.enqueueReal(medAfter)
            clock.advanceBy(25L)
            val afterMed = emitted.subList(countAtExpiry, emitted.size)
            assertEquals(1, afterMed.size)
            assertEquals(Argon4Crypto.FRAME_DATA, afterMed[0].second.frameType)
            assertTrue("После перехода в baseline ожидался внешний кадр < 620 Б, было ${afterMed[0].first}", afterMed[0].first < 620)

            // Малые пакеты после истечения срока агрегируются в FRAME_BATCH.
            val countBeforeBatch = emitted.size
            val small1 = ByteArray(70) { 0x11 }
            val small2 = ByteArray(70) { 0x22 }
            shaper.enqueueReal(small1)
            shaper.enqueueReal(small2)
            clock.advanceBy(20L)
            val afterBatch = emitted.subList(countBeforeBatch, emitted.size)
            assertEquals(1, afterBatch.size)
            assertEquals(Argon4Crypto.FRAME_BATCH, afterBatch[0].second.frameType)
            val unpacked = Argon4Profile.unpackBatchPlaintext(afterBatch[0].second.plaintext, Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE)
            assertEquals(2, unpacked.size)
            assertArrayEquals(small1, unpacked[0])
            assertArrayEquals(small2, unpacked[1])
            assertTrue(shaper.stats().sentBatchFrames >= 1L)
        } finally {
            shaper.close()
            scope.cancel()
        }
    }

    @Test
    fun kotlinVideoCallNegotiatesBatchEchoWithGoServerAndRejectsDisabledPolicy() {
        val serverDir = findTunnelServerDir()
        assumeGoAvailable(serverDir)
        val tempDir = Files.createTempDirectory("argon4-kotlin-vc-e2e").toFile()
        var allowServer: Process? = null
        var denyServer: Process? = null
        try {
            val binary = File(tempDir, "argon4-e2e-server")
            val build = ProcessBuilder(
                "go", "build", "-o", binary.absolutePath, "./internal/tunnel/testdata/e2e-server",
            ).directory(serverDir).redirectErrorStream(true).start()
            if (!build.waitFor(120, TimeUnit.SECONDS)) {
                build.destroyForcibly()
                fail("Таймаут сборки Go e2e-сервера")
            }
            assertEquals(0, build.exitValue())

            val allowAddrFile = File(tempDir, "allow.addr")
            allowServer = ProcessBuilder(
                binary.absolutePath,
                "-password", PASSWORD,
                "-addr-file", allowAddrFile.absolutePath,
                "-echo-data=true",
                "-allow-video-call=true",
            ).directory(serverDir).redirectErrorStream(true).start()
            val allowAddr = waitForServerAddress(allowServer, allowAddrFile)

            DatagramSocket().use { socket ->
                socket.soTimeout = 5_000
                val salt = Argon4Crypto.newSalt()
                val psk = Argon4Crypto.derivePSK(PASSWORD)
                val handshakeKey = Argon4Crypto.deriveKey(PASSWORD, salt)
                val keyPair = Argon4Crypto.generateX25519Keypair()
                try {
                    val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
                    val req = HandshakeRequest(
                        clientId = "kotlin-vc-client",
                        clientAuth = Argon4Crypto.clientAuthToken(psk, "kotlin-vc-client", "vc-secret"),
                        clientPub = Argon4Crypto.bytesToHex(keyPair.publicKey),
                        timestamp = System.currentTimeMillis() / 1000,
                        protocolVersion = Argon4Crypto.PROTOCOL_VERSION_V5.toInt(),
                        capabilities = Argon4Crypto.capabilitiesForVersion(Argon4Crypto.PROTOCOL_VERSION_V5, profileCapable = true),
                        behaviorProfile = Argon4Profile.PROFILE_VIDEO_CALL,
                        profileVersion = Argon4Profile.BEHAVIOR_PROFILE_VERSION,
                    )
                    val wireReq = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                        salt = salt,
                        psk = psk,
                        key = handshakeKey,
                        seq = 1L,
                        plaintext = json.encodeToString(req).toByteArray(Charsets.UTF_8),
                    )
                    socket.send(DatagramPacket(wireReq, wireReq.size, allowAddr))
                    val hsPkt = receivePacket(socket)
                    val openedHs = Argon4Crypto.openPacket(hsPkt.data, hsPkt.length, psk, handshakeKey)
                    val resp = json.decodeFromString<HandshakeResponse>(openedHs.plaintext.decodeToString())
                    val negotiated = Argon4Profile.verifyClientProfileResponse(
                        reqName = Argon4Profile.PROFILE_VIDEO_CALL,
                        reqVersion = Argon4Profile.BEHAVIOR_PROFILE_VERSION,
                        resp = resp,
                        pad = Argon4Crypto.defaultPaddingConfig(),
                        timing = ArgonTimingConfig(),
                        cfg = ArgonProfileConfig(name = Argon4Profile.PROFILE_VIDEO_CALL, version = 1),
                    )
                    assertEquals(Argon4Profile.PROFILE_VIDEO_CALL, negotiated.name)

                    val shared = Argon4Crypto.computeX25519Shared(keyPair.privateKey, Argon4Crypto.hexToBytes(resp.serverPub))
                    val sessionKeys = try {
                        Argon4Crypto.deriveSessionKeys(handshakeKey, shared, salt)
                    } finally {
                        shared.fill(0)
                    }

                    // Шлём FRAME_BATCH из двух синтетических IPv4+UDP пакетов и ждём расшифрованный ответ.
                    val pkt1 = buildIPv4UdpEchoPacket("kotlin-vc-echo-1")
                    val pkt2 = buildIPv4UdpEchoPacket("kotlin-vc-echo-2")
                    val batchPlain = Argon4Profile.packBatchPlaintext(listOf(pkt1, pkt2))
                    val wireBatch = Argon4Crypto.sealPacketWithOptions(
                        frameType = Argon4Crypto.FRAME_BATCH,
                        salt = salt,
                        psk = psk,
                        key = sessionKeys.c2sKey,
                        seq = 1L,
                        plaintext = batchPlain,
                        options = SealOptions(
                            samplePad = { len, maxOuter -> negotiated.samplePaddingLen(len, maxOuter) },
                            maxOuterBytes = negotiated.targetMaxOuter,
                        ),
                    )
                    socket.send(DatagramPacket(wireBatch, wireBatch.size, allowAddr))

                    val echoedPayloads = mutableListOf<String>()
                    val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(5)
                    while (echoedPayloads.size < 2 && System.nanoTime() < deadline) {
                        val dp = receivePacket(socket)
                        val opened = Argon4Crypto.openPacket(dp.data, dp.length, psk, sessionKeys.s2cKey)
                        when (opened.frameType) {
                            Argon4Crypto.FRAME_DATA -> echoedPayloads.add(extractUdpPayload(opened.plaintext))
                            Argon4Crypto.FRAME_BATCH -> Argon4Profile.unpackBatchPlaintext(opened.plaintext).forEach {
                                echoedPayloads.add(extractUdpPayload(it))
                            }
                        }
                    }
                    assertEquals(listOf("kotlin-vc-echo-1", "kotlin-vc-echo-2"), echoedPayloads)
                } finally {
                    keyPair.privateKey.fill(0)
                }
            }

            // Проверяем, что сервер с -allow-video-call=false не допускает молчаливый откат.
            val denyAddrFile = File(tempDir, "deny.addr")
            denyServer = ProcessBuilder(
                binary.absolutePath,
                "-password", PASSWORD,
                "-addr-file", denyAddrFile.absolutePath,
                "-allow-video-call=false",
            ).directory(serverDir).redirectErrorStream(true).start()
            val denyAddr = waitForServerAddress(denyServer, denyAddrFile)

            DatagramSocket().use { socket ->
                socket.soTimeout = 600
                val salt = Argon4Crypto.newSalt()
                val psk = Argon4Crypto.derivePSK(PASSWORD)
                val handshakeKey = Argon4Crypto.deriveKey(PASSWORD, salt)
                val keyPair = Argon4Crypto.generateX25519Keypair()
                try {
                    val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
                    val req = HandshakeRequest(
                        clientId = "kotlin-vc-denied",
                        clientAuth = Argon4Crypto.clientAuthToken(psk, "kotlin-vc-denied", "vc-secret"),
                        clientPub = Argon4Crypto.bytesToHex(keyPair.publicKey),
                        timestamp = System.currentTimeMillis() / 1000,
                        protocolVersion = Argon4Crypto.PROTOCOL_VERSION_V5.toInt(),
                        capabilities = Argon4Crypto.capabilitiesForVersion(Argon4Crypto.PROTOCOL_VERSION_V5, profileCapable = true),
                        behaviorProfile = Argon4Profile.PROFILE_VIDEO_CALL,
                        profileVersion = Argon4Profile.BEHAVIOR_PROFILE_VERSION,
                    )
                    val wireReq = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                        salt = salt,
                        psk = psk,
                        key = handshakeKey,
                        seq = 1L,
                        plaintext = json.encodeToString(req).toByteArray(Charsets.UTF_8),
                    )
                    socket.send(DatagramPacket(wireReq, wireReq.size, denyAddr))
                    var gotResponse = false
                    try {
                        val dp = receivePacket(socket)
                        gotResponse = dp.length > 0
                    } catch (_: java.net.SocketTimeoutException) {
                        // Сервер отклонил рукопожатие video-call и не выдал ответ.
                    }
                    assertFalse("Сервер при -allow-video-call=false не должен подтверждать video-call", gotResponse)
                } finally {
                    keyPair.privateKey.fill(0)
                }
            }
        } finally {
            allowServer?.destroyForcibly()
            denyServer?.destroyForcibly()
            tempDir.deleteRecursively()
        }
    }

    private fun buildIPv4UdpEchoPacket(payload: String): ByteArray {
        val body = payload.toByteArray(Charsets.UTF_8)
        val totalLen = 28 + body.size
        val pkt = ByteArray(totalLen)
        pkt[0] = 0x45
        pkt[2] = (totalLen ushr 8).toByte()
        pkt[3] = totalLen.toByte()
        pkt[8] = 64
        pkt[9] = 17 // UDP
        // src IP 10.44.0.2 -> dst IP 10.44.0.1
        pkt[12] = 10; pkt[13] = 44; pkt[14] = 0; pkt[15] = 2
        pkt[16] = 10; pkt[17] = 44; pkt[18] = 0; pkt[19] = 1
        // src port 51000, dst port 52000
        pkt[20] = (51000 ushr 8).toByte(); pkt[21] = 51000.toByte()
        pkt[22] = (52000 ushr 8).toByte(); pkt[23] = 52000.toByte()
        val udpLen = 8 + body.size
        pkt[24] = (udpLen ushr 8).toByte(); pkt[25] = udpLen.toByte()
        System.arraycopy(body, 0, pkt, 28, body.size)
        return pkt
    }

    private fun extractUdpPayload(pkt: ByteArray): String =
        if (pkt.size > 28) pkt.copyOfRange(28, pkt.size).decodeToString() else ""

    private fun receivePacket(socket: DatagramSocket): DatagramPacket {
        val packet = DatagramPacket(ByteArray(4096), 4096)
        socket.receive(packet)
        return packet
    }

    private fun waitForServerAddress(server: Process, addrFile: File): InetSocketAddress {
        val deadline = System.nanoTime() + TimeUnit.SECONDS.toNanos(15)
        while (System.nanoTime() < deadline) {
            if (addrFile.isFile && addrFile.length() > 0) {
                val address = addrFile.readText().trim()
                return InetSocketAddress(address.substringBeforeLast(':'), address.substringAfterLast(':').toInt())
            }
            if (!server.isAlive) {
                fail("Go e2e-сервер завершился:\n${server.inputStream.bufferedReader().readText()}")
            }
            Thread.sleep(50)
        }
        throw AssertionError("Go e2e-сервер не сообщил адрес за 15 секунд")
    }

    private fun findTunnelServerDir(): File {
        val workingDir = System.getProperty("user.dir") ?: throw AssertionError("user.dir не задан")
        var dir = File(workingDir).absoluteFile
        repeat(6) {
            val candidate = File(dir, "tunnel-server")
            if (candidate.isDirectory) return candidate
            dir = dir.parentFile ?: throw AssertionError("Каталог tunnel-server не найден от $workingDir")
        }
        throw AssertionError("Каталог tunnel-server не найден от $workingDir")
    }

    private fun assumeGoAvailable(serverDir: File) {
        try {
            val process = ProcessBuilder("go", "version").directory(serverDir).start()
            if (!process.waitFor(10, TimeUnit.SECONDS) || process.exitValue() != 0) {
                throw AssumptionViolatedException("Для Kotlin ↔ Go e2e-теста нужен Go")
            }
        } catch (e: java.io.IOException) {
            throw AssumptionViolatedException("Для Kotlin ↔ Go e2e-теста нужен Go", e)
        }
    }

    private companion object {
        const val PASSWORD = "kotlin-vc-e2e-password"
    }
}
