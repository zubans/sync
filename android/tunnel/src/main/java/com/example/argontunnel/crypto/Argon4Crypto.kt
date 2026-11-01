package com.example.argontunnel.crypto

import kotlinx.serialization.Serializable
import org.bouncycastle.crypto.generators.Argon2BytesGenerator
import org.bouncycastle.crypto.params.Argon2Parameters
import java.nio.ByteBuffer
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** Запрос на установку туннеля и выделение виртуального IP-адреса. */
@Serializable
data class HandshakeRequest(
    /** Постоянный идентификатор установки клиента для сохранения выданного IP при переподключении. */
    val clientId: String,
    /** Желаемый виртуальный IP-адрес в подсети туннеля (необязательно). */
    val requestedIp: String? = null,
    /** Список пакетов приложений, трафик которых заворачивается в туннель. */
    val apps: List<String> = emptyList(),
    /** Unix-время отправки запроса (в секундах). */
    val timestamp: Long = System.currentTimeMillis() / 1000,
)

/** Параметры сессии и маршрутизации, выданные сервером Ubuntu после проверки мастер-пароля. */
@Serializable
data class HandshakeResponse(
    /** Выделенный клиенту виртуальный IPv4-адрес внутри туннеля (например, `10.44.0.2`). */
    val assignedIp: String,
    /** Виртуальный IPv4-адрес сервера на интерфейсе `argon0` (например, `10.44.0.1`). */
    val serverIp: String,
    /** Длина маски подсети туннеля (например, `24`). */
    val subnetCidr: Int,
    /** MTU виртуального интерфейса `argon0` с учётом заголовка и тега Argon4. */
    val mtu: Int = 1380,
    /** DNS-серверы, назначаемые интерфейсу туннеля. */
    val dns: List<String> = emptyList(),
    /** Подсети CIDR, заворачиваемые в туннель. */
    val routes: List<String> = emptyList(),
    /** Серверное Unix-время ответа (в секундах). */
    val serverTime: Long = 0,
)

/** Параметры вывода ключа Argon2id (RFC 9106, по умолчанию по рекомендации OWASP: 19 МиБ, 2 прохода, 1 поток). */
data class Argon4Params(
    /** Число проходов по памяти (time cost). */
    val iterations: Int = 2,
    /** Объём памяти в КиБ (19456 КиБ = 19 МиБ). */
    val memoryKiB: Int = 19456,
    /** Степень параллелизма (число полос). */
    val parallelism: Int = 1,
)

/** Результат проверки и расшифровки кадра Argon4. */
data class DecryptedPacket(
    /** Тип кадра (`FRAME_HANDSHAKE_REQ`, `FRAME_HANDSHAKE_RESP`, `FRAME_DATA`, `FRAME_KEEPALIVE`). */
    val frameType: Byte,
    /** 16-байтная соль Argon2id из заголовка пакета. */
    val salt: ByteArray,
    /** Расшифрованная полезная нагрузка (IP-пакет или JSON рукопожатия). */
    val plaintext: ByteArray,
)

/**
 * Криптографическая надстройка протокола Argon4 (Argon2id + AES-256-GCM).
 *
 * Формат пакета по проводу (совместим с серверным демоном на Ubuntu):
 *   [0]      Версия протокола (0x04)
 *   [1]      Тип кадра (0x01 HandshakeReq, 0x02 HandshakeResp, 0x03 Data, 0x04 Keepalive)
 *   [2..18]  Соль Argon2id (16 байт)
 *   [18..30] Nonce / IV (12 байт)
 *   [30..]   AES-256-GCM Ciphertext + Tag (16 байт), AAD = "argon4-tun:" || version || frameType || salt
 */
object Argon4Crypto {

    const val PROTOCOL_VERSION: Byte = 0x04

    const val FRAME_HANDSHAKE_REQ: Byte = 0x01
    const val FRAME_HANDSHAKE_RESP: Byte = 0x02
    const val FRAME_DATA: Byte = 0x03
    const val FRAME_KEEPALIVE: Byte = 0x04

    const val KEY_BYTES = 32
    const val SALT_BYTES = 16
    const val NONCE_BYTES = 12
    const val TAG_BYTES = 16
    const val TAG_BITS = 128
    const val HEADER_BYTES = 1 + 1 + SALT_BYTES + NONCE_BYTES

    private val AAD_PREFIX = "argon4-tun:".toByteArray(Charsets.UTF_8)
    private val random = SecureRandom()

    fun randomBytes(size: Int): ByteArray = ByteArray(size).also(random::nextBytes)

    fun newSalt(): ByteArray = randomBytes(SALT_BYTES)

    fun deriveKey(masterPassword: String, salt: ByteArray, params: Argon4Params = Argon4Params()): SecretKey {
        require(salt.size == SALT_BYTES) { "Соль должна быть длиной $SALT_BYTES байт" }
        val generator = Argon2BytesGenerator().apply {
            init(
                Argon2Parameters.Builder(Argon2Parameters.ARGON2_id)
                    .withVersion(Argon2Parameters.ARGON2_VERSION_13)
                    .withSalt(salt)
                    .withIterations(params.iterations)
                    .withMemoryAsKB(params.memoryKiB)
                    .withParallelism(params.parallelism)
                    .build(),
            )
        }
        val keyBytes = ByteArray(KEY_BYTES)
        val passwordChars = masterPassword.toCharArray()
        try {
            generator.generateBytes(passwordChars, keyBytes)
        } finally {
            passwordChars.fill('\u0000')
        }
        return SecretKeySpec(keyBytes, "AES")
    }

    fun buildAad(version: Byte, frameType: Byte, salt: ByteArray): ByteArray {
        val aad = ByteArray(AAD_PREFIX.size + 2 + salt.size)
        System.arraycopy(AAD_PREFIX, 0, aad, 0, AAD_PREFIX.size)
        aad[AAD_PREFIX.size] = version
        aad[AAD_PREFIX.size + 1] = frameType
        System.arraycopy(salt, 0, aad, AAD_PREFIX.size + 2, salt.size)
        return aad
    }

    fun sealPacket(
        frameType: Byte,
        salt: ByteArray,
        key: SecretKey,
        plaintext: ByteArray,
        offset: Int = 0,
        length: Int = plaintext.size,
    ): ByteArray {
        require(salt.size == SALT_BYTES) { "Некорректная длина соли: ${salt.size}" }
        val nonce = randomBytes(NONCE_BYTES)
        return sealWithNonce(frameType, salt, key, nonce, plaintext, offset, length)
    }

    fun sealPacketWithSeq(
        frameType: Byte,
        salt: ByteArray,
        key: SecretKey,
        noncePrefix4: ByteArray,
        seq: Long,
        plaintext: ByteArray,
        offset: Int = 0,
        length: Int = plaintext.size,
    ): ByteArray {
        require(salt.size == SALT_BYTES) { "Некорректная длина соли: ${salt.size}" }
        require(noncePrefix4.size == 4) { "Некорректная длина префикса nonce: ${noncePrefix4.size}" }
        val nonce = ByteArray(NONCE_BYTES)
        System.arraycopy(noncePrefix4, 0, nonce, 0, 4)
        ByteBuffer.wrap(nonce, 4, 8).putLong(seq)
        return sealWithNonce(frameType, salt, key, nonce, plaintext, offset, length)
    }

    private fun sealWithNonce(
        frameType: Byte,
        salt: ByteArray,
        key: SecretKey,
        nonce: ByteArray,
        plaintext: ByteArray,
        offset: Int,
        length: Int,
    ): ByteArray {
        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(TAG_BITS, nonce))
        cipher.updateAAD(buildAad(PROTOCOL_VERSION, frameType, salt))

        val out = ByteArray(HEADER_BYTES + length + TAG_BYTES)
        out[0] = PROTOCOL_VERSION
        out[1] = frameType
        System.arraycopy(salt, 0, out, 2, SALT_BYTES)
        System.arraycopy(nonce, 0, out, 2 + SALT_BYTES, NONCE_BYTES)
        cipher.doFinal(plaintext, offset, length, out, HEADER_BYTES)
        return out
    }

    /**
     * Расшифровывает и проверяет подлинность пакета Argon4.
     * Бросает [javax.crypto.AEADBadTagException], если мастер-пароль неверный или пакет изменён.
     */
    fun openPacket(packet: ByteArray, length: Int = packet.size, key: SecretKey): DecryptedPacket {
        require(length >= HEADER_BYTES + TAG_BYTES) { "Пакет туннеля слишком короткий: $length" }
        val version = packet[0]
        require(version == PROTOCOL_VERSION) { "Неподдерживаемая версия протокола: $version" }
        val frameType = packet[1]
        val salt = packet.copyOfRange(2, 2 + SALT_BYTES)

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, packet, 2 + SALT_BYTES, NONCE_BYTES))
        cipher.updateAAD(buildAad(version, frameType, salt))
        val plaintext = cipher.doFinal(packet, HEADER_BYTES, length - HEADER_BYTES)
        return DecryptedPacket(frameType, salt, plaintext)
    }
}
