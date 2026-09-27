package com.example.contactsync.vault

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import javax.crypto.AEADBadTagException

class VaultCryptoTest {

    // В тестах — лёгкие параметры, чтобы не ждать; сами вычисления те же.
    private val params = KdfParams(VaultCrypto.KDF_ARGON2ID, iterations = 1, memoryKiB = 64, parallelism = 1)
    private val salt = VaultCrypto.newSalt()

    @Test
    fun `vault key survives wrap and unwrap with the right password`() {
        val vaultKey = VaultCrypto.newVaultKey()
        val protected = VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey("correct horse".toCharArray(), salt, params), vaultKey)

        val unwrapped = VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey("correct horse".toCharArray(), salt, params), protected)

        assertArrayEquals(vaultKey.encoded, unwrapped.encoded)
    }

    @Test
    fun `wrong master password is detected`() {
        val protected = VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey("right".toCharArray(), salt, params), VaultCrypto.newVaultKey())

        assertThrows(AEADBadTagException::class.java) {
            VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey("wrong".toCharArray(), salt, params), protected)
        }
    }

    @Test
    fun `argon2id matches the RFC 9106 reference implementation`() {
        // Эталон получен утилитой argon2 (libargon2): echo -n password | argon2 somesalt16bytes! -id -t 2 -k 64 -p 1 -l 32 -r
        val key = VaultCrypto.deriveMasterKey(
            "password".toCharArray(),
            VaultCrypto.b64("somesalt16bytes!".toByteArray()),
            KdfParams(VaultCrypto.KDF_ARGON2ID, iterations = 2, memoryKiB = 64, parallelism = 1),
        )
        assertEquals(ARGON2_REFERENCE, key.encoded.joinToString("") { "%02x".format(it) })
    }

    @Test
    fun `legacy pbkdf2 vaults still open`() {
        val params = KdfParams(VaultCrypto.KDF_PBKDF2, iterations = 1000)
        val vaultKey = VaultCrypto.newVaultKey()
        val protected = VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey("pwd".toCharArray(), salt, params), vaultKey)

        assertArrayEquals(vaultKey.encoded, VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey("pwd".toCharArray(), salt, params), protected).encoded)
    }

    @Test
    fun `items round trip and are bound to their id`() {
        val key = VaultCrypto.newVaultKey()
        val data = VaultCrypto.encryptItem(key, "item-1", "secret".toByteArray())

        assertArrayEquals("secret".toByteArray(), VaultCrypto.decryptItem(key, "item-1", data))
        // Тот же шифротекст под другим id не расшифруется.
        assertThrows(AEADBadTagException::class.java) { VaultCrypto.decryptItem(key, "item-2", data) }
        // Каждое шифрование со своим IV.
        assertNotEquals(data, VaultCrypto.encryptItem(key, "item-1", "secret".toByteArray()))
    }

    private companion object {
        const val ARGON2_REFERENCE = "ac09230651f4855141b3cdfca3e77a7ab1a70d300bbd4a78cf9bb658333e4abe"
    }
}
