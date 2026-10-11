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

    /** Название записи по умолчанию: «Встреча 10.10 14:30», «Созвон …», «Звонок …». */
    fun title(source: RecordingSource, startedAt: LocalDateTime): String = "${source.label} ${startedAt.format(TITLE)}"

    /** Название встречи в KB для части записи: у второй и следующих — «… · часть 2». */
    fun partTitle(title: String, part: Int): String = title + if (part > 1) " · часть $part" else ""

    /** «07:05» или «1:02:03». */
    fun duration(millis: Long): String {
        val seconds = millis / 1000
        val h = seconds / 3600
        val m = seconds % 3600 / 60
        val s = seconds % 60
        return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%02d:%02d".format(m, s)
    }
}

/** Сводка звука: голоса собеседников подмешиваются к микрофону. */
object AudioMix {
    /** Складывает первые [extraCount] отсчётов [extra] в [base] на месте, с отсечением по границам 16 бит. */
    fun mixInto(base: ShortArray, count: Int, extra: ShortArray, extraCount: Int) {
        for (i in 0 until minOf(count, extraCount)) {
            base[i] = (base[i] + extra[i]).coerceIn(Short.MIN_VALUE.toInt(), Short.MAX_VALUE.toInt()).toShort()
        }
    }
}
