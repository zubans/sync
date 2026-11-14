package com.example.argontunnel.crypto

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertTrue
import org.junit.Assert.fail
import org.junit.Test
import java.io.File
import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress
import java.net.InetSocketAddress
import java.net.SocketTimeoutException
import javax.crypto.AEADBadTagException

class Argon4V5PaddingTest {
    private val json = Json {
        ignoreUnknownKeys = true
        encodeDefaults = true
    }

    @Test
    fun paddingProfilesAndRandomDistributionStayWithinBounds() {
        val salt = Argon4Crypto.newSalt()
        val psk = Argon4Crypto.derivePSK("pw-v5")
        val key = Argon4Crypto.deriveKey("pw-v5", salt)
        val payload = ByteArray(64) { 0x45 }

        val balanced = Argon4Crypto.normalizePaddingConfig(PaddingConfig(profile = "balanced"), isUdp = true)
        val seenLengths = mutableSetOf<Int>()
        for (seq in 1L..32L) {
            val wire = Argon4Crypto.sealPacketWithOptions(
                frameType = Argon4Crypto.FRAME_DATA,
                salt = salt,
                psk = psk,
                key = key,
                seq = seq,
                plaintext = payload,
                options = SealOptions(
                    version = Argon4Crypto.PROTOCOL_VERSION_V5,
                    padding = balanced,
                    maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                ),
            )
            assertTrue("Размер $wire.size превысил MTU", wire.size <= Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE)
            val minExpected = Argon4Crypto.OVERHEAD_V5 + payload.size + balanced.smallMinPad
            val maxExpected = Argon4Crypto.OVERHEAD_V5 + payload.size + balanced.smallMaxPad
            assertTrue(
                "Размер ${wire.size} вне диапазона [$minExpected..$maxExpected]",
                wire.size in minExpected..maxExpected,
            )
            seenLengths.add(wire.size)

            val opened = Argon4Crypto.openPacketWithOptions(
                packet = wire,
                psk = psk,
                key = key,
                options = OpenOptions(
                    expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5,
                    maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                ),
            )
            assertEquals(Argon4Crypto.PROTOCOL_VERSION_V5, opened.version)
            assertArrayEquals(payload, opened.plaintext)
        }
        assertTrue("Ожидалась переменная длина кадров, получено ${seenLengths.size}", seenLengths.size >= 4)

        // Фиксированный padding (max <= min) и неверный path MTU отклоняются.
        try {
            Argon4Crypto.normalizePaddingConfig(
                PaddingConfig(profile = "custom", minPad = 24, maxPad = 24),
                isUdp = true,
            )
            fail("Ожидался отказ для вырожденного padding")
        } catch (_: IllegalArgumentException) {
        }

        try {
            Argon4Crypto.normalizePaddingConfig(PaddingConfig(pathMtu = 300), isUdp = true)
            fail("Ожидался отказ для недопустимого path MTU")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.MTU_EXCEEDED, ex.kind)
        }

        // Проверка ограничения TUN MTU по path MTU.
        assertEquals(1380, Argon4Crypto.effectiveTunMtu(1380, 1500, Argon4Crypto.PROTOCOL_VERSION_V5))
        assertEquals(1202, Argon4Crypto.effectiveTunMtu(1380, 1280, Argon4Crypto.PROTOCOL_VERSION_V5))
    }

    @Test
    fun envelopeAndAntiDowngradeRejectCorruptedFrames() {
        val salt = Argon4Crypto.newSalt()
        val psk = Argon4Crypto.derivePSK("strict-secret")
        val key = Argon4Crypto.deriveKey("strict-secret", salt)
        val payload = "hello-v5".toByteArray()

        // 1. Повреждённый ненулевой байт padding -> INVALID_PADDING.
        val validEnv = Argon4Crypto.packEnvelopeV5(payload, 0, payload.size, padLen = 8, maxOuterBytes = 1472)
        val badPadEnv = validEnv.copyOf().apply { this[size - 1] = 0x55 }
        try {
            Argon4Crypto.unpackEnvelopeV5(badPadEnv)
            fail("Ожидался INVALID_PADDING")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.INVALID_PADDING, ex.kind)
        }

        // 2. Лишние байты после padding -> TRAILING_DATA.
        val trailingEnv = validEnv + byteArrayOf(0x00, 0x00)
        try {
            Argon4Crypto.unpackEnvelopeV5(trailingEnv)
            fail("Ожидался TRAILING_DATA")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.TRAILING_DATA, ex.kind)
        }

        // 3. Несогласованная длина в заголовке конверта -> INVALID_ENVELOPE.
        val shortEnv = validEnv.copyOf().apply { this[3] = 0x40 }
        try {
            Argon4Crypto.unpackEnvelopeV5(shortEnv)
            fail("Ожидался INVALID_ENVELOPE")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.INVALID_ENVELOPE, ex.kind)
        }

        // 4. Повреждение шифртекста -> AEADBadTagException.
        val wire = Argon4Crypto.sealPacketWithOptions(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            psk = psk,
            key = key,
            seq = 1L,
            plaintext = payload,
            options = SealOptions(version = Argon4Crypto.PROTOCOL_VERSION_V5),
        )
        val corruptedWire = wire.copyOf().apply { this[size - 1] = (this[size - 1].toInt() xor 0xff).toByte() }
        try {
            Argon4Crypto.openPacketWithOptions(
                packet = corruptedWire,
                psk = psk,
                key = key,
                options = OpenOptions(expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5),
            )
            fail("Ожидалась ошибка GCM")
        } catch (_: AEADBadTagException) {
        }

        // 5. Downgrade v4 -> v5 должен отклоняться явно.
        val v4Wire = Argon4Crypto.sealPacketWithOptions(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            psk = psk,
            key = key,
            seq = 2L,
            plaintext = payload,
            options = SealOptions(version = Argon4Crypto.PROTOCOL_VERSION_V4),
        )
        try {
            Argon4Crypto.openPacketWithOptions(
                packet = v4Wire,
                psk = psk,
                key = key,
                options = OpenOptions(expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5, allowV4 = false),
            )
            fail("Ожидался DOWNGRADE_REJECTED")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.DOWNGRADE_REJECTED, ex.kind)
        }

        try {
            Argon4Crypto.validateHandshakeProtocol(
                Argon4Crypto.PROTOCOL_VERSION_V5,
                HandshakeResponse(
                    assignedIp = "10.44.0.2",
                    serverIp = "10.44.0.1",
                    subnetCidr = 24,
                    protocolVersion = 4,
                ),
            )
            fail("Ожидался DOWNGRADE_REJECTED для protocolVersion=4 в JSON")
        } catch (ex: Argon4ProtocolException) {
            assertEquals(Argon4ErrorKind.DOWNGRADE_REJECTED, ex.kind)
        }
    }

    @Test
    fun goServerV5InteropWithRetryObfuscationAndStrictMode() {
        val tunnelServerDir = resolveTunnelServerDir()
        val tempDir = File(System.getProperty("java.io.tmpdir"), "argon4-kt-v5-${System.nanoTime()}").apply { mkdirs() }
        val serverBin = File(tempDir, "e2e-server")
        val addrFile = File(tempDir, "addr.txt")
        val wireLog = File(tempDir, "wire.log")

        try {
            val build = ProcessBuilder("go", "build", "-o", serverBin.absolutePath, "./internal/tunnel/testdata/e2e-server")
                .directory(tunnelServerDir)
                .redirectErrorStream(true)
                .start()
            val buildOut = build.inputStream.bufferedReader().readText()
            assertEquals("Сборка e2e-server завершилась ошибкой:\n$buildOut", 0, build.waitFor())

            val proc = ProcessBuilder(
                serverBin.absolutePath,
                "-listen", "127.0.0.1:0",
                "-password", "kotlin-v5-secret",
                "-addr-file", addrFile.absolutePath,
                "-drop-responses", "1",
                "-echo-data",
                "-protocol-version", "5",
                "-allow-v4=true",
                "-padding-profile", "balanced",
                "-wire-log", wireLog.absolutePath,
            )
                .redirectErrorStream(true)
                .start()

            try {
                val addr = waitForAddress(addrFile)
                val hostPort = addr.split(":")
                val target = InetSocketAddress(InetAddress.getByName(hostPort[0]), hostPort[1].toInt())

                val salt = Argon4Crypto.newSalt()
                val psk = Argon4Crypto.derivePSK("kotlin-v5-secret")
                val hsKey = Argon4Crypto.deriveKey("kotlin-v5-secret", salt)
                val kp = Argon4Crypto.generateX25519Keypair()

                val req = HandshakeRequest(
                    clientId = "kt-v5-client",
                    clientAuth = Argon4Crypto.clientAuthToken(psk, "kt-v5-client", "kt-secret"),
                    clientPub = Argon4Crypto.bytesToHex(kp.publicKey),
                    timestamp = System.currentTimeMillis() / 1000,
                    protocolVersion = 5,
                    capabilities = Argon4Crypto.capabilitiesForVersion(Argon4Crypto.PROTOCOL_VERSION_V5),
                    paddingProfile = "balanced",
                )
                val reqBytes = json.encodeToString(req).toByteArray(Charsets.UTF_8)
                val firstProfile = Argon4Crypto.presetPaddingConfig("wide", 1500)
                val wireReq = Argon4Crypto.sealPacketWithOptions(
                    frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                    salt = salt,
                    psk = psk,
                    key = hsKey,
                    seq = 1L,
                    plaintext = reqBytes,
                    options = SealOptions(
                        version = Argon4Crypto.PROTOCOL_VERSION_V5,
                        padding = firstProfile,
                        maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                    ),
                )

                val recvBuf = ByteArray(4096)
                var hsResp: HandshakeResponse? = null
                DatagramSocket().use { sock ->
                    sock.soTimeout = 1000
                    for (attempt in 1..3) {
                        sock.send(DatagramPacket(wireReq, wireReq.size, target))
                        try {
                            val dp = DatagramPacket(recvBuf, recvBuf.size)
                            sock.receive(dp)
                            val opened = Argon4Crypto.openPacketWithOptions(
                                packet = dp.data,
                                length = dp.length,
                                psk = psk,
                                key = hsKey,
                                options = OpenOptions(
                                    expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5,
                                    allowV4 = false,
                                    maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                                ),
                            )
                            val resp = json.decodeFromString<HandshakeResponse>(opened.plaintext.decodeToString())
                            Argon4Crypto.validateHandshakeProtocol(Argon4Crypto.PROTOCOL_VERSION_V5, resp)
                            hsResp = resp
                            break
                        } catch (_: SocketTimeoutException) {
                        }
                    }

                    val resp = hsResp ?: error("Сервер не вернул ответ handshake v5")
                    assertEquals(5, resp.protocolVersion)

                    val shared = Argon4Crypto.computeX25519Shared(kp.privateKey, Argon4Crypto.hexToBytes(resp.serverPub))
                    val sessionKeys = Argon4Crypto.deriveSessionKeys(hsKey, shared, salt)
                    val paddingCfg = Argon4Crypto.defaultPaddingConfig()

                    val ipv4 = ByteArray(64)
                    ipv4[0] = 0x45
                    ipv4[2] = 0
                    ipv4[3] = 64
                    ipv4[8] = 64
                    ipv4[9] = 17
                    System.arraycopy(InetAddress.getByName(resp.assignedIp).address, 0, ipv4, 12, 4)
                    System.arraycopy(InetAddress.getByName(resp.serverIp).address, 0, ipv4, 16, 4)

                    val sealOpts = SealOptions(
                        version = Argon4Crypto.PROTOCOL_VERSION_V5,
                        padding = paddingCfg,
                        maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                    )
                    val openOpts = OpenOptions(
                        expectedVersion = Argon4Crypto.PROTOCOL_VERSION_V5,
                        allowV4 = false,
                        maxOuterBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
                        maxPayloadBytes = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE - Argon4Crypto.OVERHEAD_V5,
                    )

                    for (seq in 1L..16L) {
                        val outData = Argon4Crypto.sealPacketWithOptions(
                            frameType = Argon4Crypto.FRAME_DATA,
                            salt = salt,
                            psk = psk,
                            key = sessionKeys.c2sKey,
                            seq = seq,
                            plaintext = ipv4,
                            options = sealOpts,
                        )
                        sock.send(DatagramPacket(outData, outData.size, target))
                        val dp = DatagramPacket(recvBuf, recvBuf.size)
                        sock.receive(dp)
                        val opened = Argon4Crypto.openPacketWithOptions(
                            packet = dp.data,
                            length = dp.length,
                            psk = psk,
                            key = sessionKeys.s2cKey,
                            options = openOpts,
                        )
                        assertEquals(ipv4.size, opened.plaintext.size)
                    }
                }

                // Проверка wire.log: повтор handshake отправляет идентичный кадр, а data-пакеты имеют разную длину.
                val lines = wireLog.readLines().mapNotNull { line ->
                    val parts = line.trim().split(Regex("\\s+"))
                    if (parts.size == 2) parts[0] to parts[1].toInt() else null
                }
                val rxHandshakes = lines.filter { it.first == "rx" }.take(2).map { it.second }
                assertEquals(2, rxHandshakes.size)
                assertEquals("Retry handshake должен повторять байт-в-байт тот же кадр", rxHandshakes[0], rxHandshakes[1])

                val txDataSizes = lines
                    .filter { it.first == "tx" && it.second in (Argon4Crypto.OVERHEAD_V5 + 64)..(Argon4Crypto.OVERHEAD_V5 + 64 + 192) }
                    .map { it.second }
                    .toSet()
                assertTrue("Ожидалась переменная длина v5 data на проводе: $txDataSizes", txDataSizes.size >= 4)
                assertTrue("Ни один кадр не должен превышать MTU", lines.all { it.second <= Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE })
            } finally {
                proc.destroyForcibly()
                proc.waitFor()
            }
        } finally {
            tempDir.deleteRecursively()
        }
    }

    private fun resolveTunnelServerDir(): File {
        val cwd = File(System.getProperty("user.dir")).canonicalFile
        val candidates = listOfNotNull(
            File(cwd, "tunnel-server"),
            cwd.parentFile?.let { File(it, "tunnel-server") },
            cwd.parentFile?.parentFile?.let { File(it, "tunnel-server") },
        )
        return candidates.firstOrNull { it.isDirectory && File(it, "go.mod").isFile }
            ?: error("Не найдена директория tunnel-server относительно $cwd")
    }

    private fun waitForAddress(addrFile: File): String {
        val deadline = System.currentTimeMillis() + 10_000
        while (System.currentTimeMillis() < deadline) {
            if (addrFile.isFile) {
                val text = addrFile.readText().trim()
                if (text.isNotEmpty()) return text
            }
            Thread.sleep(25)
        }
        error("Таймаут ожидания адреса e2e-server в ${addrFile.absolutePath}")
    }
}
