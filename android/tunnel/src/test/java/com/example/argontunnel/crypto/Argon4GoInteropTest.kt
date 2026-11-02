package com.example.argontunnel.crypto

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
import java.net.SocketTimeoutException
import java.nio.file.Files
import java.util.concurrent.TimeUnit

/** Живой сквозной тест: Kotlin UDP-клиент ↔ настоящий Go Argon4-сервер. */
class Argon4GoInteropTest {

    @Test
    fun kotlinClientRetriesLostHandshakeAndCompletesPFSKeepaliveWithGoServer() {
        val serverDir = findTunnelServerDir()
        assumeGoAvailable(serverDir)
        val tempDir = Files.createTempDirectory("argon4-kotlin-go-e2e").toFile()
        var server: Process? = null
        try {
            val binary = File(tempDir, "argon4-e2e-server")
            val build = ProcessBuilder(
                "go", "build", "-o", binary.absolutePath, "./internal/tunnel/testdata/e2e-server",
            ).directory(serverDir).redirectErrorStream(true).start()
            if (!build.waitFor(120, TimeUnit.SECONDS)) {
                build.destroyForcibly()
                fail("Таймаут сборки Go e2e-сервера")
            }
            val buildOutput = build.inputStream.bufferedReader().readText()
            assertEquals("Сборка Go e2e-сервера:\n$buildOutput", 0, build.exitValue())

            val addrFile = File(tempDir, "server.addr")
            server = ProcessBuilder(
                binary.absolutePath,
                "-password", PASSWORD,
                "-addr-file", addrFile.absolutePath,
                "-drop-responses", "1",
            ).directory(serverDir).redirectErrorStream(true).start()
            val serverAddr = waitForServerAddress(server, addrFile)

            DatagramSocket().use { socket ->
                socket.soTimeout = 5_000
                val salt = Argon4Crypto.newSalt()
                val psk = Argon4Crypto.derivePSK(PASSWORD)
                val handshakeKey = Argon4Crypto.deriveKey(PASSWORD, salt)
                val keyPair = Argon4Crypto.generateX25519Keypair()
                try {
                    val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
                    val request = HandshakeRequest(
                        clientId = "kotlin-go-e2e-client",
                        clientAuth = Argon4Crypto.clientAuthToken(psk, "kotlin-go-e2e-client", "install-secret"),
                        clientPub = Argon4Crypto.bytesToHex(keyPair.publicKey),
                        timestamp = System.currentTimeMillis() / 1000,
                    )
                    val wireRequest = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_HANDSHAKE_REQ,
                        salt = salt,
                        psk = psk,
                        key = handshakeKey,
                        seq = 1L,
                        plaintext = json.encodeToString(request).toByteArray(Charsets.UTF_8),
                    )

                    var openedHandshake: DecryptedPacket? = null
                    var handshakeAttempts = 0
                    socket.soTimeout = 250
                    for (attempt in 1..3) {
                        handshakeAttempts = attempt
                        socket.send(DatagramPacket(wireRequest, wireRequest.size, serverAddr))
                        try {
                            val handshakePacket = receivePacket(socket)
                            val opened = Argon4Crypto.openPacket(
                                handshakePacket.data, handshakePacket.length, psk, handshakeKey,
                            )
                            if (opened.frameType == Argon4Crypto.FRAME_HANDSHAKE_RESP) {
                                openedHandshake = opened
                                break
                            }
                        } catch (_: SocketTimeoutException) {
                            // Первый ответ намеренно потерян сервером; повторяем тот же запрос.
                        }
                    }
                    val handshake = openedHandshake ?: throw AssertionError("Handshake не выполнен после повторов")
                    assertTrue("Первый ответ должен быть потерян, затем запрос повторён", handshakeAttempts >= 2)
                    socket.soTimeout = 5_000
                    val response = json.decodeFromString<HandshakeResponse>(handshake.plaintext.decodeToString())
                    assertEquals("10.44.0.2", response.assignedIp)

                    val sharedSecret = Argon4Crypto.computeX25519Shared(
                        keyPair.privateKey,
                        Argon4Crypto.hexToBytes(response.serverPub),
                    )
                    val sessionKeys = try {
                        Argon4Crypto.deriveSessionKeys(handshakeKey, sharedSecret, salt)
                    } finally {
                        sharedSecret.fill(0)
                    }
                    assertFalse(sessionKeys.c2sKey.encoded.contentEquals(sessionKeys.s2cKey.encoded))

                    val wirePing = Argon4Crypto.sealPacketWithSeq(
                        frameType = Argon4Crypto.FRAME_KEEPALIVE,
                        salt = salt,
                        psk = psk,
                        key = sessionKeys.c2sKey,
                        seq = 1L,
                        plaintext = "ping".toByteArray(Charsets.UTF_8),
                    )
                    socket.send(DatagramPacket(wirePing, wirePing.size, serverAddr))

                    val pongPacket = receivePacket(socket)
                    val pong = Argon4Crypto.openPacket(
                        pongPacket.data, pongPacket.length, psk, sessionKeys.s2cKey,
                    )
                    assertEquals(Argon4Crypto.FRAME_KEEPALIVE, pong.frameType)
                    assertEquals(1L, pong.seq)
                    assertArrayEquals("pong".toByteArray(Charsets.UTF_8), pong.plaintext)
                    assertTrue(ReplayWindow().accept(pong.seq))
                } finally {
                    keyPair.privateKey.fill(0)
                }
            }
        } finally {
            server?.destroyForcibly()
            server?.waitFor(5, TimeUnit.SECONDS)
            tempDir.deleteRecursively()
        }
    }

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
        const val PASSWORD = "kotlin-go-e2e-password"
    }
}
