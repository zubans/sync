package com.example.contactsync.kb

import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.media.MediaRecorder
import android.os.Build
import android.os.IBinder
import androidx.core.content.ContextCompat
import com.example.contactsync.App
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import java.io.File
import java.time.LocalDateTime

/** Идущая запись: в какой проект и с какого момента. */
data class ActiveRecording(val projectSlug: String, val projectName: String, val startedAt: Long, val part: Int)

/**
 * Запись встречи в foreground-сервисе: продолжается при выключенном экране и в других приложениях,
 * в уведомлении — таймер и «Остановить и отправить». Каждая законченная часть сразу ставится
 * в очередь отправки ([MeetingUploads]).
 */
class MeetingRecorderService : Service() {

    private var recorder: MediaRecorder? = null
    private var file: File? = null
    private var projectSlug = ""
    private var projectName = ""
    private var startedAt = LocalDateTime.now()
    private var part = 1

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> if (recorder == null) {
                projectSlug = intent.getStringExtra(EXTRA_SLUG).orEmpty()
                projectName = intent.getStringExtra(EXTRA_NAME).orEmpty()
                startedAt = LocalDateTime.now()
                part = 1
                startForegroundCompat()
                startSegment()
            }
            ACTION_STOP -> {
                finishSegment()
                _state.value = null
                stopForeground(STOP_FOREGROUND_REMOVE)
                stopSelf()
            }
        }
        return START_NOT_STICKY
    }

    override fun onDestroy() {
        // Система остановила сервис — сохраняем то, что успели записать.
        finishSegment()
        _state.value = null
        super.onDestroy()
    }

    private fun startSegment() {
        val target = File(MeetingUploads.dir(this), "meeting-${System.currentTimeMillis()}.m4a")
        val maxMinutes = (application as App).kbSession.audioMaxMinutes
        val r = if (Build.VERSION.SDK_INT >= 31) MediaRecorder(this) else @Suppress("DEPRECATION") MediaRecorder()
        try {
            r.setAudioSource(MediaRecorder.AudioSource.MIC)
            r.setOutputFormat(MediaRecorder.OutputFormat.MPEG_4)
            r.setAudioEncoder(MediaRecorder.AudioEncoder.AAC)
            r.setAudioChannels(1)
            r.setAudioSamplingRate(44_100)
            r.setAudioEncodingBitRate(48_000)
            r.setMaxDuration(MeetingPlan.segmentMillis(maxMinutes).toInt())
            r.setOnInfoListener { _, what, _ ->
                // Достигли лимита KB: закрываем часть и сразу начинаем следующую.
                if (what == MediaRecorder.MEDIA_RECORDER_INFO_MAX_DURATION_REACHED) {
                    finishSegment()
                    part++
                    startSegment()
                }
            }
            r.setOutputFile(target.absolutePath)
            r.prepare()
            r.start()
        } catch (e: Exception) {
            r.release()
            target.delete()
            _state.value = null
            stopSelf()
            return
        }
        recorder = r
        file = target
        _state.value = ActiveRecording(projectSlug, projectName, _state.value?.startedAt ?: System.currentTimeMillis(), part)
    }

    private fun finishSegment() {
        val r = recorder ?: return
        val target = file
        recorder = null
        file = null
        val ok = try {
            r.stop()
            true
        } catch (e: RuntimeException) {
            // stop() падает, если записать ничего не успели — пустой файл не отправляем.
            false
        } finally {
            r.release()
        }
        if (target == null) return
        if (!ok || target.length() == 0L) {
            target.delete()
            return
        }
        MeetingUploads.save(
            target,
            PendingMeeting(projectSlug, MeetingPlan.title(startedAt, part), startedAt.toLocalDate().toString()),
        )
        MeetingUploads.enqueue(this, target)
    }

    private fun startForegroundCompat() {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, "Запись встречи", NotificationManager.IMPORTANCE_LOW))
        val stop = PendingIntent.getService(
            this, 0, Intent(this, MeetingRecorderService::class.java).setAction(ACTION_STOP),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val notification = Notification.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_btn_speak_now)
            .setContentTitle("Запись встречи")
            .setContentText("Проект $projectName — после остановки запись уйдёт в KB")
            .setUsesChronometer(true)
            .setWhen(System.currentTimeMillis())
            .setOngoing(true)
            .addAction(Notification.Action.Builder(null, "Остановить и отправить", stop).build())
            .build()
        if (Build.VERSION.SDK_INT >= 29) {
            startForeground(NOTIFICATION_ID, notification, ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    companion object {
        private const val ACTION_START = "start"
        private const val ACTION_STOP = "stop"
        private const val EXTRA_SLUG = "slug"
        private const val EXTRA_NAME = "name"
        private const val CHANNEL = "kb-recording"
        private const val NOTIFICATION_ID = 41

        private val _state = MutableStateFlow<ActiveRecording?>(null)
        val state: StateFlow<ActiveRecording?> = _state.asStateFlow()

        /** Запускать, пока приложение на экране: сервис с микрофоном нельзя стартовать из фона. */
        fun start(context: Context, projectSlug: String, projectName: String) {
            ContextCompat.startForegroundService(
                context,
                Intent(context, MeetingRecorderService::class.java)
                    .setAction(ACTION_START)
                    .putExtra(EXTRA_SLUG, projectSlug)
                    .putExtra(EXTRA_NAME, projectName),
            )
        }

        fun stop(context: Context) {
            context.startService(Intent(context, MeetingRecorderService::class.java).setAction(ACTION_STOP))
        }
    }
}
