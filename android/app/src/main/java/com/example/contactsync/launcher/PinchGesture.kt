package com.example.contactsync.launcher

import java.io.DataInputStream
import java.io.EOFException
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.concurrent.thread
import kotlin.math.hypot

/** Палец на экране: номер касания и координаты в пикселях. */
data class Touch(val id: Long, val x: Float, val y: Float)

/**
 * Щипок тремя и более пальцами — как жест Launchpad на macOS: пальцы сводятся к центру.
 * Разброс — среднее расстояние пальцев до их центра. Щипок засчитан, когда разброс упал до
 * [PINCH_RATIO] от начального; начальный — с момента, когда на экране последний раз поменялся набор пальцев.
 * Слишком тесно поставленные пальцы (разброс меньше [minSpread]) жестом не считаются: свести их уже некуда.
 * Один жест срабатывает один раз — следующий начинается, когда все пальцы подняты.
 */
class PinchDetector(private val minSpread: Float) {
    private var fingers: Set<Long> = emptySet()
    private var startSpread = 0f
    private var fired = false

    /** Кадр касания со всеми пальцами на экране. true — щипок только что совершён. */
    fun onFrame(touches: List<Touch>): Boolean {
        if (touches.isEmpty()) fired = false
        if (fired || touches.size < MIN_FINGERS) {
            fingers = emptySet()
            return false
        }
        val ids = touches.mapTo(HashSet()) { it.id }
        val spread = spread(touches)
        if (ids != fingers) {
            fingers = ids
            startSpread = spread
            return false
        }
        if (startSpread < minSpread || spread > startSpread * PINCH_RATIO) return false
        fired = true
        return true
    }

    private fun spread(touches: List<Touch>): Float {
        val cx = touches.map { it.x }.average().toFloat()
        val cy = touches.map { it.y }.average().toFloat()
        return touches.map { hypot(it.x - cx, it.y - cy) }.average().toFloat()
    }

    companion object {
        const val MIN_FINGERS = 3
        const val PINCH_RATIO = 0.6f

        /** Наименьший начальный разброс пальцев. */
        const val MIN_SPREAD_DP = 40
    }
}

/**
 * Касания из событий ядра (`/dev/input/eventN`) по протоколу multitouch B: пальцы живут в слотах,
 * `ABS_MT_TRACKING_ID` занимает слот (−1 — палец поднят), координаты приходят только при изменении.
 * Поэтому координаты слота переживают касание: новый палец в той же точке координат не пришлёт.
 * Слот, координат которого мы ещё не видели (они не менялись с тех пор, как начали читать), пропускаем.
 * Кадр заканчивается `SYN_REPORT`.
 */
class MultiTouchDecoder {
    private class Slot(var id: Long = -1, var x: Float = Float.NaN, var y: Float = Float.NaN)

    private val slots = HashMap<Int, Slot>()
    private var slot = 0

    /** Событие ядра. Возвращает пальцы на экране в конце кадра, иначе null. */
    fun onEvent(type: Int, code: Int, value: Int): List<Touch>? {
        when (type) {
            EV_ABS -> when (code) {
                ABS_MT_SLOT -> slot = value
                ABS_MT_TRACKING_ID -> slots.getOrPut(slot) { Slot() }.id = value.toLong()
                ABS_MT_POSITION_X -> slots.getOrPut(slot) { Slot() }.x = value.toFloat()
                ABS_MT_POSITION_Y -> slots.getOrPut(slot) { Slot() }.y = value.toFloat()
            }
            EV_SYN -> when (code) {
                SYN_REPORT -> return slots.values.filter { it.id >= 0 && !it.x.isNaN() && !it.y.isNaN() }.map { Touch(it.id, it.x, it.y) }
                // Ядро потеряло события — чьи слоты живы, неизвестно; начинаем с чистого листа.
                SYN_DROPPED -> slots.clear()
            }
        }
        return null
    }

    companion object {
        const val EV_SYN = 0x00
        const val EV_ABS = 0x03
        const val SYN_REPORT = 0
        const val SYN_DROPPED = 3
        const val ABS_MT_SLOT = 0x2f
        const val ABS_MT_POSITION_X = 0x35
        const val ABS_MT_POSITION_Y = 0x36
        const val ABS_MT_TRACKING_ID = 0x39
    }
}

/** Поиск сенсорного экрана в `/proc/bus/input/devices`. */
object TouchDevices {
    private const val INPUT_PROP_DIRECT = 1

    /**
     * Узел события (`event4`) устройства, которое касаются прямо по экрану (`INPUT_PROP_DIRECT`)
     * и которое сообщает координаты пальцев (`ABS_MT_POSITION_X`). Нет такого — null.
     * Битовые маски ядро печатает словами по 64 бита (ядро 64-битное), младшее слово — последнее.
     */
    fun find(devices: String): String? = devices.split(Regex("\n\\s*\n")).firstNotNullOfOrNull { block ->
        val lines = block.lines().map { it.trim() }
        fun bits(name: String): ULong? =
            lines.firstOrNull { it.startsWith("B: $name=") }?.substringAfter('=')?.trim()?.split(' ')?.last()?.toULongOrNull(16)
        val prop = bits("PROP") ?: return@firstNotNullOfOrNull null
        val abs = bits("ABS") ?: return@firstNotNullOfOrNull null
        if (prop and (1uL shl INPUT_PROP_DIRECT) == 0uL) return@firstNotNullOfOrNull null
        if (abs and (1uL shl MultiTouchDecoder.ABS_MT_POSITION_X) == 0uL) return@firstNotNullOfOrNull null
        lines.firstOrNull { it.startsWith("H: Handlers=") }?.substringAfter('=')?.split(' ')?.firstOrNull { it.startsWith("event") }
    }
}

/**
 * Щипок тремя пальцами поверх любых приложений. Касаний чужих окон приложению не видно, поэтому
 * события сенсора читаются из ядра: `cat /dev/input/eventN` под root (его выдаёт phh Superuser).
 * Касания при этом доходят до приложений как обычно — мы только подсматриваем.
 * Нет root или сенсора — тихо выходим; щипок тогда работает только на главном экране.
 */
class RootPinchWatcher(private val minSpread: Float, private val onPinch: () -> Unit) {
    @Volatile private var stopped = false
    @Volatile private var process: Process? = null

    fun start() {
        thread(name = "pinch-watcher", isDaemon = true) {
            while (!stopped) {
                val ok = runCatching { watch() }.isSuccess
                if (!ok || stopped) break
                // cat завершился сам (например, сенсор переподключился) — подождём и найдём его снова.
                Thread.sleep(RETRY_MS)
            }
        }
    }

    fun stop() {
        stopped = true
        process?.destroy()
    }

    private fun watch() {
        val devices = su("cat /proc/bus/input/devices").inputStream.bufferedReader().use { it.readText() }
        val node = TouchDevices.find(devices) ?: error("сенсорный экран не найден")
        val cat = su("exec cat /dev/input/$node")
        process = cat
        val decoder = MultiTouchDecoder()
        val detector = PinchDetector(minSpread)
        val record = ByteArray(EVENT_SIZE)
        val buffer = ByteBuffer.wrap(record).order(ByteOrder.LITTLE_ENDIAN)
        DataInputStream(cat.inputStream.buffered()).use { input ->
            try {
                while (!stopped) {
                    input.readFully(record)
                    val type = buffer.getShort(16).toInt() and 0xffff
                    val code = buffer.getShort(18).toInt() and 0xffff
                    val touches = decoder.onEvent(type, code, buffer.getInt(20)) ?: continue
                    if (detector.onFrame(touches)) onPinch()
                }
            } catch (_: EOFException) {
            } finally {
                cat.destroy()
            }
        }
    }

    private fun su(command: String): Process = ProcessBuilder("su", "-c", command).start()

    private companion object {
        /** `struct input_event` у 64-битного cat: timeval (16 байт), type, code (по 2), value (4). */
        const val EVENT_SIZE = 24
        const val RETRY_MS = 10_000L
    }
}
