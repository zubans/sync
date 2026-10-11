package com.example.contactsync.kb

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.media.AudioManager
import android.os.Build
import android.os.Handler
import android.os.Looper

/**
 * Следит за режимом звука: созвон в другом приложении (`MODE_IN_COMMUNICATION`) или телефонный звонок (`MODE_IN_CALL`).
 * В начале разговора — уведомление «Записать?»; запись, начатую по нему, останавливает сам, когда разговор закончился.
 * Начать запись сам не может: Android не даёт включить микрофон из фона, нужно нажатие владельца.
 */
object CallWatcher {
    /** Короткие переключения режима (проверка связи, голосовое сообщение) не считаем созвоном. */
    private const val PROMPT_DELAY_MS = 3_000L
    /** Переподключение созвона не должно рвать запись. */
    private const val STOP_DELAY_MS = 5_000L

    private val handler = Handler(Looper.getMainLooper())

    fun start(context: Context) {
        if (Build.VERSION.SDK_INT < 31) return
        val app = context.applicationContext
        val audio = app.getSystemService(AudioManager::class.java)
        audio.addOnModeChangedListener(app.mainExecutor) { mode ->
            // Звонок ещё звонит — ни начала, ни конца разговора.
            if (mode == AudioManager.MODE_RINGTONE) return@addOnModeChangedListener
            handler.removeCallbacksAndMessages(null)
            if (isTalking(mode)) {
                handler.postDelayed({
                    if (isTalking(audio.mode) && MeetingRecorderService.state.value == null) {
                        RecordingNotifications.callPrompt(app, sourceFor(audio.mode))
                    }
                }, PROMPT_DELAY_MS)
            } else {
                RecordingNotifications.cancelCallPrompt(app)
                handler.postDelayed({
                    if (!isTalking(audio.mode) && MeetingRecorderService.state.value?.autoStop == true) {
                        MeetingRecorderService.stop(app)
                    }
                }, STOP_DELAY_MS)
            }
        }
    }

    /** Что сейчас записывать, если нажали «Записать». */
    fun currentSource(context: Context): RecordingSource =
        sourceFor(context.getSystemService(AudioManager::class.java).mode)

    private fun sourceFor(mode: Int) = when (mode) {
        AudioManager.MODE_IN_CALL -> RecordingSource.PHONE
        AudioManager.MODE_IN_COMMUNICATION -> RecordingSource.CALL
        else -> RecordingSource.MIC
    }

    private fun isTalking(mode: Int) = mode == AudioManager.MODE_IN_CALL || mode == AudioManager.MODE_IN_COMMUNICATION
}

/** Уведомления записи: «Идёт созвон — записать?» и «Запись готова — куда сохранить?». */
object RecordingNotifications {
    private const val CHANNEL_PROMPT = "kb-call-prompt"
    private const val CHANNEL_READY = "kb-recording-ready"
    private const val PROMPT_ID = 42

    fun callPrompt(context: Context, source: RecordingSource) {
        val manager = context.getSystemService(NotificationManager::class.java)
        if (!manager.areNotificationsEnabled()) return
        manager.createNotificationChannel(NotificationChannel(CHANNEL_PROMPT, "Предложить запись разговора", NotificationManager.IMPORTANCE_HIGH))
        val record = PendingIntent.getActivity(
            context, PROMPT_ID, RecordActivity.intent(context, source, autoStop = true),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val title = if (source == RecordingSource.PHONE) "Идёт звонок" else "Идёт созвон"
        manager.notify(
            PROMPT_ID,
            Notification.Builder(context, CHANNEL_PROMPT)
                .setSmallIcon(android.R.drawable.ic_btn_speak_now)
                .setContentTitle("$title — записать?")
                .setContentText("Запись остановится сама, когда разговор закончится")
                .setContentIntent(record)
                .setAutoCancel(true)
                .addAction(Notification.Action.Builder(null, "Записать", record).build())
                .build(),
        )
    }

    fun cancelCallPrompt(context: Context) = context.getSystemService(NotificationManager::class.java).cancel(PROMPT_ID)

    fun ready(context: Context, recording: Recording) {
        val manager = context.getSystemService(NotificationManager::class.java)
        if (!manager.areNotificationsEnabled()) return
        manager.createNotificationChannel(NotificationChannel(CHANNEL_READY, "Запись готова", NotificationManager.IMPORTANCE_HIGH))
        val id = notificationId(recording.id)
        val choose = PendingIntent.getActivity(
            context, id, SaveRecordingActivity.intent(context, recording.id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val keep = PendingIntent.getBroadcast(
            context, id,
            Intent(context, RecordingActionReceiver::class.java).setAction(RecordingActionReceiver.ACTION_KEEP)
                .putExtra(RecordingActionReceiver.EXTRA_ID, recording.id),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        manager.notify(
            id,
            Notification.Builder(context, CHANNEL_READY)
                .setSmallIcon(android.R.drawable.ic_btn_speak_now)
                .setContentTitle("${recording.title} · ${MeetingPlan.duration(recording.durationMs)}")
                .setContentText("Запись готова — куда сохранить?")
                .setContentIntent(choose)
                .addAction(Notification.Action.Builder(null, "В проект…", choose).build())
                .addAction(Notification.Action.Builder(null, "Сохранить без проекта", keep).build())
                .build(),
        )
    }

    fun cancelReady(context: Context, recordingId: String) =
        context.getSystemService(NotificationManager::class.java).cancel(notificationId(recordingId))

    private fun notificationId(recordingId: String) = 1_000 + (recordingId.hashCode() and 0xFFFF)
}

/** «Сохранить без проекта» прямо из уведомления. Удалять из уведомления нельзя — только в окне, с подтверждением. */
class RecordingActionReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val id = intent.getStringExtra(EXTRA_ID) ?: return
        if (intent.action == ACTION_KEEP) {
            RecordingStore.keep(context, id, title = "")
            RecordingNotifications.cancelReady(context, id)
        }
    }

    companion object {
        const val ACTION_KEEP = "com.example.contactsync.kb.KEEP_RECORDING"
        const val EXTRA_ID = "id"
    }
}
