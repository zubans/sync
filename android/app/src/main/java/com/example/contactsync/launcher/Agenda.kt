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
    /** id события в календаре Android — чтобы открыть его для просмотра и правки. */
    val eventId: Long = 0,
    val location: String = "",
    /** Событие из календаря sync (а не Google и других): при склейке дублей открывается оно. */
    val fromSync: Boolean = false,
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
    /** Начало и конец вхождения, мс UTC — их ждёт экран события в приложении календаря. */
    val begin: Long,
    val end: Long = begin,
    val eventId: Long = 0,
    /** День в ленте (для идущих с прошлых дней — сегодня): по нему подробный экран группирует события. */
    val date: LocalDate? = null,
    val location: String = "",
    /** Цвета календарей, где есть это событие (склеенные дубли): по полоске на календарь, первым — открываемый. */
    val colors: List<Int> = listOf(color),
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
        return merge(events)
            .filter { if (it.allDay) it.endDate() > today else it.end > now.toEpochMilli() }
            .sortedWith(compareBy({ it.startDate(zone).coerceAtLeast(today) }, { !it.allDay }, { it.begin }))
            .take(limit)
            .map { event ->
                val start = event.startDate(zone).coerceAtLeast(today)
                AgendaRow(
                    colors = event.colors,
                    title = event.title.ifBlank { "Без названия" },
                    day = dayLabel(start, today),
                    time = if (event.allDay) "весь день" else timeRange(event, zone),
                    soon = if (event.allDay) null else soonLabel(event, now),
                    color = event.color,
                    begin = event.begin,
                    end = event.end,
                    eventId = event.eventId,
                    date = start,
                    location = event.location,
                )
            }
    }

    /** Событие ленты: склеенное из одинаковых в разных календарях — с цветами всех этих календарей. */
    private class Merged(event: AgendaEvent, val colors: List<Int>) {
        val title = event.title
        val begin = event.begin
        val end = event.end
        val allDay = event.allDay
        val color = event.color
        val eventId = event.eventId
        val location = event.location
    }

    /**
     * Одно и то же событие в нескольких календарях (перенесли из Google в sync, а в Google оставили) —
     * одна строка: одинаковые название (без учёта регистра и лишних пробелов), начало и «весь день».
     * Открывается копия из календаря sync, если она есть.
     */
    private fun merge(events: List<AgendaEvent>): List<Merged> = events
        .groupBy { Triple(it.title.trim().lowercase().replace(SPACES, " "), it.begin, it.allDay) }
        .values
        .map { same ->
            val main = same.firstOrNull { it.fromSync } ?: same.first()
            Merged(main, (listOf(main) + same.filter { it !== main }).map { it.color }.distinct())
        }

    private val SPACES = Regex("\\s+")

    private fun Merged.startDate(zone: ZoneId): LocalDate =
        Instant.ofEpochMilli(begin).atZone(if (allDay) ZoneOffset.UTC else zone).toLocalDate()

    private fun Merged.endDate(): LocalDate = Instant.ofEpochMilli(end).atZone(ZoneOffset.UTC).toLocalDate()

    private fun dayLabel(date: LocalDate, today: LocalDate): String = when (date) {
        today -> "сегодня"
        today.plusDays(1) -> "завтра"
        else -> date.format(WEEKDAY)
    }

    private fun timeRange(event: Merged, zone: ZoneId): String {
        val start = Instant.ofEpochMilli(event.begin).atZone(zone)
        val end = Instant.ofEpochMilli(event.end).atZone(zone)
        // Многодневное событие: конец в другой день — показываем только начало.
        return if (event.end <= event.begin || end.toLocalDate() != start.toLocalDate()) {
            start.format(TIME)
        } else {
            "${start.format(TIME)}–${end.format(TIME)}"
        }
    }

    private fun soonLabel(event: Merged, now: Instant): String? {
        val untilStart = Duration.between(now, Instant.ofEpochMilli(event.begin))
        return when {
            untilStart.isNegative || untilStart.isZero -> "идёт"
            untilStart > SOON -> null
            untilStart.toMinutes() < 60 -> "через ${untilStart.toMinutes().coerceAtLeast(1)} мин"
            else -> "через ${untilStart.toHours()} ч ${untilStart.toMinutesPart()} мин"
        }
    }
}
