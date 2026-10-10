package com.example.contactsync.data

import kotlinx.serialization.Serializable

@Serializable
data class ServerCalendar(
    /** UUID календаря на сервере. */
    val id: String,
    val name: String,
    /** #RRGGBB */
    val color: String,
    val familyShared: Boolean = false,
    val owner: String? = null,
    val mine: Boolean = true,
    val revision: Int = 0,
)

@Serializable
data class CalendarsResponse(val calendars: List<ServerCalendar>)

/**
 * Событие в API sync. Время — ISO 8601 в UTC (`2026-10-10T12:00:00Z`), у события на весь день —
 * даты, конец не включается. В правках: [baseRevision] — ревизия, от которой правили.
 */
@Serializable
data class ServerEvent(
    val id: String,
    val revision: Int = 0,
    val baseRevision: Int? = null,
    val deleted: Boolean = false,
    val title: String? = null,
    val start: String? = null,
    val end: String? = null,
    val allDay: Boolean = false,
    val location: String? = null,
    val description: String? = null,
    val color: String? = null,
    val rrule: String? = null,
)

@Serializable
data class CalendarChanges(val revision: Int, val events: List<ServerEvent>)

@Serializable
data class CalendarPushRequest(val changes: List<ServerEvent>)

/** Итог правки: ok — принята ([event] — как сохранил сервер), conflict — [current] новее, rejected — [error]. */
@Serializable
data class CalendarPushItem(
    val id: String,
    val status: String,
    val revision: Int = 0,
    val event: ServerEvent? = null,
    val current: ServerEvent? = null,
    val error: String? = null,
)

@Serializable
data class CalendarPushResult(val revision: Int, val results: List<CalendarPushItem>)
