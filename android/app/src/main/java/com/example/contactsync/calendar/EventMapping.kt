package com.example.contactsync.calendar

import com.example.contactsync.data.ServerEvent
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneOffset
import java.time.format.DateTimeFormatter

/** Событие в календаре Android (`CalendarContract.Events`) — только поля, которые синхронизируем. */
data class LocalEvent(
    val localId: Long? = null,
    /** UUID события на сервере (`_SYNC_ID`); null — событие создано на планшете и ещё не отправлено. */
    val syncId: String? = null,
    /** Ревизия сервера, от которой локальная версия (`SYNC_DATA1`). */
    val revision: Int = 0,
    val title: String,
    /** Начало и конец, мс UTC. У событий на весь день — полночь UTC. */
    val dtStart: Long,
    /** null у повторяющихся: их длительность — в [duration]. */
    val dtEnd: Long?,
    val duration: String? = null,
    val allDay: Boolean = false,
    val timeZone: String = "UTC",
    val location: String? = null,
    val description: String? = null,
    val color: Int? = null,
    val rrule: String? = null,
)

/**
 * Перевод событий между сервером sync и календарём Android. Правила Android:
 * у события на весь день начало — полночь UTC и пояс UTC; у повторяющегося нет DTEND, есть DURATION.
 * На сервере конец есть всегда, а RRULE хранится как есть.
 */
object EventMapping {
    private val ISO_UTC = DateTimeFormatter.ofPattern("yyyy-MM-dd'T'HH:mm:ss'Z'").withZone(ZoneOffset.UTC)
    private const val DAY_MS = 24 * 60 * 60 * 1000L

    fun toServer(local: LocalEvent, id: String, baseRevision: Int?): ServerEvent {
        val end = local.dtEnd ?: (local.dtStart + (parseDurationSeconds(local.duration) ?: 0L) * 1000)
        // Пустое событие на весь день Android допускает, сервер — нет: минимум сутки.
        val safeEnd = if (local.allDay) maxOf(end, local.dtStart + DAY_MS) else maxOf(end, local.dtStart)
        return ServerEvent(
            id = id,
            baseRevision = baseRevision,
            title = local.title.ifBlank { "Без названия" },
            start = format(local.dtStart, local.allDay),
            end = format(safeEnd, local.allDay),
            allDay = local.allDay,
            location = local.location?.takeIf { it.isNotBlank() },
            description = local.description?.takeIf { it.isNotBlank() },
            color = local.color?.let(::colorHex),
            rrule = local.rrule?.removePrefix("RRULE:")?.takeIf { it.isNotBlank() },
        )
    }

    /** Событие сервера в поля Android; [timeZone] — пояс устройства для событий со временем. */
    fun toLocal(server: ServerEvent, timeZone: String): LocalEvent {
        val start = parse(server.start ?: error("У события нет начала"), server.allDay)
        val end = parse(server.end ?: error("У события нет конца"), server.allDay)
        val recurring = !server.rrule.isNullOrBlank()
        return LocalEvent(
            syncId = server.id,
            revision = server.revision,
            title = server.title.orEmpty(),
            dtStart = start,
            dtEnd = if (recurring) null else end,
            duration = if (recurring) duration(end - start, server.allDay) else null,
            allDay = server.allDay,
            timeZone = if (server.allDay) "UTC" else timeZone,
            location = server.location,
            description = server.description,
            color = server.color?.let(::parseColor),
            rrule = server.rrule,
        )
    }

    fun format(millis: Long, allDay: Boolean): String =
        if (allDay) Instant.ofEpochMilli(millis).atZone(ZoneOffset.UTC).toLocalDate().toString() else ISO_UTC.format(Instant.ofEpochMilli(millis))

    fun parse(value: String, allDay: Boolean): Long =
        if (allDay) LocalDate.parse(value).atStartOfDay(ZoneOffset.UTC).toInstant().toEpochMilli() else Instant.parse(value).toEpochMilli()

    /** Длительность RFC 5545 в формате, который пишет календарь Android: P3600S, у событий на весь день — P1D. */
    fun duration(millis: Long, allDay: Boolean): String =
        if (allDay) "P${maxOf(1, millis / DAY_MS)}D" else "P${maxOf(0, millis / 1000)}S"

    /** P3600S, PT1H30M, P1D, P1W → секунды; null — не разобрать. */
    fun parseDurationSeconds(value: String?): Long? {
        val match = Regex("""^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?(?:(\d+)S)?$""")
            .matchEntire(value?.trim() ?: return null) ?: return null
        val g = match.groupValues
        fun n(i: Int) = g[i].toLongOrNull() ?: 0L
        val seconds = n(2) * 7 * 86400 + n(3) * 86400 + n(4) * 3600 + n(5) * 60 + n(6) + n(7)
        return if (g[1] == "-") -seconds else seconds
    }

    fun colorHex(color: Int): String = "#%06X".format(color and 0xFFFFFF)

    fun parseColor(hex: String): Int? =
        hex.removePrefix("#").takeIf { it.length == 6 }?.toLongOrNull(16)?.let { (0xFF000000 or it).toInt() }
}
