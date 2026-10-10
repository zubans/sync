package com.example.contactsync.calendar

import android.accounts.Account
import android.app.Service
import android.content.AbstractThreadedSyncAdapter
import android.content.ContentProviderClient
import android.content.ContentUris
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.content.SyncResult
import android.database.Cursor
import android.net.Uri
import android.os.Bundle
import android.os.IBinder
import android.provider.CalendarContract.Calendars
import android.provider.CalendarContract.Events
import android.util.Log
import com.example.contactsync.App
import com.example.contactsync.data.Api
import com.example.contactsync.data.ServerCalendar
import com.example.contactsync.data.ServerEvent
import com.example.contactsync.data.UnauthorizedException
import kotlinx.coroutines.runBlocking
import kotlinx.serialization.SerializationException
import java.io.IOException
import java.util.TimeZone
import java.util.UUID

/**
 * Синхронизация календарей sync с календарём Android (CalendarContract).
 *
 * Порядок: список календарей → для каждого сначала отправляем правки с планшета (DIRTY/DELETED),
 * потом забираем изменения сервера с последней ревизии (хранится в CAL_SYNC1 календаря).
 * Конфликт решается в пользу сервера: событие на планшете переписывается его версией.
 * Исключения повторяющихся событий (ORIGINAL_ID) пока не синхронизируются.
 */
class CalendarSync(
    private val api: Api,
    private val provider: ContentProviderClient,
    private val account: Account,
) {
    suspend fun run() {
        val server = api.calendars()
        val local = localCalendars()
        val bySyncId = server.associate { calendar -> calendar.id to (local[calendar.id] ?: insertCalendar(calendar)) }
        server.forEach { updateCalendar(bySyncId.getValue(it.id), it) }
        // Календарь пропал с сервера (удалён, вышли из семьи) — убираем с планшета вместе с событиями.
        (local.keys - bySyncId.keys).forEach { provider.delete(asSyncAdapter(ContentUris.withAppendedId(Calendars.CONTENT_URI, local.getValue(it))), null, null) }

        server.forEach { calendar ->
            val id = bySyncId.getValue(calendar.id)
            push(calendar, id)
            pull(calendar, id)
        }
    }

    private suspend fun push(calendar: ServerCalendar, calendarId: Long) {
        val pending = mutableListOf<Pair<Long, ServerEvent>>()
        query(
            Events.CONTENT_URI, EVENT_COLUMNS,
            "${Events.CALENDAR_ID} = ? AND (${Events.DIRTY} = 1 OR ${Events.DELETED} = 1) AND ${Events.ORIGINAL_ID} IS NULL",
            arrayOf(calendarId.toString()),
        ) { c ->
            val localId = c.getLong(0)
            val syncId = c.getString(1)
            val deleted = c.getInt(c.getColumnIndexOrThrow(Events.DELETED)) == 1
            when {
                deleted && syncId == null -> deleteEvent(localId)
                deleted -> pending += localId to ServerEvent(id = syncId!!, baseRevision = c.revision(), deleted = true)
                else -> {
                    val event = c.toLocalEvent()
                    pending += localId to EventMapping.toServer(event, syncId ?: UUID.randomUUID().toString(), syncId?.let { event.revision })
                }
            }
        }
        pending.chunked(PUSH_BATCH).forEach { batch ->
            val results = api.pushCalendarChanges(calendar.id, batch.map { it.second }).results.associateBy { it.id }
            batch.forEach { (localId, change) ->
                val result = results[change.id] ?: return@forEach
                when (result.status) {
                    "ok" -> if (change.deleted) deleteEvent(localId) else markSynced(localId, change.id, result.revision)
                    // Сервер новее — его версия побеждает.
                    "conflict" -> result.current?.let { if (it.deleted) deleteEvent(localId) else writeEvent(calendarId, it, localId) }
                    // Сервер отказал насовсем (например, лимит) — снимаем отметку, чтобы не слать по кругу.
                    else -> markSynced(localId, change.id.takeIf { change.baseRevision != null }, change.baseRevision ?: 0)
                }
            }
        }
    }

    private suspend fun pull(calendar: ServerCalendar, calendarId: Long) {
        val since = calendarRevision(calendarId)
        val changes = api.calendarChanges(calendar.id, since)
        changes.events.forEach { event ->
            val local = findBySyncId(calendarId, event.id)
            when {
                event.deleted -> local?.let { deleteEvent(it.first) }
                // Пока шла синхронизация, событие поправили на планшете — отправим в следующий раз.
                local?.second == true -> Unit
                else -> writeEvent(calendarId, event, local?.first)
            }
        }
        provider.update(
            asSyncAdapter(ContentUris.withAppendedId(Calendars.CONTENT_URI, calendarId)),
            ContentValues().apply { put(Calendars.CAL_SYNC1, changes.revision.toString()) }, null, null,
        )
    }

    private fun writeEvent(calendarId: Long, event: ServerEvent, localId: Long?) {
        val local = EventMapping.toLocal(event, TimeZone.getDefault().id)
        val values = ContentValues().apply {
            put(Events.CALENDAR_ID, calendarId)
            put(Events.TITLE, local.title)
            put(Events.DTSTART, local.dtStart)
            if (local.dtEnd != null) put(Events.DTEND, local.dtEnd) else putNull(Events.DTEND)
            if (local.duration != null) put(Events.DURATION, local.duration) else putNull(Events.DURATION)
            put(Events.ALL_DAY, if (local.allDay) 1 else 0)
            put(Events.EVENT_TIMEZONE, local.timeZone)
            put(Events.EVENT_LOCATION, local.location)
            put(Events.DESCRIPTION, local.description)
            if (local.color != null) put(Events.EVENT_COLOR, local.color) else putNull(Events.EVENT_COLOR)
            if (local.rrule != null) put(Events.RRULE, local.rrule) else putNull(Events.RRULE)
            put(Events._SYNC_ID, event.id)
            put(Events.SYNC_DATA1, event.revision.toString())
            put(Events.DIRTY, 0)
        }
        if (localId == null) {
            provider.insert(asSyncAdapter(Events.CONTENT_URI), values)
        } else {
            provider.update(asSyncAdapter(ContentUris.withAppendedId(Events.CONTENT_URI, localId)), values, null, null)
        }
    }

    private fun markSynced(localId: Long, syncId: String?, revision: Int) {
        provider.update(
            asSyncAdapter(ContentUris.withAppendedId(Events.CONTENT_URI, localId)),
            ContentValues().apply {
                if (syncId != null) put(Events._SYNC_ID, syncId)
                put(Events.SYNC_DATA1, revision.toString())
                put(Events.DIRTY, 0)
            },
            null, null,
        )
    }

    private fun deleteEvent(localId: Long) {
        provider.delete(asSyncAdapter(ContentUris.withAppendedId(Events.CONTENT_URI, localId)), null, null)
    }

    /** id и признак DIRTY локального события с данным серверным id. */
    private fun findBySyncId(calendarId: Long, syncId: String): Pair<Long, Boolean>? {
        var found: Pair<Long, Boolean>? = null
        query(
            Events.CONTENT_URI, arrayOf(Events._ID, Events.DIRTY),
            "${Events.CALENDAR_ID} = ? AND ${Events._SYNC_ID} = ?", arrayOf(calendarId.toString(), syncId),
        ) { found = it.getLong(0) to (it.getInt(1) == 1) }
        return found
    }

    /** Календари этого аккаунта на планшете: серверный id → локальный. */
    private fun localCalendars(): Map<String, Long> {
        val result = mutableMapOf<String, Long>()
        query(
            Calendars.CONTENT_URI, arrayOf(Calendars._ID, Calendars._SYNC_ID),
            "${Calendars.ACCOUNT_NAME} = ? AND ${Calendars.ACCOUNT_TYPE} = ?", arrayOf(account.name, account.type),
        ) { c -> c.getString(1)?.let { result[it] = c.getLong(0) } }
        return result
    }

    private fun calendarRevision(calendarId: Long): Int {
        var revision = 0
        query(ContentUris.withAppendedId(Calendars.CONTENT_URI, calendarId), arrayOf(Calendars.CAL_SYNC1), null, null) {
            revision = it.getString(0)?.toIntOrNull() ?: 0
        }
        return revision
    }

    private fun insertCalendar(calendar: ServerCalendar): Long {
        val values = calendarValues(calendar).apply {
            put(Calendars.ACCOUNT_NAME, account.name)
            put(Calendars.ACCOUNT_TYPE, account.type)
            put(Calendars._SYNC_ID, calendar.id)
            put(Calendars.OWNER_ACCOUNT, account.name)
            put(Calendars.VISIBLE, 1)
            put(Calendars.SYNC_EVENTS, 1)
            put(Calendars.CALENDAR_TIME_ZONE, TimeZone.getDefault().id)
            put(Calendars.CAL_SYNC1, "0")
        }
        val uri = provider.insert(asSyncAdapter(Calendars.CONTENT_URI), values) ?: throw IOException("Календарь не создан")
        return ContentUris.parseId(uri)
    }

    private fun updateCalendar(localId: Long, calendar: ServerCalendar) {
        provider.update(asSyncAdapter(ContentUris.withAppendedId(Calendars.CONTENT_URI, localId)), calendarValues(calendar), null, null)
    }

    private fun calendarValues(calendar: ServerCalendar) = ContentValues().apply {
        put(Calendars.NAME, calendar.name)
        put(Calendars.CALENDAR_DISPLAY_NAME, if (calendar.mine) calendar.name else "${calendar.name} · ${calendar.owner}")
        put(Calendars.CALENDAR_COLOR, EventMapping.parseColor(calendar.color) ?: 0xFF4285F4.toInt())
        // Семейный календарь другого человека тоже можно править — так решили для семьи.
        put(Calendars.CALENDAR_ACCESS_LEVEL, if (calendar.mine) Calendars.CAL_ACCESS_OWNER else Calendars.CAL_ACCESS_CONTRIBUTOR)
    }

    private fun Cursor.revision(): Int = getString(getColumnIndexOrThrow(Events.SYNC_DATA1))?.toIntOrNull() ?: 0

    private fun Cursor.toLocalEvent() = LocalEvent(
        localId = getLong(0),
        syncId = getString(1),
        revision = revision(),
        title = getString(getColumnIndexOrThrow(Events.TITLE)).orEmpty(),
        dtStart = getLong(getColumnIndexOrThrow(Events.DTSTART)),
        dtEnd = getColumnIndexOrThrow(Events.DTEND).let { if (isNull(it)) null else getLong(it) },
        duration = getString(getColumnIndexOrThrow(Events.DURATION)),
        allDay = getInt(getColumnIndexOrThrow(Events.ALL_DAY)) == 1,
        timeZone = getString(getColumnIndexOrThrow(Events.EVENT_TIMEZONE)) ?: "UTC",
        location = getString(getColumnIndexOrThrow(Events.EVENT_LOCATION)),
        description = getString(getColumnIndexOrThrow(Events.DESCRIPTION)),
        color = getColumnIndexOrThrow(Events.EVENT_COLOR).let { if (isNull(it)) null else getInt(it) },
        rrule = getString(getColumnIndexOrThrow(Events.RRULE)),
    )

    private inline fun query(uri: Uri, projection: Array<String>, selection: String?, args: Array<String>?, row: (Cursor) -> Unit) {
        provider.query(asSyncAdapter(uri), projection, selection, args, null)?.use { while (it.moveToNext()) row(it) }
    }

    /** Запись от имени адаптера: событие не помечается DIRTY и не уходит обратно на сервер. */
    private fun asSyncAdapter(uri: Uri): Uri = uri.buildUpon()
        .appendQueryParameter(android.provider.CalendarContract.CALLER_IS_SYNCADAPTER, "true")
        .appendQueryParameter(Calendars.ACCOUNT_NAME, account.name)
        .appendQueryParameter(Calendars.ACCOUNT_TYPE, account.type)
        .build()

    private companion object {
        const val PUSH_BATCH = 200
        val EVENT_COLUMNS = arrayOf(
            Events._ID, Events._SYNC_ID, Events.SYNC_DATA1, Events.DELETED, Events.TITLE, Events.DTSTART, Events.DTEND,
            Events.DURATION, Events.ALL_DAY, Events.EVENT_TIMEZONE, Events.EVENT_LOCATION, Events.DESCRIPTION,
            Events.EVENT_COLOR, Events.RRULE,
        )
    }
}

private const val TAG = "CalendarSync"

/** SyncAdapter календаря: его запускает система по расписанию и после правок в «Календаре». */
class CalendarSyncService : Service() {
    private lateinit var adapter: Adapter

    override fun onCreate() {
        adapter = Adapter(applicationContext)
    }

    override fun onBind(intent: Intent?): IBinder = adapter.syncAdapterBinder

    private class Adapter(context: Context) : AbstractThreadedSyncAdapter(context, true) {
        override fun onPerformSync(account: Account, extras: Bundle, authority: String, provider: ContentProviderClient, result: SyncResult) {
            val app = context.applicationContext as App
            if (!app.session.isLoggedIn) return
            try {
                runBlocking { CalendarSync(app.api, provider, account).run() }
            } catch (e: UnauthorizedException) {
                Log.w(TAG, "Синхронизация календаря: нужен вход", e)
                result.stats.numAuthExceptions++
            } catch (e: IOException) {
                Log.w(TAG, "Синхронизация календаря: ошибка сети или сервера", e)
                result.stats.numIoExceptions++
            } catch (e: SerializationException) {
                Log.w(TAG, "Синхронизация календаря: неожиданный ответ сервера", e)
                // Сервер ответил не тем (например, старая версия без календаря) — не роняем поток синхронизации.
                result.stats.numParseExceptions++
            } catch (e: SecurityException) {
                Log.w(TAG, "Синхронизация календаря: нет разрешения на календарь", e)
                // Нет разрешения на календарь — синхронизировать нечего, пока его не дадут.
                result.stats.numAuthExceptions++
            }
        }
    }
}
