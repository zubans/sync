package com.example.contactsync.launcher

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class PinchGestureTest {

    /** Пальцы на окружности радиуса [radius] вокруг (500, 500). */
    private fun hand(radius: Float, count: Int = 3) = (0 until count).map { i ->
        val angle = 2 * Math.PI * i / count
        Touch(i.toLong(), 500 + radius * Math.cos(angle).toFloat(), 500 + radius * Math.sin(angle).toFloat())
    }

    @Test
    fun `three fingers brought together fire once`() {
        val detector = PinchDetector(minSpread = 50f)
        assertFalse(detector.onFrame(hand(200f)))
        assertFalse(detector.onFrame(hand(150f)))
        assertTrue(detector.onFrame(hand(110f)))
        assertFalse(detector.onFrame(hand(50f)))
    }

    @Test
    fun `next pinch needs all fingers lifted`() {
        val detector = PinchDetector(minSpread = 50f)
        detector.onFrame(hand(200f))
        assertTrue(detector.onFrame(hand(100f)))
        detector.onFrame(hand(100f).take(2))
        assertFalse(detector.onFrame(hand(200f)))
        assertFalse(detector.onFrame(hand(100f)))
        detector.onFrame(emptyList())
        detector.onFrame(hand(200f))
        assertTrue(detector.onFrame(hand(100f)))
    }

    @Test
    fun `two fingers, swipe and tight fingers are not a pinch`() {
        val two = PinchDetector(minSpread = 50f)
        two.onFrame(hand(200f, count = 2))
        assertFalse(two.onFrame(hand(50f, count = 2)))

        val swipe = PinchDetector(minSpread = 50f)
        swipe.onFrame(hand(200f))
        assertFalse(swipe.onFrame(hand(200f).map { it.copy(y = it.y - 400) }))

        val tight = PinchDetector(minSpread = 50f)
        tight.onFrame(hand(40f))
        assertFalse(tight.onFrame(hand(10f)))
    }

    @Test
    fun `new finger restarts the pinch from its spread`() {
        val detector = PinchDetector(minSpread = 50f)
        detector.onFrame(hand(200f))
        assertFalse(detector.onFrame(hand(130f, count = 4)))
        assertTrue(detector.onFrame(hand(70f, count = 4)))
    }

    @Test
    fun `decoder tracks protocol B slots`() {
        val d = MultiTouchDecoder()
        val abs = MultiTouchDecoder.EV_ABS
        fun report() = d.onEvent(MultiTouchDecoder.EV_SYN, MultiTouchDecoder.SYN_REPORT, 0)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_SLOT, 0)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_TRACKING_ID, 7)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_POSITION_X, 100)
        assertNull(d.onEvent(abs, MultiTouchDecoder.ABS_MT_POSITION_Y, 200))
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_SLOT, 1)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_TRACKING_ID, 8)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_POSITION_X, 300)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_POSITION_Y, 400)
        assertEquals(setOf(Touch(7, 100f, 200f), Touch(8, 300f, 400f)), report()!!.toSet())

        d.onEvent(abs, MultiTouchDecoder.ABS_MT_SLOT, 0)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_POSITION_X, 150)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_SLOT, 1)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_TRACKING_ID, -1)
        assertEquals(listOf(Touch(7, 150f, 200f)), report())

        // Новый палец там же, где был прежний в этом слоте: координаты ядро не повторяет.
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_SLOT, 1)
        d.onEvent(abs, MultiTouchDecoder.ABS_MT_TRACKING_ID, 9)
        assertEquals(setOf(Touch(7, 150f, 200f), Touch(9, 300f, 400f)), report()!!.toSet())
    }

    @Test
    fun `touchscreen found by direct prop and multitouch axes`() {
        val devices = """
            I: Bus=0019 Vendor=0001 Product=0001 Version=0100
            N: Name="mtk-kpd"
            H: Handlers=event1
            B: PROP=0
            B: EV=3
            B: KEY=1c000000000000 0

            I: Bus=0000 Vendor=0000 Product=0000 Version=0000
            N: Name="himax-touchscreen"
            H: Handlers=event4
            B: PROP=2
            B: EV=b
            B: KEY=400 0 0 0
            B: ABS=665800000000000
        """.trimIndent()
        assertEquals("event4", TouchDevices.find(devices))
        assertNull(TouchDevices.find(devices.replace("B: PROP=2", "B: PROP=0")))
    }
}
