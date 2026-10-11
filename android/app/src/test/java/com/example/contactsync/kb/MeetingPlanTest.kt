package com.example.contactsync.kb

import org.junit.Assert.assertArrayEquals
import org.junit.Assert.assertEquals
import org.junit.Test
import java.time.LocalDateTime

class MeetingPlanTest {
    @Test
    fun `segment fits under kb limit with margin`() {
        assertEquals(59 * 60_000L + 30_000L, MeetingPlan.segmentMillis(60))
        assertEquals(30_000L, MeetingPlan.segmentMillis(0))
    }

    @Test
    fun `default title depends on what was recorded`() {
        val start = LocalDateTime.of(2026, 10, 10, 14, 30)
        assertEquals("Встреча 10.10 14:30", MeetingPlan.title(RecordingSource.MIC, start))
        assertEquals("Созвон 10.10 14:30", MeetingPlan.title(RecordingSource.CALL, start))
        assertEquals("Звонок 10.10 14:30", MeetingPlan.title(RecordingSource.PHONE, start))
    }

    @Test
    fun `parts after the first are numbered`() {
        assertEquals("Созвон с заказчиком", MeetingPlan.partTitle("Созвон с заказчиком", 1))
        assertEquals("Созвон с заказчиком · часть 2", MeetingPlan.partTitle("Созвон с заказчиком", 2))
    }

    @Test
    fun `duration shows hours only when needed`() {
        assertEquals("07:05", MeetingPlan.duration(425_000))
        assertEquals("1:02:03", MeetingPlan.duration(3_723_000))
    }

    @Test
    fun `mix adds voices and clips instead of overflowing`() {
        val base = shortArrayOf(1000, 30000, -30000, 5)
        AudioMix.mixInto(base, 4, shortArrayOf(500, 10000, -10000), 3)
        assertArrayEquals(shortArrayOf(1500, Short.MAX_VALUE, Short.MIN_VALUE, 5), base)
    }
}
