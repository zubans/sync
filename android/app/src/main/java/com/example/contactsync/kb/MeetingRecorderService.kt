package com.example.contactsync.kb

import android.app.Activity
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.ServiceInfo
import android.media.projection.MediaProjection
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Handler
import android.os.IBinder
import android.os.Looper
import androidx.core.content.ContextCompat
import com.example.contactsync.App
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlin.concurrent.thread

/** Идущая запись. [autoStop] — начата по подсказке о созвоне и остановится, когда созвон закончится. */
data class ActiveRecording(
    val id: String,
    val source: RecordingSource,
    val startedAt: Long,
    val part: Int,
    val withOthers: Boolean,
    val autoStop: Boolean,
)

/**
 * Запись в foreground-сервисе: продолжается при выключенном экране и в других приложениях.
 * Проект не выбирается заранее: после остановки запись становится «неразобранной» ([RecordingStore]),
 * а владелец решает в [SaveRecordingActivity] — в проект, сохранить без проекта или не сохранять.
 */
class MeetingRecorderService : Service() {

    @Volatile private var running = false
    private var worker: Thread? = null
    private var projection: MediaProjection? = null
    /** Остановили из окна выбора — уведомление «Запись готова» не нужно. */
    @Volatile private var quiet = false
    private val main = Handler(Looper.getMainLooper())

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        when (intent?.action) {
            ACTION_START -> if (worker == null) start(intent) else Unit
            ACTION_STOP -> {
                quiet = quiet || intent.getBooleanExtra(EXTRA_QUIET, false)
                running = false
                if (worker == null) stopSelf()
            }
        }
        return START_NOT_STICKY
    }

    override fun onDestroy() {
        // Система остановила сервис — дописываем то, что успели записать.
        running = false
        worker?.join(5_000)
        projection?.stop()
        super.onDestroy()
    }

    private fun start(intent: Intent) {
        val source = intent.getStringExtra(EXTRA_SOURCE)?.let(RecordingSource::valueOf) ?: RecordingSource.MIC
        val autoStop = intent.getBooleanExtra(EXTRA_AUTO_STOP, false)
        val consent = if (Build.VERSION.SDK_INT >= 33) {
            intent.getParcelableExtra(EXTRA_PROJECTION, Intent::class.java)
        } else {
            @Suppress("DEPRECATION") intent.getParcelableExtra(EXTRA_PROJECTION)
        }
        // Android 14: MediaProjection берётся только после startForeground с типом mediaProjection.
        startForegroundCompat(source, withProjection = consent != null)
        projection = consent?.let {
            runCatching { getSystemService(MediaProjectionManager::class.java).getMediaProjection(Activity.RESULT_OK, it) }.getOrNull()
        }
        projection?.registerCallback(object : MediaProjection.Callback() {}, main)

        val capture = AudioCapture.open(source, projection)
        if (capture == null) {
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
            return
        }
        val recording = RecordingStore.create(this, source)
        val segmentMs = MeetingPlan.segmentMillis((application as App).kbSession.audioMaxMinutes)
        _state.value = ActiveRecording(recording.id, source, recording.startedAt, 1, capture.withOthers, autoStop)
        running = true
        worker = thread(name = "recorder") { record(capture, recording, segmentMs) }
    }

    /** Пишет части по [segmentMs] (лимит KB), пока не попросят остановиться. */
    private fun record(capture: AudioCapture, recording: Recording, segmentMs: Long) {
        val buffer = ShortArray(AudioCapture.CHUNK)
        var part = 1
        var writer = AacWriter(RecordingStore.partFile(this, recording.id, part), AudioCapture.SAMPLE_RATE)
        var totalMs = 0L
        try {
            capture.start()
            while (running) {
                val n = capture.read(buffer)
                if (n < 0) break
                if (n == 0) continue
                writer.write(buffer, n)
                if (writer.durationMs >= segmentMs) {
                    totalMs += writer.durationMs
                    closePart(writer, recording.id, part)
                    part++
                    writer = AacWriter(RecordingStore.partFile(this, recording.id, part), AudioCapture.SAMPLE_RATE)
                    _state.update { it?.copy(part = part) }
                }
            }
        } catch (e: Exception) {
            // Микрофон отобрали или кодек упал — сохраняем записанное.
        } finally {
            capture.release()
            totalMs += writer.durationMs
            closePart(writer, recording.id, part)
            finished(recording, totalMs)
        }
    }

    private fun closePart(writer: AacWriter, id: String, part: Int) {
        if (!writer.finish()) RecordingStore.partFile(this, id, part).delete()
    }

    private fun finished(recording: Recording, durationMs: Long) {
        if (RecordingStore.parts(this, recording.id).isEmpty()) {
            RecordingStore.delete(this, recording.id)
        } else {
            val done = recording.copy(status = Recording.STATUS_UNSORTED, durationMs = durationMs)
            RecordingStore.save(this, done)
            if (!quiet) RecordingNotifications.ready(this, done)
        }
        _state.value = null
        main.post {
            projection?.stop()
            projection = null
            worker = null
            stopForeground(STOP_FOREGROUND_REMOVE)
            stopSelf()
        }
    }

    private fun startForegroundCompat(source: RecordingSource, withProjection: Boolean) {
        val manager = getSystemService(NotificationManager::class.java)
        manager.createNotificationChannel(NotificationChannel(CHANNEL, "Запись", NotificationManager.IMPORTANCE_LOW))
        val stop = PendingIntent.getActivity(
            this, 0, SaveRecordingActivity.stopIntent(this),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
        )
        val what = when {
            source == RecordingSource.PHONE -> "звонок"
            withProjection -> "микрофон и голоса собеседников"
            else -> "микрофон"
        }
        val notification = Notification.Builder(this, CHANNEL)
            .setSmallIcon(android.R.drawable.ic_btn_speak_now)
            .setContentTitle("Идёт запись: ${source.label.lowercase()}")
            .setContentText("Пишется $what. После остановки — выбрать проект")
            .setUsesChronometer(true)
            .setWhen(System.currentTimeMillis())
            .setOngoing(true)
            .setContentIntent(stop)
            .addAction(Notification.Action.Builder(null, "Остановить", stop).build())
            .build()
        if (Build.VERSION.SDK_INT >= 29) {
            var type = ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
            if (withProjection) type = type or ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PROJECTION
            startForeground(NOTIFICATION_ID, notification, type)
        } else {
            startForeground(NOTIFICATION_ID, notification)
        }
    }

    companion object {
        private const val ACTION_START = "start"
        private const val ACTION_STOP = "stop"
        private const val EXTRA_SOURCE = "source"
        private const val EXTRA_AUTO_STOP = "auto_stop"
        private const val EXTRA_PROJECTION = "projection"
        private const val EXTRA_QUIET = "quiet"
        private const val CHANNEL = "kb-recording"
        private const val NOTIFICATION_ID = 41

        private val _state = MutableStateFlow<ActiveRecording?>(null)
        val state: StateFlow<ActiveRecording?> = _state.asStateFlow()

        /**
         * Запускать с видимого экрана ([RecordActivity]): сервис с микрофоном нельзя стартовать из фона.
         * [projectionConsent] — результат согласия на захват звука других приложений (для созвона).
         */
        fun start(context: Context, source: RecordingSource, autoStop: Boolean, projectionConsent: Intent?) {
            ContextCompat.startForegroundService(
                context,
                Intent(context, MeetingRecorderService::class.java)
                    .setAction(ACTION_START)
                    .putExtra(EXTRA_SOURCE, source.name)
                    .putExtra(EXTRA_AUTO_STOP, autoStop)
                    .putExtra(EXTRA_PROJECTION, projectionConsent),
            )
        }

        /** [quiet] — окно выбора уже открыто, уведомление «Запись готова» не показывать. */
        fun stop(context: Context, quiet: Boolean = false) {
            if (_state.value == null) return
            context.startService(Intent(context, MeetingRecorderService::class.java).setAction(ACTION_STOP).putExtra(EXTRA_QUIET, quiet))
        }
    }
}
