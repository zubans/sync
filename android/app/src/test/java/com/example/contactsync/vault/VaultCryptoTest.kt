package com.example.contactsync.vault

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertNotEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import javax.crypto.AEADBadTagException

class VaultCryptoTest {

    // В тестах — меньше итераций, чтобы не ждать; сами вычисления те же.
    private val iterations = 1000
    private val salt = VaultCrypto.newSalt()

    @Test
    fun `vault key survives wrap and unwrap with the right password`() {
        val vaultKey = VaultCrypto.newVaultKey()
        val protected = VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey("correct horse".toCharArray(), salt, iterations), vaultKey)

        val unwrapped = VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey("correct horse".toCharArray(), salt, iterations), protected)

        assertArrayEquals(vaultKey.encoded, unwrapped.encoded)
    }

    @Test
    fun `wrong master password is detected`() {
        val protected = VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey("right".toCharArray(), salt, iterations), VaultCrypto.newVaultKey())

        assertThrows(AEADBadTagException::class.java) {
            VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey("wrong".toCharArray(), salt, iterations), protected)
        }
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
}
