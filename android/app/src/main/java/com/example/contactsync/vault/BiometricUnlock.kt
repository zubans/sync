package com.example.contactsync.vault

import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import kotlinx.coroutines.suspendCancellableCoroutine
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec
import kotlin.coroutines.resume

/**
 * Разблокировка по отпечатку. Ключ хранилища шифруется ключом Android Keystore,
 * который выдаёт шифр только после биометрической проверки и аннулируется при добавлении нового отпечатка.
 */
class BiometricUnlock(private val context: Context) {

    private val prefs = context.getSharedPreferences("vault_biometric", Context.MODE_PRIVATE)

    val isAvailable: Boolean
        get() = BiometricManager.from(context).canAuthenticate(BIOMETRIC_STRONG) == BiometricManager.BIOMETRIC_SUCCESS

    val isEnabled: Boolean get() = prefs.contains(KEY_DATA)

    /** Шифр для включения: после проверки отпечатка им шифруется ключ хранилища. */
    fun encryptCipher(): Cipher {
        deleteKeystoreKey()
        return Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.ENCRYPT_MODE, createKeystoreKey()) }
    }

    fun store(authenticated: Cipher, vaultKey: SecretKey) {
        val data = authenticated.doFinal(vaultKey.encoded)
        prefs.edit()
            .putString(KEY_IV, VaultCrypto.b64(authenticated.iv))
            .putString(KEY_DATA, VaultCrypto.b64(data))
            .apply()
    }

    /** Шифр для разблокировки; null — биометрия выключена или ключ аннулирован (новый отпечаток). */
    fun decryptCipher(): Cipher? {
        val iv = prefs.getString(KEY_IV, null) ?: return null
        val key = keystore().getKey(ALIAS, null) as? SecretKey ?: return null.also { disable() }
        return try {
            Cipher.getInstance(TRANSFORMATION).apply { init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(128, VaultCrypto.unb64(iv))) }
        } catch (e: KeyPermanentlyInvalidatedException) {
            disable()
            null
        }
    }

    fun open(authenticated: Cipher): SecretKey =
        SecretKeySpec(authenticated.doFinal(VaultCrypto.unb64(prefs.getString(KEY_DATA, null)!!)), "AES")

    fun disable() {
        prefs.edit().clear().apply()
        deleteKeystoreKey()
    }

    private fun createKeystoreKey(): SecretKey {
        val spec = KeyGenParameterSpec.Builder(ALIAS, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT)
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true)
            .apply {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                    setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
                }
            }
            .build()
        return KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, KEYSTORE).run {
            init(spec)
            generateKey()
        }
    }

    private fun deleteKeystoreKey() {
        runCatching { keystore().deleteEntry(ALIAS) }
    }

    private fun keystore() = KeyStore.getInstance(KEYSTORE).apply { load(null) }

    companion object {
        private const val KEYSTORE = "AndroidKeyStore"
        private const val ALIAS = "vault_biometric_key"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val KEY_IV = "iv"
        private const val KEY_DATA = "data"
    }
}

/** Показывает системный диалог отпечатка. Возвращает разблокированный шифр или null, если отменили. */
suspend fun FragmentActivity.authenticate(cipher: Cipher, title: String): Cipher? =
    suspendCancellableCoroutine { cont ->
        val prompt = BiometricPrompt(
            this,
            ContextCompat.getMainExecutor(this),
            object : BiometricPrompt.AuthenticationCallback() {
                override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                    if (cont.isActive) cont.resume(result.cryptoObject?.cipher)
                }

                override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                    if (cont.isActive) cont.resume(null)
                }
            },
        )
        prompt.authenticate(
            BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setNegativeButtonText("Мастер-пароль")
                .setAllowedAuthenticators(BIOMETRIC_STRONG)
                .build(),
            BiometricPrompt.CryptoObject(cipher),
        )
        cont.invokeOnCancellation { prompt.cancelAuthentication() }
    }
