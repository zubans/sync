package com.example.argontunnel.crypto

import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import java.io.IOException
import java.io.InterruptedIOException
import java.net.PortUnreachableException
import java.net.SocketException
import java.net.SocketTimeoutException
import java.nio.channels.ClosedChannelException
import java.security.SecureRandom
import java.util.concurrent.atomic.AtomicLong
import kotlin.math.max
import kotlin.math.min
import kotlin.math.pow
import kotlin.math.roundToLong

/**
 * Проверенные границы таймингов handshake (exponential backoff + jitter)
 * и idle keepalive для клиента Argon4 без фиксированных периодических сигнатур.
 */
data class ArgonTimingConfig(
    val handshakeMaxAttempts: Int = DEFAULT_HANDSHAKE_MAX_ATTEMPTS,
    val handshakeInitialTimeoutMs: Long = DEFAULT_HANDSHAKE_INITIAL_TIMEOUT_MS,
    val handshakeMaxTimeoutMs: Long = DEFAULT_HANDSHAKE_MAX_TIMEOUT_MS,
    val handshakeMaxElapsedMs: Long = DEFAULT_HANDSHAKE_MAX_ELAPSED_MS,
    val handshakeBackoffFactor: Double = DEFAULT_HANDSHAKE_BACKOFF_FACTOR,
    val handshakeRetryMinPauseMs: Long = DEFAULT_HANDSHAKE_RETRY_MIN_PAUSE_MS,
    val handshakeRetryMaxPauseMs: Long = DEFAULT_HANDSHAKE_RETRY_MAX_PAUSE_MS,
    val handshakeJitterRatio: Double = DEFAULT_HANDSHAKE_JITTER_RATIO,
    val keepaliveMinIdleMs: Long = DEFAULT_KEEPALIVE_MIN_IDLE_MS,
    val keepaliveMaxIdleMs: Long = DEFAULT_KEEPALIVE_MAX_IDLE_MS,
    val sessionIdleTimeoutMs: Long = DEFAULT_SESSION_IDLE_TIMEOUT_MS,
) {
    companion object {
        const val DEFAULT_HANDSHAKE_MAX_ATTEMPTS = 5
        const val DEFAULT_HANDSHAKE_INITIAL_TIMEOUT_MS = 1_200L
        const val DEFAULT_HANDSHAKE_MAX_TIMEOUT_MS = 4_500L
        const val DEFAULT_HANDSHAKE_MAX_ELAPSED_MS = 15_000L
        const val DEFAULT_HANDSHAKE_BACKOFF_FACTOR = 1.7
        const val DEFAULT_HANDSHAKE_RETRY_MIN_PAUSE_MS = 120L
        const val DEFAULT_HANDSHAKE_RETRY_MAX_PAUSE_MS = 800L
        const val DEFAULT_HANDSHAKE_JITTER_RATIO = 0.30

        // Интервал [16с..28с] держит NAT-таблицы (>30с) и укладывается в серверный SessionIdleTimeout (600с).
        const val DEFAULT_KEEPALIVE_MIN_IDLE_MS = 16_000L
        const val DEFAULT_KEEPALIVE_MAX_IDLE_MS = 28_000L
        const val DEFAULT_SESSION_IDLE_TIMEOUT_MS = 600_000L
    }
}

/** Абстракция монотонных часов для детерминированного тестирования backoff и keepalive без реальных ожиданий. */
interface ArgonClock {
    fun nowMs(): Long
    suspend fun delayMs(durationMs: Long)
}

/** Системные монотонные часы на базе [System.nanoTime] и [delay]. */
object SystemArgonClock : ArgonClock {
    private val originNanos = System.nanoTime()

    override fun nowMs(): Long = (System.nanoTime() - originNanos) / 1_000_000L

    override suspend fun delayMs(durationMs: Long) {
        if (durationMs > 0L) {
            delay(durationMs)
        }
    }
}

/** Потокобезопасный трекер момента последней исходящей полезной передачи. */
class ActivityTracker(private val clock: ArgonClock) {
    private val lastOutboundMs = AtomicLong(clock.nowMs())

    /** Фиксирует исходящую передачу данных и сдвигает окно idle keepalive. */
    fun touch() {
        val now = clock.nowMs()
        while (true) {
            val prev = lastOutboundMs.get()
            if (now <= prev) return
            if (lastOutboundMs.compareAndSet(prev, now)) return
        }
    }

    /** Возвращает монотонную отметку последней полезной активности. */
    fun lastActiveMs(): Long = lastOutboundMs.get()

    /** Возвращает длительность текущего простоя в миллисекундах. */
    fun idleForMs(): Long = (clock.nowMs() - lastOutboundMs.get()).coerceAtLeast(0L)
}

/** Помечает ошибку рукопожатия как окончательную, чтобы не выполнять бессмысленные повторы. */
class NonRetryableHandshakeException(
    message: String,
    cause: Throwable? = null,
) : RuntimeException(message, cause)

/** Механизм нормирования таймингов, рандомизированного backoff handshake и idle keepalive. */
object Argon4Timing {
    private val secureRandom = SecureRandom()

    private fun defaultRandUnit(): Double = secureRandom.nextDouble()

    /** Заполняет нулевые поля значениями по умолчанию и проверяет инварианты границ. */
    fun normalizeConfig(input: ArgonTimingConfig = ArgonTimingConfig()): ArgonTimingConfig {
        require(
            input.handshakeMaxAttempts >= 0 &&
                input.handshakeInitialTimeoutMs >= 0L &&
                input.handshakeMaxTimeoutMs >= 0L &&
                input.handshakeMaxElapsedMs >= 0L &&
                input.handshakeBackoffFactor >= 0.0 &&
                input.handshakeRetryMinPauseMs >= 0L &&
                input.handshakeRetryMaxPauseMs >= 0L &&
                input.handshakeJitterRatio >= 0.0 &&
                input.keepaliveMinIdleMs >= 0L &&
                input.keepaliveMaxIdleMs >= 0L &&
                input.sessionIdleTimeoutMs >= 0L,
        ) { "Отрицательные параметры таймингов Argon4 недопустимы" }

        val cfg = ArgonTimingConfig(
            handshakeMaxAttempts = if (input.handshakeMaxAttempts == 0) ArgonTimingConfig.DEFAULT_HANDSHAKE_MAX_ATTEMPTS else input.handshakeMaxAttempts,
            handshakeInitialTimeoutMs = if (input.handshakeInitialTimeoutMs == 0L) ArgonTimingConfig.DEFAULT_HANDSHAKE_INITIAL_TIMEOUT_MS else input.handshakeInitialTimeoutMs,
            handshakeMaxTimeoutMs = if (input.handshakeMaxTimeoutMs == 0L) ArgonTimingConfig.DEFAULT_HANDSHAKE_MAX_TIMEOUT_MS else input.handshakeMaxTimeoutMs,
            handshakeMaxElapsedMs = if (input.handshakeMaxElapsedMs == 0L) ArgonTimingConfig.DEFAULT_HANDSHAKE_MAX_ELAPSED_MS else input.handshakeMaxElapsedMs,
            handshakeBackoffFactor = if (input.handshakeBackoffFactor == 0.0) ArgonTimingConfig.DEFAULT_HANDSHAKE_BACKOFF_FACTOR else input.handshakeBackoffFactor,
            handshakeRetryMinPauseMs = if (input.handshakeRetryMinPauseMs == 0L) ArgonTimingConfig.DEFAULT_HANDSHAKE_RETRY_MIN_PAUSE_MS else input.handshakeRetryMinPauseMs,
            handshakeRetryMaxPauseMs = if (input.handshakeRetryMaxPauseMs == 0L) ArgonTimingConfig.DEFAULT_HANDSHAKE_RETRY_MAX_PAUSE_MS else input.handshakeRetryMaxPauseMs,
            handshakeJitterRatio = if (input.handshakeJitterRatio == 0.0) ArgonTimingConfig.DEFAULT_HANDSHAKE_JITTER_RATIO else input.handshakeJitterRatio,
            keepaliveMinIdleMs = if (input.keepaliveMinIdleMs == 0L) ArgonTimingConfig.DEFAULT_KEEPALIVE_MIN_IDLE_MS else input.keepaliveMinIdleMs,
            keepaliveMaxIdleMs = if (input.keepaliveMaxIdleMs == 0L) ArgonTimingConfig.DEFAULT_KEEPALIVE_MAX_IDLE_MS else input.keepaliveMaxIdleMs,
            sessionIdleTimeoutMs = if (input.sessionIdleTimeoutMs == 0L) ArgonTimingConfig.DEFAULT_SESSION_IDLE_TIMEOUT_MS else input.sessionIdleTimeoutMs,
        )

        require(cfg.handshakeMaxAttempts in 1..20) {
            "handshakeMaxAttempts=${cfg.handshakeMaxAttempts} вне [1..20]"
        }
        require(cfg.handshakeInitialTimeoutMs in 20L..30_000L) {
            "handshakeInitialTimeoutMs=${cfg.handshakeInitialTimeoutMs} вне [20..30000]"
        }
        require(cfg.handshakeMaxTimeoutMs >= cfg.handshakeInitialTimeoutMs && cfg.handshakeMaxTimeoutMs <= 60_000L) {
            "handshakeMaxTimeoutMs=${cfg.handshakeMaxTimeoutMs} должен быть >= initial и <= 60000"
        }
        require(cfg.handshakeMaxElapsedMs >= cfg.handshakeInitialTimeoutMs && cfg.handshakeMaxElapsedMs <= 180_000L) {
            "handshakeMaxElapsedMs=${cfg.handshakeMaxElapsedMs} должен быть >= initial и <= 180000"
        }
        require(cfg.handshakeBackoffFactor.isFinite() && cfg.handshakeBackoffFactor in 1.1..4.0) {
            "handshakeBackoffFactor=${cfg.handshakeBackoffFactor} вне [1.1..4.0]"
        }
        require(
            cfg.handshakeRetryMinPauseMs >= 1L &&
                cfg.handshakeRetryMaxPauseMs >= cfg.handshakeRetryMinPauseMs &&
                cfg.handshakeRetryMaxPauseMs <= 15_000L,
        ) {
            "Некорректный диапазон handshakeRetryPause [${cfg.handshakeRetryMinPauseMs}..${cfg.handshakeRetryMaxPauseMs}]"
        }
        require(cfg.handshakeJitterRatio.isFinite() && cfg.handshakeJitterRatio in 0.05..0.75) {
            "handshakeJitterRatio=${cfg.handshakeJitterRatio} вне [0.05..0.75]"
        }
        require(cfg.keepaliveMinIdleMs >= 20L) {
            "keepaliveMinIdleMs=${cfg.keepaliveMinIdleMs} слишком мал"
        }
        require(cfg.keepaliveMaxIdleMs > cfg.keepaliveMinIdleMs) {
            "keepaliveMaxIdleMs (${cfg.keepaliveMaxIdleMs}) должен быть строго больше keepaliveMinIdleMs (${cfg.keepaliveMinIdleMs})"
        }
        require(cfg.sessionIdleTimeoutMs >= 100L) {
            "sessionIdleTimeoutMs=${cfg.sessionIdleTimeoutMs} слишком мал"
        }
        require(cfg.keepaliveMaxIdleMs * 2L <= cfg.sessionIdleTimeoutMs) {
            "keepaliveMaxIdleMs (${cfg.keepaliveMaxIdleMs}) не должен превышать половину sessionIdleTimeoutMs (${cfg.sessionIdleTimeoutMs})"
        }
        return cfg
    }

    /** Возвращает равномерно распределённое значение в `[minMs..maxMs]`. */
    fun randomDurationBetween(
        minMs: Long,
        maxMs: Long,
        randUnit: () -> Double = ::defaultRandUnit,
    ): Long {
        if (maxMs <= minMs) return minMs
        val u = randUnit().coerceIn(0.0, 1.0)
        return minMs + ((maxMs - minMs).toDouble() * u).roundToLong()
    }

    /** Вычисляет таймаут попытки handshake с экспоненциальным ростом, потолком и случайным jitter. */
    fun computeAttemptTimeoutMs(
        cfg: ArgonTimingConfig,
        attemptIndex: Int,
        remainingBudgetMs: Long,
        randUnit: () -> Double = ::defaultRandUnit,
    ): Long {
        if (remainingBudgetMs <= 0L) return 0L
        var base = cfg.handshakeInitialTimeoutMs.toDouble() * cfg.handshakeBackoffFactor.pow(attemptIndex.toDouble())
        if (!base.isFinite() || base > cfg.handshakeMaxTimeoutMs.toDouble()) {
            base = cfg.handshakeMaxTimeoutMs.toDouble()
        }
        val u = randUnit().coerceIn(0.0, 1.0)
        val factor = 1.0 + (u * 2.0 - 1.0) * cfg.handshakeJitterRatio
        val minAttemptMs = min(20L, remainingBudgetMs)
        val jittered = (base * factor).roundToLong().coerceIn(minAttemptMs, min(cfg.handshakeMaxTimeoutMs, remainingBudgetMs))
        return jittered
    }

    /**
     * Проверяет, свидетельствует ли исключение сокета о закрытии канала или потере сети,
     * при которых цикл keepalive должен немедленно освободить ресурсы.
     */
    fun isFatalKeepaliveError(err: Throwable): Boolean {
        if (err is CancellationException) return true
        if (err is ClosedChannelException) return true
        if (err is PortUnreachableException || err is SocketException) return true
        val msg = err.message?.lowercase().orEmpty()
        return msg.contains("socket closed") ||
            msg.contains("closed") ||
            msg.contains("bad file descriptor") ||
            msg.contains("network is unreachable") ||
            msg.contains("network down")
    }

    /** Проверяет, является ли исключение handshake окончательным (без повторов). */
    fun isNonRetryableHandshakeError(err: Throwable): Boolean {
        if (err is CancellationException || err is NonRetryableHandshakeException) return true
        if (err is Argon4ProtocolException) {
            return err.kind == Argon4ErrorKind.DOWNGRADE_REJECTED ||
                err.kind == Argon4ErrorKind.BAD_VERSION ||
                err.kind == Argon4ErrorKind.INVALID_PADDING ||
                err.kind == Argon4ErrorKind.INVALID_ENVELOPE ||
                err.kind == Argon4ErrorKind.TRAILING_DATA ||
                err.kind == Argon4ErrorKind.PROFILE_UNSUPPORTED
        }
        return false
    }

    /**
     * Выполняет UDP-рукопожатие с ограниченным числом попыток, рандомизированным exponential backoff
     * и повторной отправкой побайтно идентичного [wireRequest] (для idempotent handshake-cache сервера).
     */
    suspend fun <T> runHandshakeWithBackoff(
        config: ArgonTimingConfig,
        clock: ArgonClock = SystemArgonClock,
        randUnit: () -> Double = ::defaultRandUnit,
        wireRequest: ByteArray,
        setReadTimeoutMs: (Int) -> Unit,
        sendPacket: (ByteArray) -> Unit,
        receivePacket: () -> ByteArray?,
        parseResponse: (ByteArray) -> T?,
    ): T {
        val norm = normalizeConfig(config)
        val startMs = clock.nowMs()
        val overallDeadlineMs = startMs + norm.handshakeMaxElapsedMs
        var lastError: Throwable? = null

        for (attempt in 0 until norm.handshakeMaxAttempts) {
            if (!currentCoroutineContext().isActive) {
                throw CancellationException("Рукопожатие Argon4 отменено")
            }
            val nowMs = clock.nowMs()
            val remainingOverallMs = overallDeadlineMs - nowMs
            if (remainingOverallMs <= 0L) break

            val attemptTimeoutMs = computeAttemptTimeoutMs(norm, attempt, remainingOverallMs, randUnit)
            val attemptDeadlineMs = nowMs + attemptTimeoutMs

            try {
                sendPacket(wireRequest)
                while (currentCoroutineContext().isActive) {
                    val leftMs = attemptDeadlineMs - clock.nowMs()
                    if (leftMs <= 0L) {
                        lastError = SocketTimeoutException("Таймаут попытки handshake (${attempt + 1}/${norm.handshakeMaxAttempts})")
                        break
                    }
                    setReadTimeoutMs(leftMs.coerceIn(1L, Int.MAX_VALUE.toLong()).toInt())
                    val packet = try {
                        receivePacket()
                    } catch (timeout: SocketTimeoutException) {
                        lastError = timeout
                        break
                    }
                    if (packet == null) {
                        lastError = SocketTimeoutException("Пустой ответ handshake")
                        break
                    }
                    val parsed = try {
                        parseResponse(packet)
                    } catch (err: Throwable) {
                        if (isNonRetryableHandshakeError(err)) throw err
                        lastError = err
                        continue
                    }
                    if (parsed != null) {
                        return parsed
                    }
                }
            } catch (err: Throwable) {
                if (isNonRetryableHandshakeError(err)) throw err
                if (err is SocketException && isFatalKeepaliveError(err) && !currentCoroutineContext().isActive) {
                    throw err
                }
                lastError = err
            }

            if (attempt == norm.handshakeMaxAttempts - 1) break

            val pauseRemainingMs = overallDeadlineMs - clock.nowMs()
            if (pauseRemainingMs <= norm.handshakeRetryMinPauseMs) break

            val pauseMs = randomDurationBetween(norm.handshakeRetryMinPauseMs, norm.handshakeRetryMaxPauseMs, randUnit)
                .coerceAtMost(pauseRemainingMs)
            clock.delayMs(pauseMs)
        }

        throw IllegalStateException(
            "Сервер не ответил на рукопожатие туннеля после ${norm.handshakeMaxAttempts} попыток " +
                "(проверьте мастер-пароль, адрес и порт): ${lastError?.message ?: "таймаут"}",
            lastError,
        )
    }

    /**
     * Запускает адаптивный idle keepalive: пауза с jitter выбирается в `[keepaliveMinIdleMs..keepaliveMaxIdleMs]`
     * и сбрасывается при любой исходящей активности через [tracker].
     * Цикл немедленно завершается при отмене корутины или фатальной ошибке сокета.
     */
    suspend fun runIdleKeepalive(
        config: ArgonTimingConfig,
        clock: ArgonClock = SystemArgonClock,
        tracker: ActivityTracker = ActivityTracker(clock),
        randUnit: () -> Double = ::defaultRandUnit,
        isFatalError: (Throwable) -> Boolean = ::isFatalKeepaliveError,
        onError: ((Throwable) -> Unit)? = null,
        sendKeepalive: suspend () -> Unit,
    ) {
        val norm = normalizeConfig(config)
        var targetIdleMs = randomDurationBetween(norm.keepaliveMinIdleMs, norm.keepaliveMaxIdleMs, randUnit)
        var lastKeepaliveAtMs = clock.nowMs()

        while (currentCoroutineContext().isActive) {
            val anchorMs = max(tracker.lastActiveMs(), lastKeepaliveAtMs)
            val nowMs = clock.nowMs()
            val idleForMs = (nowMs - anchorMs).coerceAtLeast(0L)
            if (idleForMs < targetIdleMs) {
                val remainingMs = (targetIdleMs - idleForMs).coerceAtLeast(1L)
                clock.delayMs(remainingMs)
                continue
            }

            try {
                sendKeepalive()
            } catch (err: Throwable) {
                if (err is CancellationException) throw err
                onError?.invoke(err)
                if (!currentCoroutineContext().isActive || isFatalError(err)) {
                    return
                }
            }

            lastKeepaliveAtMs = clock.nowMs()
            targetIdleMs = randomDurationBetween(norm.keepaliveMinIdleMs, norm.keepaliveMaxIdleMs, randUnit)
        }
    }
}
