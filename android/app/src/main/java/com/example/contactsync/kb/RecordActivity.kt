package com.example.contactsync.kb

import android.Manifest
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.media.projection.MediaProjectionManager
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat

/**
 * Начало записи без своего интерфейса: разрешения, согласие на захват звука других приложений (для созвона)
 * и запуск [MeetingRecorderService]. Сервис с микрофоном стартует только с видимого экрана —
 * поэтому сюда ведут и кнопка «Записать», и уведомление «Идёт созвон — записать?».
 */
class RecordActivity : ComponentActivity() {

    private lateinit var source: RecordingSource
    private var autoStop = false

    private val permissions = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        if (result[Manifest.permission.RECORD_AUDIO] == true) askProjection() else finish()
    }

    private val projection = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        // Отказали — пишем хотя бы микрофон.
        launch(result.data.takeIf { result.resultCode == RESULT_OK })
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        RecordingNotifications.cancelCallPrompt(this)
        if (MeetingRecorderService.state.value != null) {
            finish()
            return
        }
        source = intent.getStringExtra(EXTRA_SOURCE)?.let(RecordingSource::valueOf) ?: CallWatcher.currentSource(this)
        autoStop = intent.getBooleanExtra(EXTRA_AUTO_STOP, false)
        // После поворота результаты придут в зарегистрированные колбэки.
        if (savedInstanceState != null) return
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            askProjection()
        } else {
            permissions.launch(
                if (Build.VERSION.SDK_INT >= 33) {
                    arrayOf(Manifest.permission.RECORD_AUDIO, Manifest.permission.POST_NOTIFICATIONS)
                } else {
                    arrayOf(Manifest.permission.RECORD_AUDIO)
                },
            )
        }
    }

    /**
     * Голоса собеседников берутся через MediaProjection — система спрашивает согласие на каждый запуск.
     * Если пакету разрешена операция `PROJECT_MEDIA` (`appops set … PROJECT_MEDIA allow`), согласие даётся без окна.
     */
    private fun askProjection() {
        if (source != RecordingSource.CALL || Build.VERSION.SDK_INT < 29) {
            launch(null)
            return
        }
        projection.launch(getSystemService(MediaProjectionManager::class.java).createScreenCaptureIntent())
    }

    private fun launch(consent: Intent?) {
        MeetingRecorderService.start(this, source, autoStop, consent)
        finish()
    }

    companion object {
        private const val EXTRA_SOURCE = "source"
        private const val EXTRA_AUTO_STOP = "auto_stop"

        /** [source] null — по текущему режиму звука: идёт созвон, звонок или ничего. */
        fun intent(context: Context, source: RecordingSource? = null, autoStop: Boolean = false): Intent =
            Intent(context, RecordActivity::class.java)
                .putExtra(EXTRA_SOURCE, source?.name)
                .putExtra(EXTRA_AUTO_STOP, autoStop)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
}
