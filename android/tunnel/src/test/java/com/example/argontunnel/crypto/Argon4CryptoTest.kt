package com.example.argontunnel.crypto

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import javax.crypto.AEADBadTagException

class Argon4CryptoTest {

    @Test
    fun testDeriveKeyAndPSKDeterministic() {
        val password = "correct-horse-battery-staple"
        val salt = ByteArray(16) { it.toByte() }
        val key1 = Argon4Crypto.deriveKey(password, salt)
        val key2 = Argon4Crypto.deriveKey(password, salt)
        assertArrayEquals(key1.encoded, key2.encoded)

        val psk1 = Argon4Crypto.derivePSK(password)
        val psk2 = Argon4Crypto.derivePSK(password)
        assertArrayEquals(psk1, psk2)
        assertEquals(32, psk1.size)
    }

    @Test
    fun testClientAuthToken() {
        val psk = ByteArray(32) { 0x42 }
        val token1 = Argon4Crypto.clientAuthToken(psk, "client-1", "secret-1")
        val token2 = Argon4Crypto.clientAuthToken(psk, "client-1", "secret-1")
        val tokenDiff = Argon4Crypto.clientAuthToken(psk, "client-1", "secret-2")

        assertEquals(token1, token2)
        assertEquals(32, token1.length) // 16 bytes hex = 32 chars
        assertFalse(token1 == tokenDiff)
    }

    @Test
    fun testReplayWindow() {
        val rw = ReplayWindow()
        assertTrue(rw.accept(1))
        assertFalse(rw.accept(1)) // duplicate rejected
        assertTrue(rw.accept(2))
        assertTrue(rw.accept(10))
        assertTrue(rw.accept(5))  // inside window
        assertFalse(rw.accept(5)) // duplicate inside window rejected
        assertTrue(rw.accept(100))
        assertFalse(rw.accept(10)) // too old (diff >= 64)
    }

    @Test
    fun testSealAndOpenPacketWithSeqAndMask() {
        val password = "secret-password"
        val psk = Argon4Crypto.derivePSK(password)
        val salt = Argon4Crypto.newSalt()
        val key = Argon4Crypto.deriveKey(password, salt)

        val plaintext = "hello-argon4-encrypted-world".toByteArray(Charsets.UTF_8)
        val sealed = Argon4Crypto.sealPacketWithSeq(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            psk = psk,
            key = key,
            seq = 42L,
            plaintext = plaintext
        )

        val decrypted = Argon4Crypto.openPacket(sealed, sealed.size, psk, key)
        assertEquals(Argon4Crypto.FRAME_DATA, decrypted.frameType)
        assertEquals(42L, decrypted.seq)
        assertArrayEquals(salt, decrypted.salt)
        assertArrayEquals(plaintext, decrypted.plaintext)
    }

    @Test
    fun testOpenPacketWrongPasswordThrows() {
        val salt = Argon4Crypto.newSalt()
        val psk = Argon4Crypto.derivePSK("password-1")
        val key = Argon4Crypto.deriveKey("password-1", salt)

        val sealed = Argon4Crypto.sealPacketWithSeq(
            frameType = Argon4Crypto.FRAME_DATA,
            salt = salt,
            psk = psk,
            key = key,
            seq = 1L,
            plaintext = "data".toByteArray()
        )

        val wrongPsk = Argon4Crypto.derivePSK("password-2")
        val wrongKey = Argon4Crypto.deriveKey("password-2", salt)
        val error = runCatching {
            Argon4Crypto.openPacket(sealed, sealed.size, wrongPsk, wrongKey)
        }.exceptionOrNull()

        assertNotNull(error)
        assertTrue(
            error is IllegalArgumentException ||
                error is SecurityException ||
                error is AEADBadTagException,
        )
    }
}
