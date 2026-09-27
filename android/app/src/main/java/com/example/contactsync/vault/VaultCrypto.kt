package com.example.contactsync.vault

import java.security.SecureRandom
import java.util.Base64
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.SecretKeyFactory
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.PBEKeySpec
import javax.crypto.spec.SecretKeySpec

/**
 * Криптография хранилища.
 *
 * - мастер-ключ = PBKDF2-HMAC-SHA256(мастер-пароль, соль, итерации); из устройства не уходит;
 * - ключ хранилища — случайный AES-256; на сервере лежит зашифрованным мастер-ключом («обёртка»);
 * - записи шифруются ключом хранилища AES-256-GCM, id записи идёт в AAD —
 *   шифротекст одной записи нельзя незаметно подставить на место другой.
 *
 * Формат шифротекста: base64(iv[12] || ciphertext || tag[16]).
 */
object VaultCrypto {

    const val KDF_ALGORITHM = "pbkdf2-sha256"
    const val KDF_ITERATIONS = 600_000

    private const val IV_BYTES = 12
    private const val TAG_BITS = 128
    private val KEY_AAD = "contactsync:vault-key:v1".toByteArray()
    private val random = SecureRandom()

    fun randomBytes(size: Int): ByteArray = ByteArray(size).also(random::nextBytes)

    fun newSalt(): String = b64(randomBytes(16))

    fun newVaultKey(): SecretKey = SecretKeySpec(randomBytes(32), "AES")

    fun deriveMasterKey(password: CharArray, saltB64: String, iterations: Int): SecretKey {
        val spec = PBEKeySpec(password, unb64(saltB64), iterations, 256)
        try {
            val bytes = SecretKeyFactory.getInstance("PBKDF2WithHmacSHA256").generateSecret(spec).encoded
            return SecretKeySpec(bytes, "AES")
        } finally {
            spec.clearPassword()
        }
    }

    fun wrapKey(masterKey: SecretKey, vaultKey: SecretKey): String = encrypt(masterKey, vaultKey.encoded, KEY_AAD)

    /** Бросает [javax.crypto.AEADBadTagException], если мастер-пароль неверный. */
    fun unwrapKey(masterKey: SecretKey, protectedKey: String): SecretKey =
        SecretKeySpec(decrypt(masterKey, protectedKey, KEY_AAD), "AES")

    fun encryptItem(vaultKey: SecretKey, itemId: String, plaintext: ByteArray): String =
        encrypt(vaultKey, plaintext, itemAad(itemId))

    fun decryptItem(vaultKey: SecretKey, itemId: String, data: String): ByteArray =
        decrypt(vaultKey, data, itemAad(itemId))

    fun encrypt(key: SecretKey, plaintext: ByteArray, aad: ByteArray): String {
        val iv = randomBytes(IV_BYTES)
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(TAG_BITS, iv))
        cipher.updateAAD(aad)
        return b64(iv + cipher.doFinal(plaintext))
    }

    fun decrypt(key: SecretKey, data: String, aad: ByteArray): ByteArray {
        val bytes = unb64(data)
        require(bytes.size > IV_BYTES) { "Повреждённый шифротекст" }
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, bytes, 0, IV_BYTES))
        cipher.updateAAD(aad)
        return cipher.doFinal(bytes, IV_BYTES, bytes.size - IV_BYTES)
    }

    fun b64(bytes: ByteArray): String = Base64.getEncoder().encodeToString(bytes)

    fun unb64(value: String): ByteArray = Base64.getDecoder().decode(value)

    private fun itemAad(itemId: String) = "contactsync:item:v1:$itemId".toByteArray()
}
