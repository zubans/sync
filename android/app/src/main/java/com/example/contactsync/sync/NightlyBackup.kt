package com.example.contactsync.sync

import java.time.Duration
import java.time.ZonedDateTime

/**
 * Расписание еженедельного бэкапа приложений: ночью, раз в неделю.
 *
 * WorkManager не умеет «в определённое время суток», поэтому каждую ночь ставится проверка
 * (с условиями «зарядка + Wi-Fi»), а она решает, пора ли: сейчас ночь и с последнего
 * успешного бэкапа прошла неделя. Не было зарядки этой ночью — попробуем следующей, а не через неделю.
 */
object NightlyBackup {
    /** Проверка ставится на это время. */
    const val START_HOUR = 2

    /** Если условия выполнились позже (телефон поставили на зарядку под утро), после этого часа уже не начинаем. */
    const val END_HOUR = 6

    /** Раз в неделю, с запасом в полдня, чтобы время запуска не «уползало» на сутки каждую неделю. */
    val INTERVAL: Duration = Duration.ofDays(7).minusHours(12)

    /** Сколько ждать до ближайшей ночной проверки. */
    fun delayUntilNextStart(now: ZonedDateTime): Duration {
        var next = now.withHour(START_HOUR).withMinute(0).withSecond(0).withNano(0)
        if (!next.isAfter(now)) next = next.plusDays(1)
        return Duration.between(now, next)
    }

    fun isNight(now: ZonedDateTime): Boolean = now.hour < END_HOUR

    fun isDue(lastSuccessMillis: Long, nowMillis: Long): Boolean =
        lastSuccessMillis <= 0 || nowMillis - lastSuccessMillis >= INTERVAL.toMillis()
}
