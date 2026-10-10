package com.example.contactsync.calendar

import com.example.contactsync.data.ServerEvent
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.Instant

class EventMappingTest {
    private fun ms(iso: String) = Instant.parse(iso).toEpochMilli()

    @Test
    fun `timed event goes to server in utc`() {
        val local = LocalEvent(title = "Созвон", dtStart = ms("2026-10-10T12:00:00Z"), dtEnd = ms("2026-10-10T12:30:00Z"), timeZone = "Europe/Moscow")
        val server = EventMapping.toServer(local, "id-1", baseRevision = 5)
        assertEquals("2026-10-10T12:00:00Z", server.start)
        assertEquals("2026-10-10T12:30:00Z", server.end)
        assertEquals(5, server.baseRevision)
        assertNull(server.rrule)
    }

    @Test
    fun `all day event uses dates and at least one day`() {
        val local = LocalEvent(title = "ДР", dtStart = ms("2026-10-11T00:00:00Z"), dtEnd = ms("2026-10-11T00:00:00Z"), allDay = true)
        val server = EventMapping.toServer(local, "id-2", baseRevision = null)
        assertEquals("2026-10-11", server.start)
        assertEquals("2026-10-12", server.end)
    }

    @Test
    fun `recurring local event takes end from duration and drops rrule prefix`() {
        val local = LocalEvent(title = "Планёрка", dtStart = ms("2026-10-12T07:00:00Z"), dtEnd = null, duration = "P900S", rrule = "RRULE:FREQ=WEEKLY;BYDAY=MO")
        val server = EventMapping.toServer(local, "id-3", baseRevision = null)
        assertEquals("2026-10-12T07:15:00Z", server.end)
        assertEquals("FREQ=WEEKLY;BYDAY=MO", server.rrule)
    }

    @Test
    fun `server recurring event becomes dtstart with duration`() {
        val local = EventMapping.toLocal(
            ServerEvent(id = "id-4", revision = 7, title = "Планёрка", start = "2026-10-12T07:00:00Z", end = "2026-10-12T07:15:00Z", rrule = "FREQ=WEEKLY"),
            timeZone = "Europe/Moscow",
        )
        assertNull(local.dtEnd)
        assertEquals("P900S", local.duration)
        assertEquals("Europe/Moscow", local.timeZone)
        assertEquals(7, local.revision)
    }

    @Test
    fun `server all day event is stored at utc midnight`() {
        val local = EventMapping.toLocal(
            ServerEvent(id = "id-5", title = "Дача", start = "2026-10-17", end = "2026-10-19", allDay = true, color = "#34A853"),
            timeZone = "Europe/Moscow",
        )
        assertEquals(ms("2026-10-17T00:00:00Z"), local.dtStart)
        assertEquals(ms("2026-10-19T00:00:00Z"), local.dtEnd)
        assertEquals("UTC", local.timeZone)
        assertEquals("#34A853", EventMapping.colorHex(local.color!!))
    }

    @Test
    fun `durations in android and rfc forms`() {
        assertEquals(3600L, EventMapping.parseDurationSeconds("P3600S"))
        assertEquals(5400L, EventMapping.parseDurationSeconds("PT1H30M"))
        assertEquals(86400L, EventMapping.parseDurationSeconds("P1D"))
        assertEquals(7 * 86400L, EventMapping.parseDurationSeconds("P1W"))
        assertNull(EventMapping.parseDurationSeconds("час"))
        assertEquals("P2D", EventMapping.duration(2 * 86_400_000L, allDay = true))
    }
}
