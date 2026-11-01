package com.example.argontunnel.crypto

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import javax.crypto.AEADBadTagException

class Argon4CryptoTest {

    private val fastParams = Argon4Params(iterations = 1, memoryKiB = 64, parallelism = 1)

    @Test
    fun `argon2id matches RFC 9106 and Ubuntu Go server reference`() {
        val key = Argon4Crypto.deriveKey(
            masterPassword = "password",
            salt = "somesalt16bytes!".toByteArray(Charsets.UTF_8),
            params = Argon4Params(iterations = 2, memoryKiB = 64, parallelism = 1),
        )
        val hex = key.encoded.joinToString("") { "%02x".format(it) }
        assertEquals("ac09230651f4855141b3cdfca3e77a7ab1a70d300bbd4a78cf9bb658333e4abe", hex)
    }

    @Test
    fun `seal and open packet roundtrip with master password`() {
        val salt = "0123456789abcdef".toByteArray(Charsets.UTF_8)
        val key = Argon4Crypto.deriveKey("мастер-пароль-секрет", salt, fastParams)
        val payload = "зашифрованный материал через интерфейс".toByteArray(Charsets.UTF_8)

        val packet = Argon4Crypto.sealPacket(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            key = key,
            plaintext = payload,
        )

        val opened = Argon4Crypto.openPacket(packet, packet.size, key)
        assertEquals(Argon4Crypto.FRAME_DATA, opened.frameType)
        assertArrayEquals(salt, opened.salt)
        assertArrayEquals(payload, opened.plaintext)
    }

    @Test
    fun `wrong master password or tampered header fails decryption`() {
        val salt = "0123456789abcdef".toByteArray(Charsets.UTF_8)
        val rightKey = Argon4Crypto.deriveKey("верный-мастер-пароль", salt, fastParams)
        val wrongKey = Argon4Crypto.deriveKey("чужой-мастер-пароль", salt, fastParams)

        val packet = Argon4Crypto.sealPacket(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            key = rightKey,
            plaintext = "секретный пакет данных".toByteArray(Charsets.UTF_8),
        )

        assertThrows(AEADBadTagException::class.java) {
            Argon4Crypto.openPacket(packet, packet.size, wrongKey)
        }

        // Изменение байта типа кадра в заголовке ломает проверку AAD
        val tampered = packet.copyOf()
        tampered[1] = Argon4Crypto.FRAME_KEEPALIVE
        assertThrows(AEADBadTagException::class.java) {
            Argon4Crypto.openPacket(tampered, tampered.size, rightKey)
        }
    }
}
