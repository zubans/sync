package com.example.contactsync.launcher

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId
import java.time.ZoneOffset

class AgendaTest {
    private val zone = ZoneId.of("Europe/Moscow")
    private val now = LocalDateTime.of(2026, 10, 10, 11, 20).atZone(zone).toInstant()

    private fun at(day: Int, hour: Int, minute: Int = 0) =
        LocalDateTime.of(2026, 10, day, hour, minute).atZone(zone).toInstant().toEpochMilli()

    private fun allDay(date: LocalDate) =
        AgendaEvent("ДР мамы", date.atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(),
            date.plusDays(1).atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli(), allDay = true, color = 0)

    @Test
    fun `event starting soon is highlighted with minutes left`() {
        val row = Agenda.rows(listOf(AgendaEvent("Созвон", at(10, 12), at(10, 12, 30), false, 0)), now, zone).single()
        assertEquals("сегодня", row.day)
        assertEquals("12:00–12:30", row.time)
        assertEquals("через 40 мин", row.soon)
    }

    @Test
    fun `running event is shown as ongoing and finished one is dropped`() {
        val rows = Agenda.rows(
            listOf(
                AgendaEvent("Утренняя встреча", at(10, 9), at(10, 10), false, 0),
                AgendaEvent("Обед", at(10, 11), at(10, 12), false, 0),
            ),
            now, zone,
        )
        assertEquals(listOf("Обед"), rows.map { it.title })
        assertEquals("идёт", rows.single().soon)
    }

    @Test
    fun `distant event is not highlighted`() {
        val row = Agenda.rows(listOf(AgendaEvent("Тренировка", at(10, 19), at(10, 20, 30), false, 0)), now, zone).single()
        assertNull(row.soon)
    }

    @Test
    fun `all day event keeps its date regardless of device time zone`() {
        val row = Agenda.rows(listOf(allDay(LocalDate.of(2026, 10, 11))), now, ZoneId.of("America/New_York")).single()
        assertEquals("завтра", row.day)
        assertEquals("весь день", row.time)
    }

    @Test
    fun `all day events go first within a day and later days get weekday labels`() {
        val rows = Agenda.rows(
            listOf(
                AgendaEvent("Купон", at(12, 10), at(12, 11), false, 0),
                AgendaEvent("Тренировка", at(10, 19), at(10, 20), false, 0),
                allDay(LocalDate.of(2026, 10, 10)),
            ),
            now, zone,
        )
        assertEquals(listOf("ДР мамы", "Тренировка", "Купон"), rows.map { it.title })
        assertEquals("пн 12", rows.last().day)
    }

    @Test
    fun `same event in Google and sync calendars is one row with both colors, opening the sync copy`() {
        val google = AgendaEvent("В театр  на ревизора", at(17, 17), at(17, 18), false, color = 0x4285F4, eventId = 1)
        val sync = AgendaEvent("в театр на ревизора", at(17, 17), at(17, 18), false, color = 0x34A853, eventId = 2, fromSync = true)
        val other = AgendaEvent("Винзавод", at(17, 19), at(17, 21), false, color = 0x4285F4, eventId = 3)

        val rows = Agenda.rows(listOf(google, sync, other), now, zone)

        assertEquals(2, rows.size)
        assertEquals(2L, rows[0].eventId)
        assertEquals(listOf(0x34A853, 0x4285F4), rows[0].colors)
        assertEquals(listOf(0x4285F4), rows[1].colors)
    }

    @Test
    fun `today's birthday is pinned first all day even after its time`() {
        val passed = AgendaEvent("Днюха у Ромы", at(10, 9, 30), at(10, 10, 30), false, 0)
        val meeting = AgendaEvent("Созвон", at(10, 12), at(10, 12, 30), false, 0)
        val tomorrow = AgendaEvent("ДР мамы", at(11, 9), at(11, 10), false, 0)

        val rows = Agenda.rows(listOf(meeting, tomorrow, passed), now, zone)

        assertEquals(listOf("Днюха у Ромы", "Созвон", "ДР мамы"), rows.map { it.title })
        assertNull(rows[0].soon)
    }

    @Test
    fun `birthday titles are recognized`() {
        listOf("День рождения Вани", "днюха у маслова", "ДР мамы", "Света Богданова ДР", "Mom's birthday").forEach {
            assertEquals(it, true, Agenda.isBirthday(it))
        }
        listOf("Драмтеатр", "Дрова", "Винзавод").forEach { assertEquals(it, false, Agenda.isBirthday(it)) }
    }
}
