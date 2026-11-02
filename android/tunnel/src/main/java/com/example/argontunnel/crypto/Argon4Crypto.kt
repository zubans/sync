package com.example.argontunnel.crypto

import kotlinx.serialization.Serializable
import org.bouncycastle.crypto.generators.Argon2BytesGenerator
import org.bouncycastle.crypto.params.Argon2Parameters
import java.nio.ByteBuffer
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** Запрос на установку туннеля и выделение виртуального IP-адреса. */
@Serializable
data class HandshakeRequest(
    /** Постоянный идентификатор установки клиента для сохранения выданного IP при переподключении. */
    val clientId: String,
    /** Токен привязки clientId к clientSecret (hex‑encoded HMAC-SHA256, 16 байт). */
    val clientAuth: String? = null,
    /** Публичный ключ X25519 клиента в hex для прямой секретности сессии (PFS). */
    val clientPub: String = "",
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
    /** Публичный ключ X25519 сервера в hex для сессионного обмена ECDH. */
    val serverPub: String = "",
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
    /** Монотонный номер пакета seq (начиная с 1). */
    val seq: Long,
    /** 16-байтная соль Argon2id из заголовка пакета. */
    val salt: ByteArray,
    /** Расшифрованная полезная нагрузка (IP-пакет или JSON рукопожатия). */
    val plaintext: ByteArray,
)

/** 64-пакетное скользящее окно защиты от повтора (anti-replay) пакетов. */
class ReplayWindow {
    private var maxSeq: Long = 0L
    private var bitmap: Long = 0L

    @Synchronized
    fun accept(seq: Long): Boolean {
        if (seq <= 0L) return false
        if (seq > maxSeq) {
            val shift = seq - maxSeq
            bitmap = if (shift >= 64) 1L else (bitmap shl shift.toInt()) or 1L
            maxSeq = seq
            return true
        }
        val diff = (maxSeq - seq).toInt()
        if (diff >= 64) return false
        val mask = 1L shl diff
        if ((bitmap and mask) != 0L) return false
        bitmap = bitmap or mask
        return true
    }
}

/**
 * Криптографическая надстройка протокола Argon4 (Argon2id + AES-256-GCM + PSK header obfuscation).
 *
 * Формат пакета на проводе (полностью неотличим от случайного шума):
 *   [0..16]   Соль сессии (16 случайных байт)
 *   [16..30]  Замаскированные метаданные: (Version[1] || FrameType[1] || AuthCheck[4] || Seq[8]) XOR HMAC(psk, salt)
 *   [30..]    Шифротекст AES-256-GCM + тег аутентификации (16 байт)
 */
object Argon4Crypto {

    const val PROTOCOL_VERSION: Byte = 0x04

    const val FRAME_HANDSHAKE_REQ: Byte = 0x01
    const val FRAME_HANDSHAKE_RESP: Byte = 0x02
    const val FRAME_DATA: Byte = 0x03
    const val FRAME_KEEPALIVE: Byte = 0x04

    const val KEY_BYTES = 32
    const val SALT_BYTES = 16
    const val META_BYTES = 14
    const val NONCE_BYTES = 12
    const val TAG_BYTES = 16
    const val TAG_BITS = 128
    const val HEADER_BYTES = SALT_BYTES + META_BYTES

    private val PSK_DOMAIN_SALT = "argon4-psk-v4-01".toByteArray(Charsets.UTF_8)
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

    /** Выводит 32-байтный PSK из мастер-пароля для защиты заголовков и фильтрации DoS за доли микросекунды. */
    fun derivePSK(masterPassword: String, params: Argon4Params = Argon4Params()): ByteArray {
        val sk = deriveKey(masterPassword, PSK_DOMAIN_SALT, params)
        return sk.encoded
    }

    /** Вычисляет 16-байтный токен привязки clientId к clientSecret через HMAC-SHA256(psk). */
    fun clientAuthToken(psk: ByteArray, clientId: String, clientSecret: String): String {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(psk, "HmacSHA256"))
        mac.update("argon4-cid:".toByteArray(Charsets.UTF_8))
        mac.update(clientId.toByteArray(Charsets.UTF_8))
        mac.update(":".toByteArray(Charsets.UTF_8))
        mac.update(clientSecret.toByteArray(Charsets.UTF_8))
        val raw = mac.doFinal()
        val hex = StringBuilder(32)
        for (i in 0 until 16) {
            hex.append("%02x".format(raw[i]))
        }
        return hex.toString()
    }

    private fun computeHeaderMask(psk: ByteArray, salt: ByteArray): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(psk, "HmacSHA256"))
        mac.update("argon4-hdr:".toByteArray(Charsets.UTF_8))
        mac.update(salt)
        return mac.doFinal()
    }

    private fun computeHeaderCheck(psk: ByteArray, salt: ByteArray, version: Byte, frameType: Byte, seqBytes: ByteArray): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(psk, "HmacSHA256"))
        mac.update("argon4-chk:".toByteArray(Charsets.UTF_8))
        mac.update(salt)
        mac.update(byteArrayOf(version, frameType))
        mac.update(seqBytes)
        val sum = mac.doFinal()
        return sum.copyOfRange(0, 4)
    }

    fun buildAad(version: Byte, frameType: Byte, salt: ByteArray, seq: Long): ByteArray {
        val buf = ByteBuffer.allocate(AAD_PREFIX.size + 2 + salt.size + 8)
        buf.put(AAD_PREFIX)
        buf.put(version)
        buf.put(frameType)
        buf.put(salt)
        buf.putLong(seq)
        return buf.array()
    }

    /** Шифрует пакет с обфускацией заголовка и монотонным seq. */
    fun sealPacketWithSeq(
        frameType: Byte,
        salt: ByteArray,
        psk: ByteArray,
        key: SecretKey,
        seq: Long,
        plaintext: ByteArray,
        offset: Int = 0,
        length: Int = plaintext.size,
    ): ByteArray {
        require(salt.size == SALT_BYTES) { "Некорректная длина соли: ${salt.size}" }
        require(psk.size == KEY_BYTES) { "Некорректная длина psk: ${psk.size}" }
        require(seq >= 1L) { "Счётчик пакета seq должен быть >= 1" }

        val seqBytes = ByteBuffer.allocate(8).putLong(seq).array()
        val chk = computeHeaderCheck(psk, salt, PROTOCOL_VERSION, frameType, seqBytes)

        val meta = ByteArray(META_BYTES)
        meta[0] = PROTOCOL_VERSION
        meta[1] = frameType
        System.arraycopy(chk, 0, meta, 2, 4)
        System.arraycopy(seqBytes, 0, meta, 6, 8)

        val nonce = ByteArray(NONCE_BYTES)
        System.arraycopy(meta, 2, nonce, 0, NONCE_BYTES)

        val mask = computeHeaderMask(psk, salt)
        val out = ByteArray(HEADER_BYTES + length + TAG_BYTES)
        System.arraycopy(salt, 0, out, 0, SALT_BYTES)
        for (i in 0 until META_BYTES) {
            out[SALT_BYTES + i] = (meta[i].toInt() xor mask[i].toInt()).toByte()
        }

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(TAG_BITS, nonce))
        cipher.updateAAD(buildAad(PROTOCOL_VERSION, frameType, salt, seq))
        cipher.doFinal(plaintext, offset, length, out, HEADER_BYTES)
        return out
    }

    /** Расшифровывает пакет Argon4 с проверкой маскированного заголовка и тега GCM. */
    fun openPacket(packet: ByteArray, length: Int = packet.size, psk: ByteArray, key: SecretKey): DecryptedPacket {
        require(length >= HEADER_BYTES + TAG_BYTES) { "Пакет туннеля слишком короткий: $length" }
        require(psk.size == KEY_BYTES) { "Некорректная длина psk: ${psk.size}" }

        val salt = packet.copyOfRange(0, SALT_BYTES)
        val mask = computeHeaderMask(psk, salt)

        val meta = ByteArray(META_BYTES)
        for (i in 0 until META_BYTES) {
            meta[i] = (packet[SALT_BYTES + i].toInt() xor mask[i].toInt()).toByte()
        }

        val version = meta[0]
        require(version == PROTOCOL_VERSION) { "Неподдерживаемая версия протокола: $version" }
        val frameType = meta[1]

        val seqBytes = meta.copyOfRange(6, 14)
        val expectedChk = computeHeaderCheck(psk, salt, version, frameType, seqBytes)
        for (i in 0 until 4) {
            if (meta[2 + i] != expectedChk[i]) {
                throw SecurityException("Ошибка проверки заголовка пакета по мастер-паролю (header auth failed)")
            }
        }

        val seq = ByteBuffer.wrap(seqBytes).long
        val nonce = ByteArray(NONCE_BYTES)
        System.arraycopy(meta, 2, nonce, 0, NONCE_BYTES)

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, nonce))
        cipher.updateAAD(buildAad(version, frameType, salt, seq))

        val plaintext = cipher.doFinal(packet, HEADER_BYTES, length - HEADER_BYTES)
        return DecryptedPacket(frameType, seq, salt, plaintext)
    }
}
