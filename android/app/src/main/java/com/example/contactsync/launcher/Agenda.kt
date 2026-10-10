package com.example.contactsync.launcher

import java.time.Duration
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter
import java.util.Locale

/** Вхождение события календаря (повторяющиеся события раскрыты в отдельные вхождения). */
data class AgendaEvent(
    val title: String,
    /** Начало и конец, мс UTC. У событий на весь день — полночь UTC, как хранит CalendarContract. */
    val begin: Long,
    val end: Long,
    val allDay: Boolean,
    val color: Int,
)

/** Строка ленты «Ближайшее». */
data class AgendaRow(
    val title: String,
    /** «сегодня», «завтра», «пн 12». */
    val day: String,
    /** «19:00», «19:00–20:30», «весь день». */
    val time: String,
    /** Для ближайшего события: «через 40 мин», «идёт». null — без подсветки. */
    val soon: String?,
    val color: Int,
    /** Начало, мс UTC — чтобы открыть этот момент в приложении календаря. */
    val begin: Long,
)

/**
 * Лента ближайших событий: закончившиеся отбрасываются, идущие и начинающиеся
 * в ближайшие 2 часа подсвечиваются. События на весь день хранятся в полночь UTC,
 * поэтому их дату берём в UTC, а не в поясе устройства — иначе они уедут на соседний день.
 */
object Agenda {
    private val SOON = Duration.ofHours(2)
    private val RU = Locale.forLanguageTag("ru")
    private val TIME = DateTimeFormatter.ofPattern("HH:mm", RU)
    private val WEEKDAY = DateTimeFormatter.ofPattern("EE d", RU)

    fun rows(events: List<AgendaEvent>, now: Instant, zone: ZoneId, limit: Int = 6): List<AgendaRow> {
        val today = now.atZone(zone).toLocalDate()
        return events
            .filter { if (it.allDay) it.endDate() > today else it.end > now.toEpochMilli() }
            .sortedWith(compareBy({ it.startDate(zone).coerceAtLeast(today) }, { !it.allDay }, { it.begin }))
            .take(limit)
            .map { event ->
                val start = event.startDate(zone).coerceAtLeast(today)
                AgendaRow(
                    title = event.title.ifBlank { "Без названия" },
                    day = dayLabel(start, today),
                    time = if (event.allDay) "весь день" else timeRange(event, zone),
                    soon = if (event.allDay) null else soonLabel(event, now),
                    color = event.color,
                    begin = event.begin,
                )
            }
    }

    private fun AgendaEvent.startDate(zone: ZoneId): LocalDate =
        Instant.ofEpochMilli(begin).atZone(if (allDay) ZoneOffset.UTC else zone).toLocalDate()

    private fun AgendaEvent.endDate(): LocalDate = Instant.ofEpochMilli(end).atZone(ZoneOffset.UTC).toLocalDate()

    private fun dayLabel(date: LocalDate, today: LocalDate): String = when (date) {
        today -> "сегодня"
        today.plusDays(1) -> "завтра"
        else -> date.format(WEEKDAY)
    }

    private fun timeRange(event: AgendaEvent, zone: ZoneId): String {
        val start = Instant.ofEpochMilli(event.begin).atZone(zone)
        val end = Instant.ofEpochMilli(event.end).atZone(zone)
        // Многодневное событие: конец в другой день — показываем только начало.
        return if (event.end <= event.begin || end.toLocalDate() != start.toLocalDate()) {
            start.format(TIME)
        } else {
            "${start.format(TIME)}–${end.format(TIME)}"
        }
    }

    private fun soonLabel(event: AgendaEvent, now: Instant): String? {
        val untilStart = Duration.between(now, Instant.ofEpochMilli(event.begin))
        return when {
            untilStart.isNegative || untilStart.isZero -> "идёт"
            untilStart > SOON -> null
            untilStart.toMinutes() < 60 -> "через ${untilStart.toMinutes().coerceAtLeast(1)} мин"
            else -> "через ${untilStart.toHours()} ч ${untilStart.toMinutesPart()} мин"
        }
    }
}
