package com.example.argontunnel.crypto

import kotlinx.serialization.Serializable
import org.bouncycastle.crypto.generators.Argon2BytesGenerator
import org.bouncycastle.crypto.params.Argon2Parameters
import org.bouncycastle.crypto.params.X25519PrivateKeyParameters
import org.bouncycastle.crypto.params.X25519PublicKeyParameters
import java.nio.ByteBuffer
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.Mac
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.SecretKeySpec

/** Категория ошибки разбора или проверки кадра Argon4. */
enum class Argon4ErrorKind {
    PACKET_TOO_SHORT,
    BAD_VERSION,
    DOWNGRADE_REJECTED,
    HEADER_AUTH_FAILED,
    AUTH_FAILED,
    INVALID_PADDING,
    INVALID_ENVELOPE,
    TRAILING_DATA,
    MTU_EXCEEDED,
    PROFILE_UNSUPPORTED,
}

/** Исключение протокола Argon4 с явным типом нарушения границ, версии или padding. */
class Argon4ProtocolException(
    val kind: Argon4ErrorKind,
    message: String,
) : SecurityException(message)

/** Конфигурация распределения переменной длины padding и ограничения внешнего path MTU. */
data class PaddingConfig(
    val profile: String = Argon4Crypto.PADDING_PROFILE_BALANCED,
    val minPad: Int = 8,
    val maxPad: Int = 96,
    val smallThreshold: Int = 160,
    val smallMinPad: Int = 16,
    val smallMaxPad: Int = 192,
    val pathMtu: Int = Argon4Crypto.DEFAULT_PATH_MTU,
)

/** Параметры шифрования исходящего кадра Argon4 v4/v5. */
data class SealOptions(
    val version: Byte = Argon4Crypto.PROTOCOL_VERSION_V5,
    val padding: PaddingConfig = PaddingConfig(),
    val useFixedPad: Boolean = false,
    val fixedPadding: Int = 0,
    val samplePad: ((payloadLen: Int, maxOuterBytes: Int) -> Int)? = null,
    val maxOuterBytes: Int = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
)

/** Политика проверки версии, совместимости и лимитов MTU при чтении кадра Argon4. */
data class OpenOptions(
    val expectedVersion: Byte = 0,
    val allowV4: Boolean = true,
    val maxOuterBytes: Int = Argon4Crypto.DEFAULT_MAX_OUTER_UDP_SIZE,
    val maxPayloadBytes: Int = 0,
)

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
    /** Предпочитаемая версия протокола (`4` или `5`). */
    val protocolVersion: Int = 0,
    /** Поддерживаемые возможности клиента (например, `["pad-v5"]`). */
    val capabilities: List<String>? = null,
    /** Запрошенный профиль padding (`"balanced"`, `"compact"`, `"wide"`, `"custom"`). */
    val paddingProfile: String? = null,
    /** Запрошенный поведенческий профиль трафика (`"baseline"`, `"video-call"`). */
    val behaviorProfile: String? = null,
    /** Версия схемы поведенческого профиля (`1` для `profile-v1`, `0` = выкл.). */
    val profileVersion: Int = 0,
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
    /** Согласованная сервером версия протокола (`4` или `5`). */
    val protocolVersion: Int = 0,
    /** Поддерживаемые возможности сервера. */
    val capabilities: List<String>? = null,
    /** Согласованный профиль padding. */
    val paddingProfile: String? = null,
    /** Подтверждённый сервером поведенческий профиль трафика. */
    val behaviorProfile: String? = null,
    /** Подтверждённая версия поведенческого профиля. */
    val profileVersion: Int = 0,
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
    /** Фактическая версия протокола из аутентифицированного заголовка (`0x04` или `0x05`). */
    val version: Byte = Argon4Crypto.PROTOCOL_VERSION_V5,
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

    const val PROTOCOL_VERSION_V4: Byte = 0x04
    const val PROTOCOL_VERSION_V5: Byte = 0x05
    const val PROTOCOL_VERSION: Byte = PROTOCOL_VERSION_V5

    const val PADDING_PROFILE_BALANCED = "balanced"
    const val PADDING_PROFILE_COMPACT = "compact"
    const val PADDING_PROFILE_WIDE = "wide"
    const val PADDING_PROFILE_CUSTOM = "custom"

    const val ENVELOPE_HEADER_V5_BYTES = 4
    const val DEFAULT_PATH_MTU = 1500
    const val IPV4_UDP_OVERHEAD = 28
    const val DEFAULT_MAX_OUTER_UDP_SIZE = DEFAULT_PATH_MTU - IPV4_UDP_OVERHEAD
    const val MIN_PATH_MTU = 576
    const val MAX_PATH_MTU = 9000
    const val MAX_PAYLOAD_LIMIT = 65535 - ENVELOPE_HEADER_V5_BYTES
    const val MAX_TCP_FRAME_SIZE = 65535

    const val FRAME_HANDSHAKE_REQ: Byte = 0x01
    const val FRAME_HANDSHAKE_RESP: Byte = 0x02
    const val FRAME_DATA: Byte = 0x03
    const val FRAME_KEEPALIVE: Byte = 0x04
    const val FRAME_BATCH: Byte = 0x05

    const val KEY_BYTES = 32
    const val SALT_BYTES = 16
    const val META_BYTES = 14
    const val NONCE_BYTES = 12
    const val TAG_BYTES = 16
    const val TAG_BITS = 128
    const val HEADER_BYTES = SALT_BYTES + META_BYTES
    const val OVERHEAD_V4 = HEADER_BYTES + TAG_BYTES
    const val OVERHEAD_V5 = HEADER_BYTES + ENVELOPE_HEADER_V5_BYTES + TAG_BYTES

    /** Разбранный и аутентифицированный по PSK заголовок кадра Argon4. */
    data class ParsedHeader(
        val version: Byte,
        val frameType: Byte,
        val salt: ByteArray,
        val nonce: ByteArray,
        val seq: Long,
        val payloadOffset: Int,
        val ciphertextLength: Int,
    )

    fun defaultPaddingConfig(pathMtu: Int = DEFAULT_PATH_MTU): PaddingConfig =
        PaddingConfig(
            profile = PADDING_PROFILE_BALANCED,
            minPad = 8,
            maxPad = 96,
            smallThreshold = 160,
            smallMinPad = 16,
            smallMaxPad = 192,
            pathMtu = pathMtu,
        )

    fun presetPaddingConfig(name: String, pathMtu: Int = DEFAULT_PATH_MTU): PaddingConfig {
        val mtu = if (pathMtu == 0) DEFAULT_PATH_MTU else pathMtu
        return when (name.trim().lowercase()) {
            "", PADDING_PROFILE_BALANCED -> defaultPaddingConfig(mtu)
            PADDING_PROFILE_COMPACT -> PaddingConfig(
                profile = PADDING_PROFILE_COMPACT,
                minPad = 4,
                maxPad = 32,
                smallThreshold = 120,
                smallMinPad = 8,
                smallMaxPad = 64,
                pathMtu = mtu,
            )
            PADDING_PROFILE_WIDE -> PaddingConfig(
                profile = PADDING_PROFILE_WIDE,
                minPad = 16,
                maxPad = 192,
                smallThreshold = 256,
                smallMinPad = 32,
                smallMaxPad = 320,
                pathMtu = mtu,
            )
            else -> throw IllegalArgumentException("Неизвестный профиль padding: $name")
        }
    }

    fun maxOuterBytesForPathMtu(pathMtu: Int): Int {
        if (pathMtu <= 0) return DEFAULT_MAX_OUTER_UDP_SIZE
        if (pathMtu <= IPV4_UDP_OVERHEAD) return 0
        return pathMtu - IPV4_UDP_OVERHEAD
    }

    fun normalizePaddingConfig(cfg: PaddingConfig, isUdp: Boolean = true): PaddingConfig {
        val mtu = if (cfg.pathMtu == 0) DEFAULT_PATH_MTU else cfg.pathMtu
        if (mtu < MIN_PATH_MTU || mtu > MAX_PATH_MTU) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Недопустимый path MTU $mtu (допустимо $MIN_PATH_MTU..$MAX_PATH_MTU)",
            )
        }
        if (cfg.minPad < 0 || cfg.maxPad < 0 || cfg.smallThreshold < 0 || cfg.smallMinPad < 0 || cfg.smallMaxPad < 0) {
            throw IllegalArgumentException("Отрицательные параметры padding недопустимы")
        }
        val profile = cfg.profile.trim().lowercase()
        val hasCustomBounds = cfg.minPad != 0 || cfg.maxPad != 0 || cfg.smallThreshold != 0 ||
            cfg.smallMinPad != 0 || cfg.smallMaxPad != 0
        val normalized = when {
            profile.isEmpty() && !hasCustomBounds -> defaultPaddingConfig(mtu)
            profile == PADDING_PROFILE_CUSTOM || (profile.isEmpty() && hasCustomBounds) -> cfg.copy(
                profile = PADDING_PROFILE_CUSTOM,
                pathMtu = mtu,
            )
            else -> presetPaddingConfig(profile, mtu)
        }
        if (normalized.maxPad <= normalized.minPad) {
            throw IllegalArgumentException(
                "Padding должен иметь переменную длину (maxPad=${normalized.maxPad} <= minPad=${normalized.minPad})",
            )
        }
        if (normalized.smallThreshold > 0 && normalized.smallMaxPad <= normalized.smallMinPad) {
            throw IllegalArgumentException(
                "Padding коротких пакетов должен иметь переменную длину " +
                    "(smallMaxPad=${normalized.smallMaxPad} <= smallMinPad=${normalized.smallMinPad})",
            )
        }
        if (isUdp) {
            val maxPadBudget = maxOuterBytesForPathMtu(normalized.pathMtu) - OVERHEAD_V5
            if (normalized.minPad > maxPadBudget || normalized.maxPad > maxPadBudget ||
                normalized.smallMinPad > maxPadBudget || normalized.smallMaxPad > maxPadBudget
            ) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.MTU_EXCEEDED,
                    "Параметры padding превышают доступный бюджет path MTU (${normalized.pathMtu})",
                )
            }
        }
        return normalized
    }

    fun resolvePaddingConfig(cfg: PaddingConfig, isUdp: Boolean = true): PaddingConfig =
        runCatching { normalizePaddingConfig(cfg, isUdp) }.getOrElse { defaultPaddingConfig() }

    fun effectiveTunMtu(requestedMtu: Int, pathMtu: Int, version: Byte): Int {
        val base = if (requestedMtu <= 0) 1380 else requestedMtu
        val outerMax = maxOuterBytesForPathMtu(pathMtu)
        val overhead = if (version == PROTOCOL_VERSION_V4) OVERHEAD_V4 else OVERHEAD_V5
        val maxInner = outerMax - overhead
        return if (maxInner >= MIN_PATH_MTU && base > maxInner) maxInner else base
    }

    fun samplePaddingLen(cfg: PaddingConfig, payloadLen: Int, maxOuterBytes: Int): Int {
        if (payloadLen < 0) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_ENVELOPE, "Отрицательная длина payload: $payloadLen")
        }
        val effectiveOuter = if (maxOuterBytes <= 0) maxOuterBytesForPathMtu(cfg.pathMtu) else maxOuterBytes
        val budget = effectiveOuter - OVERHEAD_V5 - payloadLen
        if (budget < 0 || payloadLen > MAX_PAYLOAD_LIMIT) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Payload ($payloadLen байт) превышает лимит кадра ($effectiveOuter байт)",
            )
        }
        val resolved = resolvePaddingConfig(cfg, effectiveOuter <= DEFAULT_MAX_OUTER_UDP_SIZE)
        var lo = if (payloadLen <= resolved.smallThreshold) resolved.smallMinPad else resolved.minPad
        var hi = if (payloadLen <= resolved.smallThreshold) resolved.smallMaxPad else resolved.maxPad
        if (hi > budget) hi = budget
        if (lo > hi) lo = hi
        if (hi <= lo) return lo
        val span = hi - lo + 1
        return lo + random.nextInt(span)
    }

    fun packEnvelopeV5(
        plaintext: ByteArray,
        offset: Int,
        length: Int,
        padLen: Int,
        maxOuterBytes: Int,
    ): ByteArray {
        if (length < 0 || offset < 0 || offset + length > plaintext.size) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_ENVELOPE, "Некорректный срез полезной нагрузки")
        }
        if (padLen < 0) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_PADDING, "Отрицательная длина padding: $padLen")
        }
        if (length > MAX_PAYLOAD_LIMIT || padLen > 65535) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Превышен предельный размер конверта (payload=$length, pad=$padLen)",
            )
        }
        val totalPlain = ENVELOPE_HEADER_V5_BYTES + length + padLen
        if (totalPlain > 65535) {
            throw Argon4ProtocolException(Argon4ErrorKind.MTU_EXCEEDED, "Конверт превышает 65535 байт ($totalPlain)")
        }
        if (maxOuterBytes > 0 && OVERHEAD_V5 + length + padLen > maxOuterBytes) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Внешний кадр ${OVERHEAD_V5 + length + padLen} байт превышает лимит $maxOuterBytes байт",
            )
        }
        val out = ByteArray(totalPlain)
        out[0] = (length ushr 8).toByte()
        out[1] = length.toByte()
        out[2] = (padLen ushr 8).toByte()
        out[3] = padLen.toByte()
        if (length > 0) {
            System.arraycopy(plaintext, offset, out, ENVELOPE_HEADER_V5_BYTES, length)
        }
        return out
    }

    fun validateEnvelopeV5Lengths(envelopeLen: Int, payloadLen: Int, padLen: Int, maxPayloadLen: Int) {
        if (envelopeLen < ENVELOPE_HEADER_V5_BYTES || payloadLen < 0 || padLen < 0) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Некорректные длины конверта (env=$envelopeLen, payload=$payloadLen, pad=$padLen)",
            )
        }
        if (maxPayloadLen > 0 && payloadLen > maxPayloadLen) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Длина полезной нагрузки $payloadLen превышает предел $maxPayloadLen",
            )
        }
        val expectedLen = ENVELOPE_HEADER_V5_BYTES + payloadLen + padLen
        if (expectedLen > envelopeLen) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Объявленная длина $expectedLen выходит за границы буфера $envelopeLen",
            )
        }
        if (expectedLen < envelopeLen) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.TRAILING_DATA,
                "Обнаружено ${envelopeLen - expectedLen} лишних байт после padding",
            )
        }
    }

    fun unpackEnvelopeV5(envelope: ByteArray, maxPayloadLen: Int = 0): ByteArray {
        if (envelope.size < ENVELOPE_HEADER_V5_BYTES) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Размер расшифрованного конверта ${envelope.size} меньше заголовка",
            )
        }
        val payloadLen = ((envelope[0].toInt() and 0xff) shl 8) or (envelope[1].toInt() and 0xff)
        val padLen = ((envelope[2].toInt() and 0xff) shl 8) or (envelope[3].toInt() and 0xff)
        validateEnvelopeV5Lengths(envelope.size, payloadLen, padLen, maxPayloadLen)

        val padStart = ENVELOPE_HEADER_V5_BYTES + payloadLen
        val padEnd = padStart + padLen
        var nonZero = 0
        for (i in padStart until padEnd) {
            nonZero = nonZero or (envelope[i].toInt() and 0xff)
        }
        if (nonZero != 0) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_PADDING, "Повреждённый padding кадра v5")
        }
        return envelope.copyOfRange(ENVELOPE_HEADER_V5_BYTES, padStart)
    }

    fun capabilitiesForVersion(version: Byte, profileCapable: Boolean = false): List<String>? =
        if (version == PROTOCOL_VERSION_V5) {
            if (profileCapable) listOf("pad-v5", "profile-v1") else listOf("pad-v5")
        } else {
            null
        }

    fun resolveVersion(rawVersion: Int): Byte = when (rawVersion) {
        0, PROTOCOL_VERSION_V5.toInt() -> PROTOCOL_VERSION_V5
        PROTOCOL_VERSION_V4.toInt() -> PROTOCOL_VERSION_V4
        else -> throw Argon4ProtocolException(Argon4ErrorKind.BAD_VERSION, "Неподдерживаемая версия протокола: $rawVersion")
    }

    fun validateHandshakeProtocol(expectedVersion: Byte, response: HandshakeResponse) {
        if (response.protocolVersion == 0) return
        if (expectedVersion == PROTOCOL_VERSION_V5 && response.protocolVersion == PROTOCOL_VERSION_V4.toInt()) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.DOWNGRADE_REJECTED,
                "Обнаружена попытка понижения версии протокола (v5 -> v4)",
            )
        }
        if (response.protocolVersion != expectedVersion.toInt()) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.BAD_VERSION,
                "Неожиданная версия протокола ответа: ${response.protocolVersion}",
            )
        }
    }

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

    /** Эфемерная пара X25519. Приватный ключ должен быть очищен после handshake. */
    data class X25519KeyPair(val privateKey: ByteArray, val publicKey: ByteArray)

    /** Раздельные ключи AES-256-GCM для двух направлений сессии. */
    data class SessionKeys(val c2sKey: SecretKey, val s2cKey: SecretKey)

    /** Генерирует эфемерную пару X25519 для одного handshake. */
    fun generateX25519Keypair(): X25519KeyPair {
        val privateKey = X25519PrivateKeyParameters(random)
        return X25519KeyPair(privateKey.encoded, privateKey.generatePublicKey().encoded)
    }

    /** Вычисляет общий секрет X25519 и отклоняет все-нулевой результат, как crypto/ecdh в Go. */
    fun computeX25519Shared(privateKey: ByteArray, peerPublicKey: ByteArray): ByteArray {
        require(privateKey.size == KEY_BYTES) { "Некорректная длина приватного ключа X25519" }
        require(peerPublicKey.size == KEY_BYTES) { "Некорректная длина публичного ключа X25519" }
        val shared = ByteArray(KEY_BYTES)
        X25519PrivateKeyParameters(privateKey, 0).generateSecret(
            X25519PublicKeyParameters(peerPublicKey, 0),
            shared,
            0,
        )
        if (shared.all { it == 0.toByte() }) {
            shared.fill(0)
            throw SecurityException("Недопустимый общий секрет X25519")
        }
        return shared
    }

    /** Повторяет Go DeriveSessionKeys: HMAC-SHA256 и независимые ключи c2s/s2c. */
    fun deriveSessionKeys(handshakeKey: SecretKey, sharedSecret: ByteArray, salt: ByteArray): SessionKeys {
        require(salt.size == SALT_BYTES) { "Некорректная длина соли: ${salt.size}" }
        val prkMac = Mac.getInstance("HmacSHA256")
        prkMac.init(SecretKeySpec(handshakeKey.encoded, "HmacSHA256"))
        prkMac.update(sharedSecret)
        prkMac.update(salt)
        val prk = prkMac.doFinal()
        try {
            return SessionKeys(
                c2sKey = SecretKeySpec(hmacSha256(prk, "argon4-c2s-key\u0001".toByteArray(Charsets.UTF_8)), "AES"),
                s2cKey = SecretKeySpec(hmacSha256(prk, "argon4-s2c-key\u0001".toByteArray(Charsets.UTF_8)), "AES"),
            )
        } finally {
            prk.fill(0)
        }
    }

    fun bytesToHex(bytes: ByteArray): String = bytes.joinToString("") { "%02x".format(it) }

    fun hexToBytes(hex: String): ByteArray {
        require(hex.length % 2 == 0) { "Некорректная hex-строка" }
        return ByteArray(hex.length / 2) { index ->
            val high = Character.digit(hex[index * 2], 16)
            val low = Character.digit(hex[index * 2 + 1], 16)
            require(high >= 0 && low >= 0) { "Некорректная hex-строка" }
            ((high shl 4) or low).toByte()
        }
    }

    private fun hmacSha256(key: ByteArray, data: ByteArray): ByteArray {
        val mac = Mac.getInstance("HmacSHA256")
        mac.init(SecretKeySpec(key, "HmacSHA256"))
        return mac.doFinal(data)
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

    /** Шифрует пакет (по умолчанию Argon4 v5 с аутентифицированным padding переменной длины). */
    fun sealPacketWithSeq(
        frameType: Byte,
        salt: ByteArray,
        psk: ByteArray,
        key: SecretKey,
        seq: Long,
        plaintext: ByteArray,
        offset: Int = 0,
        length: Int = plaintext.size,
    ): ByteArray = sealPacketWithOptions(
        frameType = frameType,
        salt = salt,
        psk = psk,
        key = key,
        seq = seq,
        plaintext = plaintext,
        offset = offset,
        length = length,
        options = SealOptions(
            version = PROTOCOL_VERSION_V5,
            padding = defaultPaddingConfig(),
            maxOuterBytes = MAX_TCP_FRAME_SIZE,
        ),
    )

    /** Шифрует кадр Argon4 v4 или v5 с явной версией, padding и проверкой внешнего MTU. */
    fun sealPacketWithOptions(
        frameType: Byte,
        salt: ByteArray,
        psk: ByteArray,
        key: SecretKey,
        seq: Long,
        plaintext: ByteArray,
        offset: Int = 0,
        length: Int = plaintext.size,
        options: SealOptions = SealOptions(),
    ): ByteArray {
        require(salt.size == SALT_BYTES) { "Некорректная длина соли: ${salt.size}" }
        require(psk.size == KEY_BYTES) { "Некорректная длина psk: ${psk.size}" }
        require(seq >= 1L) { "Счётчик пакета seq должен быть >= 1" }
        if (length < 0 || offset < 0 || offset + length > plaintext.size) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_ENVELOPE, "Некорректный срез полезной нагрузки")
        }

        val version = if (options.version.toInt() == 0) PROTOCOL_VERSION_V5 else options.version
        val innerInput: ByteArray
        val innerOffset: Int
        val innerLen: Int
        when (version) {
            PROTOCOL_VERSION_V5 -> {
                val padLen = when {
                    options.useFixedPad -> options.fixedPadding
                    options.samplePad != null -> options.samplePad.invoke(length, options.maxOuterBytes)
                    else -> samplePaddingLen(options.padding, length, options.maxOuterBytes)
                }
                innerInput = packEnvelopeV5(plaintext, offset, length, padLen, options.maxOuterBytes)
                innerOffset = 0
                innerLen = innerInput.size
            }
            PROTOCOL_VERSION_V4 -> {
                if (options.maxOuterBytes > 0 && OVERHEAD_V4 + length > options.maxOuterBytes) {
                    throw Argon4ProtocolException(
                        Argon4ErrorKind.MTU_EXCEEDED,
                        "Кадр v4 (${OVERHEAD_V4 + length} байт) превышает лимит ${options.maxOuterBytes} байт",
                    )
                }
                innerInput = plaintext
                innerOffset = offset
                innerLen = length
            }
            else -> throw Argon4ProtocolException(Argon4ErrorKind.BAD_VERSION, "Неподдерживаемая версия протокола: $version")
        }

        val seqBytes = ByteBuffer.allocate(8).putLong(seq).array()
        val chk = computeHeaderCheck(psk, salt, version, frameType, seqBytes)

        val meta = ByteArray(META_BYTES)
        meta[0] = version
        meta[1] = frameType
        System.arraycopy(chk, 0, meta, 2, 4)
        System.arraycopy(seqBytes, 0, meta, 6, 8)

        val nonce = ByteArray(NONCE_BYTES)
        System.arraycopy(meta, 2, nonce, 0, NONCE_BYTES)

        val mask = computeHeaderMask(psk, salt)
        val out = ByteArray(HEADER_BYTES + innerLen + TAG_BYTES)
        System.arraycopy(salt, 0, out, 0, SALT_BYTES)
        for (i in 0 until META_BYTES) {
            out[SALT_BYTES + i] = (meta[i].toInt() xor mask[i].toInt()).toByte()
        }

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.ENCRYPT_MODE, key, GCMParameterSpec(TAG_BITS, nonce))
        cipher.updateAAD(buildAad(version, frameType, salt, seq))
        cipher.doFinal(innerInput, innerOffset, innerLen, out, HEADER_BYTES)
        return out
    }

    /** Снимает маску и проверяет 4-байтный HMAC заголовка кадра v4/v5 по PSK. */
    fun parseAndVerifyHeader(packet: ByteArray, length: Int = packet.size, psk: ByteArray): ParsedHeader {
        if (length < OVERHEAD_V4 || length > packet.size) {
            throw Argon4ProtocolException(Argon4ErrorKind.PACKET_TOO_SHORT, "Пакет туннеля слишком короткий: $length")
        }
        require(psk.size == KEY_BYTES) { "Некорректная длина psk: ${psk.size}" }

        val salt = packet.copyOfRange(0, SALT_BYTES)
        val mask = computeHeaderMask(psk, salt)

        val meta = ByteArray(META_BYTES)
        for (i in 0 until META_BYTES) {
            meta[i] = (packet[SALT_BYTES + i].toInt() xor mask[i].toInt()).toByte()
        }

        val version = meta[0]
        if (version != PROTOCOL_VERSION_V4 && version != PROTOCOL_VERSION_V5) {
            throw Argon4ProtocolException(Argon4ErrorKind.BAD_VERSION, "Неподдерживаемая версия протокола: $version")
        }
        val frameType = meta[1]

        val seqBytes = meta.copyOfRange(6, 14)
        val expectedChk = computeHeaderCheck(psk, salt, version, frameType, seqBytes)
        var chkDiff = 0
        for (i in 0 until 4) {
            chkDiff = chkDiff or (meta[2 + i].toInt() xor expectedChk[i].toInt())
        }
        if (chkDiff != 0) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.HEADER_AUTH_FAILED,
                "Ошибка проверки заголовка пакета по мастер-паролю (header auth failed)",
            )
        }
        if (version == PROTOCOL_VERSION_V5 && length < OVERHEAD_V5) {
            throw Argon4ProtocolException(Argon4ErrorKind.PACKET_TOO_SHORT, "Кадр v5 слишком короткий: $length")
        }

        val seq = ByteBuffer.wrap(seqBytes).long
        val nonce = ByteArray(NONCE_BYTES)
        System.arraycopy(meta, 2, nonce, 0, NONCE_BYTES)

        return ParsedHeader(
            version = version,
            frameType = frameType,
            salt = salt,
            nonce = nonce,
            seq = seq,
            payloadOffset = HEADER_BYTES,
            ciphertextLength = length - HEADER_BYTES,
        )
    }

    /** Расшифровывает пакет Argon4 (v4/v5) с настройками совместимости по умолчанию. */
    fun openPacket(packet: ByteArray, length: Int = packet.size, psk: ByteArray, key: SecretKey): DecryptedPacket =
        openPacketWithOptions(
            packet = packet,
            length = length,
            psk = psk,
            key = key,
            options = OpenOptions(allowV4 = true, maxOuterBytes = MAX_TCP_FRAME_SIZE),
        )

    /** Расшифровывает и проверяет кадр Argon4 с контролем версии (anti-downgrade), границ MTU и конверта v5. */
    fun openPacketWithOptions(
        packet: ByteArray,
        length: Int = packet.size,
        psk: ByteArray,
        key: SecretKey,
        options: OpenOptions = OpenOptions(),
    ): DecryptedPacket {
        if (options.maxOuterBytes > 0 && length > options.maxOuterBytes) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Входящий кадр $length байт превышает предел ${options.maxOuterBytes} байт",
            )
        }
        val header = parseAndVerifyHeader(packet, length, psk)
        if (options.expectedVersion.toInt() != 0) {
            if (header.version != options.expectedVersion) {
                if (options.expectedVersion == PROTOCOL_VERSION_V5 && header.version == PROTOCOL_VERSION_V4) {
                    throw Argon4ProtocolException(
                        Argon4ErrorKind.DOWNGRADE_REJECTED,
                        "Отклонена попытка понижения версии протокола (v5 -> v4)",
                    )
                }
                throw Argon4ProtocolException(
                    Argon4ErrorKind.BAD_VERSION,
                    "Неожиданная версия протокола: ${header.version}",
                )
            }
        } else if (header.version == PROTOCOL_VERSION_V4 && !options.allowV4) {
            throw Argon4ProtocolException(Argon4ErrorKind.BAD_VERSION, "Устаревшая версия v4 отключена")
        }

        val cipher = Cipher.getInstance("AES/GCM/NoPadding")
        cipher.init(Cipher.DECRYPT_MODE, key, GCMParameterSpec(TAG_BITS, header.nonce))
        cipher.updateAAD(buildAad(header.version, header.frameType, header.salt, header.seq))

        val pt = cipher.doFinal(packet, header.payloadOffset, header.ciphertextLength)
        val payload = if (header.version == PROTOCOL_VERSION_V5) {
            unpackEnvelopeV5(pt, options.maxPayloadBytes)
        } else {
            if (options.maxPayloadBytes > 0 && pt.size > options.maxPayloadBytes) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.MTU_EXCEEDED,
                    "Полезная нагрузка ${pt.size} байт превышает предел ${options.maxPayloadBytes}",
                )
            }
            pt
        }
        return DecryptedPacket(header.frameType, header.seq, header.salt, payload, header.version)
    }
}
