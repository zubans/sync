package com.example.contactsync.kb

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
    fun `parts after the first are numbered`() {
        val start = LocalDateTime.of(2026, 10, 10, 14, 30)
        assertEquals("Встреча 10.10 14:30", MeetingPlan.title(start, 1))
        assertEquals("Встреча 10.10 14:30 · часть 2", MeetingPlan.title(start, 2))
    }
}
