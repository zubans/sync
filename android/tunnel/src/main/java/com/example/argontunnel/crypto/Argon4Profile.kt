package com.example.argontunnel.crypto

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Job
import kotlinx.coroutines.channels.Channel
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.selects.select
import java.security.SecureRandom
import java.util.concurrent.atomic.AtomicBoolean
import java.util.concurrent.atomic.AtomicLong
import javax.crypto.SecretKey
import kotlin.math.max
import kotlin.math.min

/** Взвешенная корзина размеров внешнего кадра Argon4 v5 (в байтах UDP-кадра). */
data class PadBin(
    val weight: Int,
    val minOuter: Int,
    val maxOuter: Int,
)

/** Пользовательская и политическая конфигурация поведенческого профиля трафика. */
data class ArgonProfileConfig(
    val name: String = Argon4Profile.PROFILE_NONE,
    val version: Int = 0,
    val archetype: String = "",
    val maxAddedDelayMs: Long = 0L,
    val coalesceWindowMs: Long = 0L,
    val maxQueuePackets: Int = 0,
    val maxQueueBytes: Int = 0,
    val maxDurationMs: Long = 0L,
    val lowPowerMode: Boolean = false,
    val allowVideoCall: Boolean? = null,
)

/** Конкретный поведенческий профиль сессии, согласованный при рукопожатии Argon4 v5. */
data class SessionBehaviorProfile(
    val name: String = Argon4Profile.PROFILE_NONE,
    val version: Int = 0,
    val archetype: String = "",
    val padding: PaddingConfig = Argon4Crypto.defaultPaddingConfig(),
    val targetMaxOuter: Int = 0,
    val recommendedTunMtu: Int = 0,
    val coalesceEnabled: Boolean = false,
    val coalesceThreshold: Int = 0,
    val coalesceMaxPackets: Int = 0,
    val coalesceMaxBytes: Int = 0,
    val coalesceMinWindowMs: Long = 0L,
    val coalesceMaxWindowMs: Long = 0L,
    val maxAddedDelayMs: Long = 0L,
    val maxQueuePackets: Int = Argon4Profile.DEFAULT_MAX_QUEUE_PACKETS,
    val maxQueueBytes: Int = Argon4Profile.DEFAULT_MAX_QUEUE_BYTES,
    val burstPackets: Int = 0,
    val slotMinIntervalMs: Long = 0L,
    val slotMaxIntervalMs: Long = 0L,
    val keyframeBurstPackets: Int = 0,
    val keyframeMinIntervalMs: Long = 0L,
    val keyframeMaxIntervalMs: Long = 0L,
    val feedbackMinIntervalMs: Long = 0L,
    val feedbackMaxIntervalMs: Long = 0L,
    val feedbackIdleMinMs: Long = 0L,
    val feedbackIdleMaxMs: Long = 0L,
    val keepaliveMinIdleMs: Long = ArgonTimingConfig.DEFAULT_KEEPALIVE_MIN_IDLE_MS,
    val keepaliveMaxIdleMs: Long = ArgonTimingConfig.DEFAULT_KEEPALIVE_MAX_IDLE_MS,
    val deepIdleMinKeepaliveMs: Long = 0L,
    val deepIdleMaxKeepaliveMs: Long = 0L,
    val maxDurationMs: Long = 0L,
    val lowPowerMode: Boolean = false,
    val smallBins: List<PadBin> = emptyList(),
    val mediumBins: List<PadBin> = emptyList(),
) {
    /** Возвращает `true`, если поверх базового v5 включён активный профиль поведения трафика. */
    fun isActive(): Boolean = name.isNotEmpty() && version >= Argon4Profile.BEHAVIOR_PROFILE_VERSION

    /** Возвращает порог очереди, выше которого шейпер сбрасывает задержку pacing для снятия нагрузки. */
    fun highWatermark(): Int {
        val limit = if (maxQueuePackets > 0) maxQueuePackets else Argon4Profile.DEFAULT_MAX_QUEUE_PACKETS
        return (limit / 2).coerceAtLeast(4)
    }

    /** Подбирает длину padding в рамках корзин профиля и ограничения внешнего кадра. */
    fun samplePaddingLen(
        payloadLen: Int,
        maxOuterBytes: Int,
        randIntFn: (Int) -> Int = Argon4Profile::defaultRandInt,
    ): Int {
        if (payloadLen < 0) {
            throw Argon4ProtocolException(Argon4ErrorKind.INVALID_ENVELOPE, "Отрицательная длина payload: $payloadLen")
        }
        val effectiveOuter = if (maxOuterBytes <= 0) Argon4Crypto.maxOuterBytesForPathMtu(padding.pathMtu) else maxOuterBytes
        val budget = effectiveOuter - Argon4Crypto.OVERHEAD_V5 - payloadLen
        if (budget < 0 || payloadLen > Argon4Crypto.MAX_PAYLOAD_LIMIT) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Payload ($payloadLen байт) превышает лимит кадра ($effectiveOuter байт)",
            )
        }
        if (!isActive() || (smallBins.isEmpty() && mediumBins.isEmpty())) {
            return Argon4Crypto.samplePaddingLen(padding, payloadLen, effectiveOuter)
        }

        var targetOuter = effectiveOuter
        if (this.targetMaxOuter in 1 until targetOuter && Argon4Crypto.OVERHEAD_V5 + payloadLen + 8 <= this.targetMaxOuter) {
            targetOuter = this.targetMaxOuter
        }
        val targetBudget = min(budget, targetOuter - Argon4Crypto.OVERHEAD_V5 - payloadLen)
        if (targetBudget <= 0) return 0

        var lo: Int
        var hi: Int
        when {
            payloadLen <= 140 && smallBins.isNotEmpty() -> {
                val bin = pickWeightedBin(smallBins, randIntFn)
                val minFrame = Argon4Crypto.OVERHEAD_V5 + payloadLen + 6
                val binLo = max(bin.minOuter, minFrame)
                var binHi = bin.maxOuter
                if (binHi < binLo) binHi = binLo + 16
                lo = binLo - (Argon4Crypto.OVERHEAD_V5 + payloadLen)
                hi = binHi - (Argon4Crypto.OVERHEAD_V5 + payloadLen)
            }
            payloadLen <= 750 && mediumBins.isNotEmpty() -> {
                val bin = pickWeightedBin(mediumBins, randIntFn)
                lo = max(8, bin.minOuter - (Argon4Crypto.OVERHEAD_V5 + payloadLen))
                hi = bin.maxOuter - (Argon4Crypto.OVERHEAD_V5 + payloadLen)
                if (hi <= lo) hi = lo + 48
            }
            else -> {
                lo = padding.minPad
                hi = padding.maxPad
                if (lo > targetBudget) lo = 1
            }
        }

        if (lo < 0) lo = 0
        if (hi > targetBudget) hi = targetBudget
        if (lo > hi) lo = hi
        if (hi <= lo) return lo
        return lo + randIntFn(hi - lo + 1)
    }

    private fun pickWeightedBin(bins: List<PadBin>, randIntFn: (Int) -> Int): PadBin {
        val total = bins.sumOf { if (it.weight > 0) it.weight else 0 }
        if (total <= 0) return bins.first()
        val pick = randIntFn(total)
        var acc = 0
        for (bin in bins) {
            if (bin.weight <= 0) continue
            acc += bin.weight
            if (pick < acc) return bin
        }
        return bins.last()
    }
}

/** Исключение переполнения ограниченной очереди шейпера (backpressure). */
class QueueFullException(message: String) : IllegalStateException(message)

/** Текущие агрегированные счётчики и состояние поведенческого шейпера. */
data class ShaperStats(
    val activeProfile: String = Argon4Profile.PROFILE_NONE,
    val activeArchetype: String = "",
    val sentDataFrames: Long = 0,
    val sentBatchFrames: Long = 0,
    val coalescedPackets: Long = 0,
    val sentControlFrames: Long = 0,
    val sentKeepalives: Long = 0,
    val droppedBackpressure: Long = 0,
    val queueDepth: Int = 0,
    val queuedBytes: Int = 0,
    val profileDowngraded: Boolean = false,
)

/**
 * Правила согласования, валидации и калибровки поведенческих профилей Argon4 (`profile-v1`),
 * синхронизированные с сервером (`tunnel-server/internal/tunnel/profile.go`).
 */
object Argon4Profile {
    const val CAPABILITY_PROFILE_V1 = "profile-v1"
    const val BEHAVIOR_PROFILE_VERSION = 1

    const val PROFILE_NONE = ""
    const val PROFILE_BASELINE = "baseline"
    const val PROFILE_VIDEO_CALL = "video-call"

    const val MODE_TUNNEL = "tunnel"
    const val MODE_TRANSIT = "transit"
    const val PROTO_UDP = "udp"
    const val PROTO_TCP = "tcp"

    const val DEFAULT_MAX_QUEUE_PACKETS = 128
    const val DEFAULT_MAX_QUEUE_BYTES = 256 * 1024
    const val DEFAULT_VIDEO_CALL_MAX_DURATION_MS = 2 * 60 * 60 * 1000L
    const val HARD_MAX_ADDED_DELAY_MS = 30L

    const val MAX_BATCH_COUNT = 16
    const val BATCH_HEADER_SIZE = 1
    const val BATCH_ITEM_HEADER_SIZE = 2

    const val KEEPALIVE_PING_PAYLOAD = "ping"
    const val KEEPALIVE_PONG_PAYLOAD = "pong"
    const val KEEPALIVE_FEEDBACK_PAYLOAD = "fb"

    const val VIDEO_CALL_WARNING =
        "Профиль video-call увеличивает расход батареи/трафика (~25–45%) и добавляет <=18 мс сглаживания. Он не скрывает IP сервера и сам факт активного протокола."

    private val secureRandom = SecureRandom()

    internal fun defaultRandInt(bound: Int): Int = if (bound <= 1) 0 else secureRandom.nextInt(bound)

    internal fun defaultRandUnit(): Double = secureRandom.nextDouble()

    /** Приводит имя профиля к каноническому виду и отвергает неизвестные значения. */
    fun normalizeBehaviorProfileName(name: String?): String =
        when (name.orEmpty().trim().lowercase()) {
            "", "none", "off" -> PROFILE_NONE
            PROFILE_BASELINE -> PROFILE_BASELINE
            PROFILE_VIDEO_CALL, "videocall", "video_call" -> PROFILE_VIDEO_CALL
            else -> throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Неизвестный поведенческий профиль: $name",
            )
        }

    /** Проверяет совместимость запрошенного профиля с версией протокола и транспортом. */
    fun validateProfileRequest(
        version: Byte,
        mode: String = MODE_TUNNEL,
        proto: String = PROTO_UDP,
        cfg: ArgonProfileConfig,
    ): Pair<String, Int> {
        val normName = normalizeBehaviorProfileName(cfg.name)
        if (cfg.version < 0 || cfg.version > BEHAVIOR_PROFILE_VERSION) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Неподдерживаемая версия профиля ${cfg.version} (поддерживается 1)",
            )
        }
        if (cfg.maxAddedDelayMs < 0L || cfg.maxAddedDelayMs > 200L) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Некорректный предел добавленной задержки ${cfg.maxAddedDelayMs} мс",
            )
        }
        if (cfg.coalesceWindowMs < 0L || cfg.coalesceWindowMs > 50L) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Некорректное окно агрегации ${cfg.coalesceWindowMs} мс",
            )
        }
        if (cfg.maxQueuePackets < 0 || cfg.maxQueueBytes < 0 || cfg.maxDurationMs < 0L) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Отрицательные ограничения очереди или длительности профиля недопустимы",
            )
        }
        if (version == Argon4Crypto.PROTOCOL_VERSION_V4 && (normName.isNotEmpty() || cfg.version > 0)) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Поведенческие профили требуют Argon4 v5 (недоступны в v4)",
            )
        }
        if (cfg.allowVideoCall == false && normName == PROFILE_VIDEO_CALL) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Профиль video-call запрещён локальной политикой",
            )
        }
        if (normName == PROFILE_VIDEO_CALL && proto.trim().equals(PROTO_TCP, ignoreCase = true)) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Профиль video-call поддерживается только поверх UDP-транспорта (режим=$mode)",
            )
        }
        if (normName.isNotEmpty() || cfg.version == BEHAVIOR_PROFILE_VERSION) {
            val resolvedName = normName.ifEmpty { PROFILE_BASELINE }
            return resolvedName to BEHAVIOR_PROFILE_VERSION
        }
        return PROFILE_NONE to 0
    }

    /** Строго проверяет ответ сервера в рукопожатии и запрещает молчаливый откат запрошенного профиля. */
    fun verifyClientProfileResponse(
        reqName: String,
        reqVersion: Int,
        resp: HandshakeResponse,
        pad: PaddingConfig,
        timing: ArgonTimingConfig,
        cfg: ArgonProfileConfig,
        randIntFn: (Int) -> Int = ::defaultRandInt,
    ): SessionBehaviorProfile {
        val respName = normalizeBehaviorProfileName(resp.behaviorProfile)
        if (reqVersion == 0 && reqName.isEmpty()) {
            if (respName.isNotEmpty()) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.PROFILE_UNSUPPORTED,
                    "Сервер неожиданно навязал профиль '$respName'",
                )
            }
            return SessionBehaviorProfile()
        }
        val hasCap = resp.capabilities?.any { it.trim().equals(CAPABILITY_PROFILE_V1, ignoreCase = true) } == true
        if (resp.protocolVersion != Argon4Crypto.PROTOCOL_VERSION_V5.toInt() || !hasCap) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Сервер не поддерживает поведенческие профили $CAPABILITY_PROFILE_V1",
            )
        }
        if (resp.profileVersion != reqVersion || respName != reqName) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.PROFILE_UNSUPPORTED,
                "Несогласованный профиль сервера ('$respName' v${resp.profileVersion} вместо '$reqName' v$reqVersion)",
            )
        }
        return buildSessionProfile(
            cfg = cfg.copy(name = respName, version = resp.profileVersion),
            pad = pad,
            timing = timing,
            randIntFn = randIntFn,
        )
    }

    /** Формирует сессионный профиль со случайным выбором одного из калиброванных архетипов. */
    fun buildSessionProfile(
        cfg: ArgonProfileConfig,
        pad: PaddingConfig = Argon4Crypto.defaultPaddingConfig(),
        timing: ArgonTimingConfig = ArgonTimingConfig(),
        randIntFn: (Int) -> Int = ::defaultRandInt,
    ): SessionBehaviorProfile {
        val (name, ver) = validateProfileRequest(Argon4Crypto.PROTOCOL_VERSION_V5, MODE_TUNNEL, PROTO_UDP, cfg)
        if (name.isEmpty() && ver == 0) {
            return SessionBehaviorProfile()
        }
        val resolvedPad = Argon4Crypto.resolvePaddingConfig(pad, isUdp = true)
        val resolvedTiming = runCatching { Argon4Timing.normalizeConfig(timing) }.getOrDefault(ArgonTimingConfig())

        var sp = when (name) {
            PROFILE_BASELINE -> sampleBaselineArchetype(cfg.archetype, resolvedPad, resolvedTiming, randIntFn)
            PROFILE_VIDEO_CALL -> sampleVideoCallArchetype(cfg.archetype, resolvedPad, randIntFn)
            else -> throw Argon4ProtocolException(Argon4ErrorKind.PROFILE_UNSUPPORTED, "Неизвестный профиль: $name")
        }

        val maxAddedDelay = if (cfg.maxAddedDelayMs > 0L) cfg.maxAddedDelayMs else sp.maxAddedDelayMs
        var coalesceMin = sp.coalesceMinWindowMs
        var coalesceMax = sp.coalesceMaxWindowMs
        if (cfg.coalesceWindowMs > 0L) {
            coalesceMin = max(1L, cfg.coalesceWindowMs / 2L)
            coalesceMax = min(cfg.coalesceWindowMs, maxAddedDelay)
        }
        sp = sp.copy(
            maxAddedDelayMs = maxAddedDelay,
            coalesceMinWindowMs = coalesceMin,
            coalesceMaxWindowMs = coalesceMax,
            maxQueuePackets = if (cfg.maxQueuePackets > 0) cfg.maxQueuePackets else sp.maxQueuePackets.coerceAtLeast(DEFAULT_MAX_QUEUE_PACKETS),
            maxQueueBytes = if (cfg.maxQueueBytes > 0) cfg.maxQueueBytes else sp.maxQueueBytes.coerceAtLeast(DEFAULT_MAX_QUEUE_BYTES),
            maxDurationMs = if (cfg.maxDurationMs > 0L) cfg.maxDurationMs else sp.maxDurationMs,
            lowPowerMode = cfg.lowPowerMode,
        )
        return sp
    }

    /** Возвращает эффективный TUN MTU с учётом рекомендованного потолка активного профиля. */
    fun effectiveProfileTunMtu(
        requestedMtu: Int,
        pathMtu: Int,
        version: Byte,
        profile: SessionBehaviorProfile,
    ): Int {
        var eff = Argon4Crypto.effectiveTunMtu(requestedMtu, pathMtu, version)
        if (profile.isActive() && profile.recommendedTunMtu > 0 && eff > profile.recommendedTunMtu) {
            eff = Argon4Crypto.effectiveTunMtu(profile.recommendedTunMtu, pathMtu, version)
        }
        return eff
    }

    /** Упаковывает от 2 до [MAX_BATCH_COUNT] мелких пакетов в контейнер [Argon4Crypto.FRAME_BATCH]. */
    fun packBatchPlaintext(packets: List<ByteArray>): ByteArray {
        if (packets.size < 2 || packets.size > MAX_BATCH_COUNT) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Размер batch ${packets.size} вне [2..$MAX_BATCH_COUNT]",
            )
        }
        var total = BATCH_HEADER_SIZE
        for (pkt in packets) {
            if (pkt.isEmpty() || pkt.size > Argon4Crypto.MAX_PAYLOAD_LIMIT) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.INVALID_ENVELOPE,
                    "Некорректная длина вложенного пакета в batch: ${pkt.size}",
                )
            }
            total += BATCH_ITEM_HEADER_SIZE + pkt.size
        }
        if (total > Argon4Crypto.MAX_PAYLOAD_LIMIT) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Суммарный размер batch ($total Б) превышает лимит",
            )
        }
        val out = ByteArray(total)
        out[0] = packets.size.toByte()
        var off = BATCH_HEADER_SIZE
        for (pkt in packets) {
            val len = pkt.size
            out[off] = (len ushr 8).toByte()
            out[off + 1] = len.toByte()
            System.arraycopy(pkt, 0, out, off + BATCH_ITEM_HEADER_SIZE, len)
            off += BATCH_ITEM_HEADER_SIZE + len
        }
        return out
    }

    /** Разбирает и проверяет контейнер [Argon4Crypto.FRAME_BATCH]. */
    fun unpackBatchPlaintext(plaintext: ByteArray, maxPayloadLen: Int = 0): List<ByteArray> {
        if (maxPayloadLen > 0 && plaintext.size > maxPayloadLen) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.MTU_EXCEEDED,
                "Агрегированный кадр ${plaintext.size} Б превышает предел $maxPayloadLen",
            )
        }
        if (plaintext.size < BATCH_HEADER_SIZE + BATCH_ITEM_HEADER_SIZE + 1) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Агрегированный кадр слишком короткий (${plaintext.size})",
            )
        }
        val count = plaintext[0].toInt() and 0xFF
        if (count < 2 || count > MAX_BATCH_COUNT) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.INVALID_ENVELOPE,
                "Недопустимое число элементов batch: $count",
            )
        }
        val out = ArrayList<ByteArray>(count)
        var off = BATCH_HEADER_SIZE
        for (i in 0 until count) {
            if (off + BATCH_ITEM_HEADER_SIZE > plaintext.size) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.INVALID_ENVELOPE,
                    "Обрезан заголовок элемента batch #$i",
                )
            }
            val itemLen = ((plaintext[off].toInt() and 0xFF) shl 8) or (plaintext[off + 1].toInt() and 0xFF)
            off += BATCH_ITEM_HEADER_SIZE
            if (itemLen <= 0 || off + itemLen > plaintext.size) {
                throw Argon4ProtocolException(
                    Argon4ErrorKind.INVALID_ENVELOPE,
                    "Некорректная длина элемента batch #$i ($itemLen)",
                )
            }
            out.add(plaintext.copyOfRange(off, off + itemLen))
            off += itemLen
        }
        if (off != plaintext.size) {
            throw Argon4ProtocolException(
                Argon4ErrorKind.TRAILING_DATA,
                "Обнаружено ${plaintext.size - off} лишних байт в batch-контейнере",
            )
        }
        return out
    }

    private data class BaselineVariant(
        val archetype: String,
        val minPad: Int,
        val maxPad: Int,
        val smallThr: Int,
        val smallMin: Int,
        val smallMax: Int,
        val coalMaxMs: Long,
        val delayMs: Long,
    )

    private data class VideoCallVariant(
        val archetype: String,
        val targetMaxOuter: Int,
        val recommendedTunMtu: Int,
        val coalesceMaxMs: Long,
        val maxAddedDelayMs: Long,
        val burstPackets: Int,
        val slotMinMs: Long,
        val slotMaxMs: Long,
        val keyframeBurstPackets: Int,
        val kfMinMs: Long,
        val kfMaxMs: Long,
        val fbMinMs: Long,
        val fbMaxMs: Long,
        val fbIdleMinMs: Long,
        val fbIdleMaxMs: Long,
        val kaMinMs: Long,
        val kaMaxMs: Long,
    )

    private val baselineVariants = listOf(
        BaselineVariant("baseline-balanced-mix", 10, 108, 176, 18, 208, 3L, 6L),
        BaselineVariant("baseline-compact-mix", 6, 48, 136, 10, 96, 2L, 5L),
        BaselineVariant("baseline-wide-mix", 16, 184, 240, 28, 288, 4L, 8L),
    )

    private val videoCallVariants = listOf(
        VideoCallVariant("telemost-av-active", 1210, 1140, 4L, 15L, 7, 32L, 48L, 16, 3_000L, 5_000L, 95L, 195L, 480L, 1_050L, 1_900L, 3_000L),
        VideoCallVariant("telemost-hd-dynamic", 1228, 1150, 5L, 16L, 9, 26L, 42L, 18, 2_000L, 4_000L, 80L, 175L, 420L, 920L, 1_800L, 2_800L),
        VideoCallVariant("telemost-low-cam", 1184, 1120, 5L, 18L, 5, 38L, 58L, 12, 4_000L, 6_000L, 120L, 250L, 560L, 1_180L, 2_100L, 3_300L),
    )

    private fun sampleBaselineArchetype(
        requested: String,
        basePad: PaddingConfig,
        timing: ArgonTimingConfig,
        randIntFn: (Int) -> Int,
    ): SessionBehaviorProfile {
        val idx = chooseVariantIndex(baselineVariants.map { it.archetype }, requested, randIntFn)
        val v = baselineVariants[idx]
        var minPad = jitterInt(v.minPad, 2, randIntFn)
        var maxPad = jitterInt(v.maxPad, 8, randIntFn)
        if (maxPad <= minPad) maxPad = minPad + 8
        val smallMin = jitterInt(v.smallMin, 4, randIntFn)
        var smallMax = jitterInt(v.smallMax, 12, randIntFn)
        if (smallMax <= smallMin) smallMax = smallMin + 8

        val pad = PaddingConfig(
            profile = basePad.profile.ifEmpty { Argon4Crypto.PADDING_PROFILE_BALANCED },
            minPad = minPad,
            maxPad = maxPad,
            smallThreshold = jitterInt(v.smallThr, 12, randIntFn),
            smallMinPad = smallMin,
            smallMaxPad = smallMax,
            pathMtu = basePad.pathMtu,
        )
        val maxOuter = Argon4Crypto.maxOuterBytesForPathMtu(pad.pathMtu)
        return SessionBehaviorProfile(
            name = PROFILE_BASELINE,
            version = BEHAVIOR_PROFILE_VERSION,
            archetype = v.archetype,
            padding = pad,
            targetMaxOuter = maxOuter,
            coalesceEnabled = true,
            coalesceThreshold = pad.smallThreshold,
            coalesceMaxPackets = 6,
            coalesceMaxBytes = 900,
            coalesceMinWindowMs = 1L,
            coalesceMaxWindowMs = v.coalMaxMs,
            maxAddedDelayMs = v.delayMs,
            maxQueuePackets = DEFAULT_MAX_QUEUE_PACKETS,
            maxQueueBytes = DEFAULT_MAX_QUEUE_BYTES,
            keepaliveMinIdleMs = timing.keepaliveMinIdleMs,
            keepaliveMaxIdleMs = timing.keepaliveMaxIdleMs,
            smallBins = listOf(
                PadBin(70, Argon4Crypto.OVERHEAD_V5 + 48 + pad.smallMinPad, Argon4Crypto.OVERHEAD_V5 + 80 + pad.smallMaxPad),
                PadBin(30, Argon4Crypto.OVERHEAD_V5 + 160, Argon4Crypto.OVERHEAD_V5 + 320),
            ),
        )
    }

    private fun sampleVideoCallArchetype(
        requested: String,
        basePad: PaddingConfig,
        randIntFn: (Int) -> Int,
    ): SessionBehaviorProfile {
        val idx = chooseVariantIndex(videoCallVariants.map { it.archetype }, requested, randIntFn)
        val v = videoCallVariants[idx]
        val physMaxOuter = Argon4Crypto.maxOuterBytesForPathMtu(basePad.pathMtu)
        var targetMaxOuter = jitterInt(v.targetMaxOuter, 12, randIntFn)
        if (physMaxOuter in 1 until targetMaxOuter) {
            targetMaxOuter = physMaxOuter
        }
        val effectivePathForTun = minPositive(basePad.pathMtu, targetMaxOuter + Argon4Crypto.IPV4_UDP_OVERHEAD)
        val tunMtu = Argon4Crypto.effectiveTunMtu(v.recommendedTunMtu, effectivePathForTun, Argon4Crypto.PROTOCOL_VERSION_V5)

        val pad = PaddingConfig(
            profile = basePad.profile.ifEmpty { Argon4Crypto.PADDING_PROFILE_BALANCED },
            minPad = 8,
            maxPad = 68,
            smallThreshold = 160,
            smallMinPad = 18,
            smallMaxPad = 140,
            pathMtu = basePad.pathMtu,
        )

        return SessionBehaviorProfile(
            name = PROFILE_VIDEO_CALL,
            version = BEHAVIOR_PROFILE_VERSION,
            archetype = v.archetype,
            padding = pad,
            targetMaxOuter = targetMaxOuter,
            recommendedTunMtu = tunMtu,
            coalesceEnabled = true,
            coalesceThreshold = 180,
            coalesceMaxPackets = 8,
            coalesceMaxBytes = minPositive(1060, targetMaxOuter - Argon4Crypto.OVERHEAD_V5 - 16),
            coalesceMinWindowMs = 2L,
            coalesceMaxWindowMs = v.coalesceMaxMs,
            maxAddedDelayMs = v.maxAddedDelayMs,
            maxQueuePackets = DEFAULT_MAX_QUEUE_PACKETS,
            maxQueueBytes = DEFAULT_MAX_QUEUE_BYTES,
            burstPackets = jitterInt(v.burstPackets, 1, randIntFn),
            slotMinIntervalMs = v.slotMinMs,
            slotMaxIntervalMs = v.slotMaxMs,
            keyframeBurstPackets = jitterInt(v.keyframeBurstPackets, 2, randIntFn),
            keyframeMinIntervalMs = v.kfMinMs,
            keyframeMaxIntervalMs = v.kfMaxMs,
            feedbackMinIntervalMs = v.fbMinMs,
            feedbackMaxIntervalMs = v.fbMaxMs,
            feedbackIdleMinMs = v.fbIdleMinMs,
            feedbackIdleMaxMs = v.fbIdleMaxMs,
            keepaliveMinIdleMs = v.kaMinMs,
            keepaliveMaxIdleMs = v.kaMaxMs,
            deepIdleMinKeepaliveMs = 4_000L,
            deepIdleMaxKeepaliveMs = 10_000L,
            maxDurationMs = DEFAULT_VIDEO_CALL_MAX_DURATION_MS,
            smallBins = listOf(
                PadBin(56, 92, 196),
                PadBin(28, 278, 342),
                PadBin(16, 420, 740),
            ),
            mediumBins = listOf(
                PadBin(32, 620, 900),
                PadBin(68, 900, minPositive(1180, targetMaxOuter)),
            ),
        )
    }

    private fun chooseVariantIndex(names: List<String>, requested: String, randIntFn: (Int) -> Int): Int {
        val req = requested.trim()
        if (req.isNotEmpty()) {
            val found = names.indexOfFirst { it.equals(req, ignoreCase = true) }
            if (found >= 0) return found
        }
        return randIntFn(names.size).coerceIn(0, names.lastIndex)
    }

    private fun jitterInt(base: Int, spread: Int, randIntFn: (Int) -> Int): Int {
        if (spread <= 0) return base
        val v = base - spread + randIntFn(spread * 2 + 1)
        return if (v < 1) 1 else v
    }

    private fun minPositive(a: Int, b: Int): Int {
        if (a <= 0) return b
        if (b <= 0 || a < b) return a
        return b
    }
}

/**
 * Поведенческий шейпер исходящего потока Argon4 v5:
 * ограниченная очередь с приоритетом реальных пакетов над `"fb"`/`"ping"`,
 * коалесинг в [Argon4Crypto.FRAME_BATCH], пачечный pacing и авто-снижение по [SessionBehaviorProfile.maxDurationMs].
 */
class ArgonTrafficShaper(
    profile: SessionBehaviorProfile,
    private val version: Byte = Argon4Crypto.PROTOCOL_VERSION_V5,
    salt: ByteArray,
    psk: ByteArray,
    private val key: SecretKey,
    maxOuterBytes: Int = Argon4Crypto.maxOuterBytesForPathMtu(profile.padding.pathMtu),
    private val isServer: Boolean = false,
    private val keepaliveEnabled: Boolean = true,
    private val clock: ArgonClock = SystemArgonClock,
    private val tracker: ActivityTracker = ActivityTracker(clock),
    private val randUnit: () -> Double = Argon4Profile::defaultRandUnit,
    private val randIntFn: (Int) -> Int = Argon4Profile::defaultRandInt,
    private val nextSeq: () -> Long,
    private val writeWire: suspend (ByteArray) -> Unit,
    private val onDowngrade: ((String) -> Unit)? = null,
    private val onFatalError: ((Throwable) -> Unit)? = null,
) {
    private data class QueuedPacket(
        val payload: ByteArray,
        val coalesceAtMs: Long,
        val deadlineMs: Long,
    )

    private data class SendItem(
        val frameType: Byte,
        val payload: ByteArray,
        val isPing: Boolean = false,
        val batched: Int = 0,
    )

    private val profile: SessionBehaviorProfile = profile.copy(
        maxQueuePackets = if (profile.maxQueuePackets > 0) profile.maxQueuePackets else Argon4Profile.DEFAULT_MAX_QUEUE_PACKETS,
        maxQueueBytes = if (profile.maxQueueBytes > 0) profile.maxQueueBytes else Argon4Profile.DEFAULT_MAX_QUEUE_BYTES,
        coalesceMaxPackets = if (profile.coalesceMaxPackets > 0) profile.coalesceMaxPackets else 6,
        coalesceMaxBytes = if (profile.coalesceMaxBytes > 0) profile.coalesceMaxBytes else 900,
    )
    private val saltCopy = salt.copyOf()
    private val pskCopy = psk.copyOf()
    private val maxOuter = if (maxOuterBytes > 0) maxOuterBytes else Argon4Crypto.maxOuterBytesForPathMtu(this.profile.padding.pathMtu)
    private val sealOptions = SealOptions(
        version = if (version.toInt() == 0) Argon4Crypto.PROTOCOL_VERSION_V5 else version,
        padding = this.profile.padding,
        samplePad = { payloadLen, outerLimit ->
            this.profile.samplePaddingLen(payloadLen, outerLimit, randIntFn)
        },
        maxOuterBytes = this.maxOuter,
    )

    private val lock = Any()
    private val notifyCh = Channel<Unit>(Channel.CONFLATED)

    private val startedAtMs = clock.nowMs()
    private var started = false
    private var closed = false
    private var expired = false
    private var loopJob: Job? = null

    private val realQueue = ArrayDeque<QueuedPacket>()
    private var queuedBytes = 0
    private val ctrlQueue = ArrayDeque<String>()

    private var tokens = this.profile.burstPackets
    private var nextSlotAtMs = startedAtMs
    private var nextKfAtMs = if (
        this.profile.keyframeMinIntervalMs > 0L &&
        this.profile.keyframeMaxIntervalMs >= this.profile.keyframeMinIntervalMs
    ) {
        startedAtMs + Argon4Timing.randomDurationBetween(
            this.profile.keyframeMinIntervalMs,
            this.profile.keyframeMaxIntervalMs,
            randUnit,
        )
    } else {
        UNSET_TIME_MS
    }
    private var lastRealInMs = UNSET_TIME_MS
    private var lastRealOutMs = UNSET_TIME_MS
    private var nextFbAtMs = UNSET_TIME_MS
    private var nextKaAtMs = UNSET_TIME_MS

    private val sentDataFrames = AtomicLong(0L)
    private val sentBatchFrames = AtomicLong(0L)
    private val coalescedPackets = AtomicLong(0L)
    private val sentControlFrames = AtomicLong(0L)
    private val sentKeepalives = AtomicLong(0L)
    private val droppedBackpressure = AtomicLong(0L)
    private val profileDowngraded = AtomicBoolean(false)

    /** Запускает фоновую корутину планировщика; повторный вызов безопасен. */
    fun start(scope: CoroutineScope) {
        synchronized(lock) {
            if (started || closed) return
            started = true
            loopJob = scope.launch { runLoop() }
        }
    }

    /** Останавливает шейпер, очищает очереди и чувствительные буферы. */
    fun close() {
        val jobToCancel: Job?
        synchronized(lock) {
            if (closed) return
            closed = true
            realQueue.clear()
            queuedBytes = 0
            ctrlQueue.clear()
            jobToCancel = loopJob
            loopJob = null
        }
        notifyCh.close()
        jobToCancel?.cancel()
        saltCopy.fill(0)
        pskCopy.fill(0)
    }

    /** Добавляет реальный IP-пакет в приоритетную очередь или бросает [QueueFullException]. */
    fun enqueueReal(pkt: ByteArray) {
        val now = clock.nowMs()
        synchronized(lock) {
            if (closed) throw IllegalStateException("Шейпер Argon4 остановлен")
            if (realQueue.size >= profile.maxQueuePackets ||
                (realQueue.isNotEmpty() && queuedBytes + pkt.size > profile.maxQueueBytes)
            ) {
                droppedBackpressure.incrementAndGet()
                signalWake()
                throw QueueFullException("Очередь поведенческого шейпера переполнена")
            }
            val copied = pkt.copyOf()
            val deadline = now + profile.maxAddedDelayMs
            var coalesceAt = now
            if (profile.coalesceEnabled && !expired && copied.size <= profile.coalesceThreshold && profile.coalesceMaxWindowMs > 0L) {
                val cw = Argon4Timing.randomDurationBetween(profile.coalesceMinWindowMs, profile.coalesceMaxWindowMs, randUnit)
                coalesceAt = min(now + cw, deadline)
            }
            realQueue.addLast(QueuedPacket(copied, coalesceAt, deadline))
            queuedBytes += copied.size
            lastRealOutMs = now
            nextKaAtMs = UNSET_TIME_MS
        }
        tracker.touch()
        signalWake()
    }

    /** Добавляет низкоприоритетный служебный кадр (`"pong"`, `"fb"`, `"ping"`), не мешающий данным. */
    fun requestControl(payload: String) {
        synchronized(lock) {
            if (closed || ctrlQueue.size >= 8) return
            ctrlQueue.addLast(payload)
        }
        signalWake()
    }

    /** Отмечает приём подлинного входящего пакета (обновляет окно обратной связи RTCP-модели). */
    fun recordInboundActivity() {
        val now = clock.nowMs()
        synchronized(lock) {
            if (closed) return
            lastRealInMs = now
        }
        signalWake()
    }

    /** Возвращает текущую статистику и статус деградации профиля. */
    fun stats(): ShaperStats = synchronized(lock) {
        ShaperStats(
            activeProfile = profile.name,
            activeArchetype = profile.archetype,
            sentDataFrames = sentDataFrames.get(),
            sentBatchFrames = sentBatchFrames.get(),
            coalescedPackets = coalescedPackets.get(),
            sentControlFrames = sentControlFrames.get(),
            sentKeepalives = sentKeepalives.get(),
            droppedBackpressure = droppedBackpressure.get(),
            queueDepth = realQueue.size,
            queuedBytes = queuedBytes,
            profileDowngraded = profileDowngraded.get(),
        )
    }

    private suspend fun runLoop() {
        try {
            while (true) {
                val (item, waitMs, shouldStop) = nextStep(clock.nowMs())
                if (shouldStop) return
                if (item != null) {
                    try {
                        emitItem(item)
                    } catch (ce: CancellationException) {
                        throw ce
                    } catch (err: Throwable) {
                        if (Argon4Timing.isFatalKeepaliveError(err)) {
                            onFatalError?.invoke(err)
                            return
                        }
                    }
                    continue
                }
                val woken = awaitNextWake(waitMs)
                if (!woken) return
            }
        } catch (ce: CancellationException) {
            throw ce
        }
    }

    private suspend fun awaitNextWake(waitMs: Long): Boolean {
        val clampedWait = waitMs.coerceAtLeast(1L)
        val immediate = notifyCh.tryReceive()
        if (immediate.isClosed) return false
        if (immediate.isSuccess) return true
        return coroutineScope {
            if (!isActive) return@coroutineScope false
            val timerJob = launch { clock.delayMs(clampedWait) }
            val keepRunning = select<Boolean> {
                notifyCh.onReceiveCatching { res -> !res.isClosed }
                timerJob.onJoin { true }
            }
            timerJob.cancel()
            keepRunning
        }
    }

    private data class StepDecision(
        val item: SendItem?,
        val waitMs: Long,
        val stop: Boolean,
    )

    private fun nextStep(nowMs: Long): StepDecision {
        var expiredMsg: String? = null
        val decision = synchronized(lock) {
            if (closed) return@synchronized StepDecision(null, 0L, true)
            if (profile.maxDurationMs > 0L && !expired && nowMs >= startedAtMs + profile.maxDurationMs) {
                expired = true
                ctrlQueue.clear()
                nextFbAtMs = UNSET_TIME_MS
                nextKaAtMs = UNSET_TIME_MS
                profileDowngraded.set(true)
                expiredMsg = "Время профиля '${profile.name}' истекло (${profile.maxDurationMs} мс); фоновый трафик снижен до baseline"
            }

            if (realQueue.isNotEmpty()) {
                val (item, wait) = stepRealLocked(nowMs)
                return@synchronized StepDecision(item, wait, false)
            }
            if (ctrlQueue.isNotEmpty()) {
                val payload = ctrlQueue.removeFirst()
                val item = SendItem(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    payload = payload.toByteArray(Charsets.UTF_8),
                    isPing = payload == Argon4Profile.KEEPALIVE_PING_PAYLOAD,
                )
                return@synchronized StepDecision(item, 0L, false)
            }
            StepDecision(stepIdleLocked(nowMs), computeIdleWaitLocked(nowMs), false)
        }
        if (expiredMsg != null) {
            onDowngrade?.invoke(expiredMsg!!)
        }
        return decision
    }

    private fun stepRealLocked(nowMs: Long): Pair<SendItem?, Long> {
        val head = realQueue.first()
        val effectiveVC = profile.name == Argon4Profile.PROFILE_VIDEO_CALL && !expired

        if (effectiveVC && profile.burstPackets > 0 && nowMs >= nextSlotAtMs) {
            if (nextKfAtMs != UNSET_TIME_MS && nowMs >= nextKfAtMs) {
                tokens = profile.keyframeBurstPackets
                nextKfAtMs = nowMs + Argon4Timing.randomDurationBetween(profile.keyframeMinIntervalMs, profile.keyframeMaxIntervalMs, randUnit)
            } else {
                tokens = profile.burstPackets
            }
            nextSlotAtMs = nowMs + Argon4Timing.randomDurationBetween(profile.slotMinIntervalMs, profile.slotMaxIntervalMs, randUnit)
        }

        val mustFlushNow = nowMs >= head.deadlineMs ||
            realQueue.size >= profile.highWatermark() ||
            realQueue.size >= profile.coalesceMaxPackets ||
            head.payload.size > profile.coalesceThreshold ||
            expired

        if (!mustFlushNow && profile.coalesceEnabled && allCoalesceableLocked() && nowMs < head.coalesceAtMs) {
            return null to (head.coalesceAtMs - nowMs)
        }

        if (effectiveVC && !mustFlushNow && profile.burstPackets > 0 && tokens <= 0 && nowMs < nextSlotAtMs) {
            val slotRem = nextSlotAtMs - nowMs
            val deadlineRem = (head.deadlineMs - nowMs).coerceAtLeast(0L)
            val wait = min(slotRem, deadlineRem)
            if (wait > 0L) return null to wait
        }

        val item = popRealLocked()
        if (effectiveVC && tokens > 0) {
            tokens--
        }
        lastRealOutMs = nowMs
        nextKaAtMs = UNSET_TIME_MS
        return item to 0L
    }

    private fun allCoalesceableLocked(): Boolean {
        if (realQueue.isEmpty() || realQueue.size >= profile.coalesceMaxPackets) return false
        var total = 0
        for (item in realQueue) {
            if (item.payload.size > profile.coalesceThreshold) return false
            total += item.payload.size
        }
        return total < profile.coalesceMaxBytes
    }

    private fun popRealLocked(): SendItem {
        val head = realQueue.first()
        if (profile.coalesceEnabled && !expired && realQueue.size >= 2 && head.payload.size <= profile.coalesceThreshold) {
            val targetMaxOuter = when {
                profile.targetMaxOuter > 0 && maxOuter > 0 -> min(profile.targetMaxOuter, maxOuter)
                profile.targetMaxOuter > 0 -> profile.targetMaxOuter
                else -> maxOuter
            }
            val avail = targetMaxOuter - Argon4Crypto.OVERHEAD_V5 - 12
            val maxBatchRaw = if (avail in 1 until profile.coalesceMaxBytes) avail else profile.coalesceMaxBytes

            val batch = ArrayList<ByteArray>(profile.coalesceMaxPackets)
            var batchBytes = Argon4Profile.BATCH_HEADER_SIZE
            var consumed = 0
            for (cand in realQueue) {
                if (batch.size >= profile.coalesceMaxPackets) break
                if (cand.payload.size > profile.coalesceThreshold) break
                val needed = Argon4Profile.BATCH_ITEM_HEADER_SIZE + cand.payload.size
                if (batch.isNotEmpty() && batchBytes + needed > maxBatchRaw) break
                batch.add(cand.payload)
                batchBytes += needed
                consumed += cand.payload.size
            }
            if (batch.size >= 2) {
                val encoded = Argon4Profile.packBatchPlaintext(batch)
                repeat(batch.size) { realQueue.removeFirst() }
                queuedBytes -= consumed
                return SendItem(
                    frameType = Argon4Crypto.FRAME_BATCH,
                    payload = encoded,
                    batched = batch.size,
                )
            }
        }
        realQueue.removeFirst()
        queuedBytes -= head.payload.size
        return SendItem(
            frameType = Argon4Crypto.FRAME_DATA,
            payload = head.payload,
        )
    }

    private fun stepIdleLocked(nowMs: Long): SendItem? {
        if (shouldEmitVideoFeedbackLocked(nowMs)) {
            if (nextFbAtMs == UNSET_TIME_MS) {
                scheduleFeedbackLocked(nowMs)
                return null
            }
            if (nowMs >= nextFbAtMs) {
                scheduleFeedbackLocked(nowMs)
                return SendItem(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    payload = Argon4Profile.KEEPALIVE_FEEDBACK_PAYLOAD.toByteArray(Charsets.UTF_8),
                    isPing = false,
                )
            }
        }
        if (keepaliveEnabled) {
            if (nextKaAtMs == UNSET_TIME_MS) {
                scheduleKeepaliveLocked(nowMs)
                return null
            }
            if (nowMs >= nextKaAtMs) {
                scheduleKeepaliveLocked(nowMs)
                return SendItem(
                    frameType = Argon4Crypto.FRAME_KEEPALIVE,
                    payload = Argon4Profile.KEEPALIVE_PING_PAYLOAD.toByteArray(Charsets.UTF_8),
                    isPing = true,
                )
            }
        }
        return null
    }

    private fun computeIdleWaitLocked(nowMs: Long): Long {
        var best = Long.MAX_VALUE
        fun consider(t: Long) {
            if (t == UNSET_TIME_MS) return
            val d = (t - nowMs).coerceAtLeast(0L)
            if (d < best) best = d
        }
        if (shouldEmitVideoFeedbackLocked(nowMs)) {
            if (nextFbAtMs == UNSET_TIME_MS) scheduleFeedbackLocked(nowMs)
            consider(nextFbAtMs)
        } else {
            nextFbAtMs = UNSET_TIME_MS
        }
        if (keepaliveEnabled) {
            if (nextKaAtMs == UNSET_TIME_MS) scheduleKeepaliveLocked(nowMs)
            consider(nextKaAtMs)
        }
        if (profile.maxDurationMs > 0L && !expired) {
            consider(startedAtMs + profile.maxDurationMs)
        }
        return if (best == Long.MAX_VALUE) 30_000L else best
    }

    private fun shouldEmitVideoFeedbackLocked(nowMs: Long): Boolean {
        if (profile.name != Argon4Profile.PROFILE_VIDEO_CALL || expired || profile.lowPowerMode) return false
        val inRecent = lastRealInMs != UNSET_TIME_MS && (nowMs - lastRealInMs) < 3_000L
        if (isServer) return inRecent
        val outRecent = lastRealOutMs != UNSET_TIME_MS && (nowMs - lastRealOutMs) < 15_000L
        return inRecent || outRecent
    }

    private fun scheduleFeedbackLocked(nowMs: Long) {
        val inRecent = lastRealInMs != UNSET_TIME_MS && (nowMs - lastRealInMs) < 3_000L
        val outRecent = lastRealOutMs != UNSET_TIME_MS && (nowMs - lastRealOutMs) < 3_000L
        val active = inRecent || outRecent || isServer
        val minI = (if (active) profile.feedbackMinIntervalMs else profile.feedbackIdleMinMs).coerceAtLeast(120L)
        val maxI = max(minI, if (active) profile.feedbackMaxIntervalMs else profile.feedbackIdleMaxMs)
        nextFbAtMs = nowMs + Argon4Timing.randomDurationBetween(minI, maxI, randUnit)
    }

    private fun scheduleKeepaliveLocked(nowMs: Long) {
        var minI = profile.keepaliveMinIdleMs
        var maxI = profile.keepaliveMaxIdleMs
        if (expired || profile.name != Argon4Profile.PROFILE_VIDEO_CALL) {
            if (minI <= 0L || minI < 10_000L) {
                minI = ArgonTimingConfig.DEFAULT_KEEPALIVE_MIN_IDLE_MS
                maxI = ArgonTimingConfig.DEFAULT_KEEPALIVE_MAX_IDLE_MS
            }
        } else if (profile.lowPowerMode) {
            minI = profile.deepIdleMinKeepaliveMs
            maxI = profile.deepIdleMaxKeepaliveMs
        } else {
            val lastAct = max(lastRealOutMs, lastRealInMs)
            if (lastAct != UNSET_TIME_MS && (nowMs - lastAct) >= 45_000L && profile.deepIdleMinKeepaliveMs > 0L) {
                minI = profile.deepIdleMinKeepaliveMs
                maxI = profile.deepIdleMaxKeepaliveMs
            }
        }
        if (minI <= 0L) minI = ArgonTimingConfig.DEFAULT_KEEPALIVE_MIN_IDLE_MS
        if (maxI < minI) maxI = minI
        nextKaAtMs = nowMs + Argon4Timing.randomDurationBetween(minI, maxI, randUnit)
    }

    private suspend fun emitItem(item: SendItem) {
        val seq = nextSeq()
        val wire = Argon4Crypto.sealPacketWithOptions(
            frameType = item.frameType,
            salt = saltCopy,
            psk = pskCopy,
            key = key,
            seq = seq,
            plaintext = item.payload,
            options = sealOptions,
        )
        writeWire(wire)
        when (item.frameType) {
            Argon4Crypto.FRAME_DATA -> {
                sentDataFrames.incrementAndGet()
                tracker.touch()
            }
            Argon4Crypto.FRAME_BATCH -> {
                sentBatchFrames.incrementAndGet()
                coalescedPackets.addAndGet(item.batched.toLong())
                tracker.touch()
            }
            Argon4Crypto.FRAME_KEEPALIVE -> {
                if (item.isPing) {
                    sentKeepalives.incrementAndGet()
                } else {
                    sentControlFrames.incrementAndGet()
                }
            }
        }
    }

    private fun signalWake() {
        notifyCh.trySend(Unit)
    }

    private companion object {
        const val UNSET_TIME_MS = Long.MIN_VALUE
    }
}
