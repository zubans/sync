package com.example.contactsync.widget

import android.app.Activity
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.provider.CalendarContract
import com.example.contactsync.calendar.CalendarAccount
import com.example.contactsync.launcher.AgendaRow
import com.example.contactsync.launcher.newEvent
import com.example.contactsync.launcher.openEvent

/**
 * Без экрана: нажатие в виджете «Ближайшее» — открыть календарь на текущем месяце, событие или создать новое.
 * Тем же путём, что и на главном экране планшета (в том числе с выбором Etar, если он стоит).
 */
class CalendarActionActivity : Activity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val begin = intent.getLongExtra(EXTRA_BEGIN, 0)
        if (intent.getBooleanExtra(EXTRA_MONTH, false)) {
            // Заодно подтянуть с сервера свежие события календарей sync — пока открывается календарь.
            CalendarAccount.requestSync(this)
            openMonth()
        } else if (begin == 0L) {
            newEvent()
        } else {
            openEvent(
                AgendaRow(
                    title = "", day = "", time = "", soon = null, color = 0, begin = begin,
                    end = intent.getLongExtra(EXTRA_END, begin), eventId = intent.getLongExtra(EXTRA_EVENT_ID, 0),
                ),
            )
        }
        finish()
    }

    /** Приложение «Календарь» на сегодняшней дате — в его обычном виде (у календаря Xiaomi — месяц). */
    private fun openMonth() {
        val uri = CalendarContract.CONTENT_URI.buildUpon().appendPath("time").appendPath(System.currentTimeMillis().toString()).build()
        try {
            startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
        } catch (_: ActivityNotFoundException) {
            // Приложения календаря нет — открывать нечего.
        }
    }

    companion object {
        private const val EXTRA_BEGIN = "begin"
        private const val EXTRA_END = "end"
        private const val EXTRA_EVENT_ID = "event_id"
        private const val EXTRA_MONTH = "month"

        fun openCalendar(context: Context): Intent = Intent(context, CalendarActionActivity::class.java)
            .putExtra(EXTRA_MONTH, true)
            .setData(Uri.parse("contactsync-widget://calendar"))

        fun openEvent(context: Context, row: AgendaRow): Intent = Intent(context, CalendarActionActivity::class.java)
            .putExtra(EXTRA_BEGIN, row.begin)
            .putExtra(EXTRA_END, row.end)
            .putExtra(EXTRA_EVENT_ID, row.eventId)
            .setData(Uri.parse("contactsync-widget://event/${row.eventId}/${row.begin}"))

        fun newEvent(context: Context): Intent = Intent(context, CalendarActionActivity::class.java)
            .setData(Uri.parse("contactsync-widget://event/new"))
    }
}
