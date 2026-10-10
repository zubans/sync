package com.example.contactsync.kb

import java.time.LocalDateTime
import java.time.format.DateTimeFormatter

/**
 * Правила записи встречи. KB отклоняет запись длиннее `audioMaxMinutes` (проверка с допуском в секунду),
 * поэтому длинная встреча пишется частями, а каждая часть уходит отдельной встречей.
 */
object MeetingPlan {
    private val TITLE = DateTimeFormatter.ofPattern("dd.MM HH:mm")

    /** Длина одной части: лимит KB минус полминуты запаса на неточность длительности у кодека. */
    fun segmentMillis(audioMaxMinutes: Int): Long = (audioMaxMinutes.coerceAtLeast(1) * 60_000L - 30_000L).coerceAtLeast(30_000L)

    /** «Встреча 10.10 14:30», для второй и следующих частей — «… · часть 2». */
    fun title(startedAt: LocalDateTime, part: Int): String =
        "Встреча ${startedAt.format(TITLE)}" + if (part > 1) " · часть $part" else ""
}
