package com.example.contactsync.kb

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.setContent
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.content.FileProvider
import com.example.contactsync.App
import com.example.contactsync.launcher.Accent
import com.example.contactsync.launcher.CardColor
import com.example.contactsync.launcher.CardRaised
import com.example.contactsync.launcher.Down
import com.example.contactsync.launcher.LauncherColors
import com.example.contactsync.launcher.TextMain
import com.example.contactsync.launcher.TextMuted
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext

/**
 * Что сделать с записью: отправить в проект KB, сохранить без проекта (проект ещё не заведён) или не сохранять.
 * Закрыли окно, ничего не выбрав, — запись остаётся неразобранной и видна в «Записях» экрана «База знаний».
 */
class SaveRecordingActivity : ComponentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val stop = intent.getBooleanExtra(EXTRA_STOP, false)
        val id = intent.getStringExtra(EXTRA_ID) ?: MeetingRecorderService.state.value?.id
        if (id == null) {
            finish()
            return
        }
        if (stop && savedInstanceState == null) MeetingRecorderService.stop(this, quiet = true)
        setContent {
            MaterialTheme(colorScheme = LauncherColors) {
                SaveRecordingDialog(id, onDone = ::finish)
            }
        }
    }

    companion object {
        private const val EXTRA_ID = "id"
        private const val EXTRA_STOP = "stop"

        fun intent(context: Context, recordingId: String): Intent =
            Intent(context, SaveRecordingActivity::class.java).putExtra(EXTRA_ID, recordingId)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)

        /** Остановить идущую запись и сразу спросить, куда её сохранить. */
        fun stopIntent(context: Context): Intent =
            Intent(context, SaveRecordingActivity::class.java).putExtra(EXTRA_STOP, true)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
    }
}

@Composable
private fun SaveRecordingDialog(id: String, onDone: () -> Unit) {
    val context = LocalContext.current
    val app = context.applicationContext as App
    val recording by produceState<Recording?>(null, id) {
        // Сервис ещё закрывает последнюю часть — ждём.
        while (true) {
            val loaded = withContext(Dispatchers.IO) { RecordingStore.load(context, id) }
            if (loaded == null) {
                onDone()
                return@produceState
            }
            if (loaded.status != Recording.STATUS_RECORDING) {
                value = loaded
                return@produceState
            }
            delay(300)
        }
    }
    /** null — ещё загружаются; пустой — нет входа в KB или она недоступна. */
    val projects by produceState<List<KbProject>?>(null) {
        value = if (app.kbSession.isLoggedIn) runCatching { app.kbApi.projects() }.getOrDefault(emptyList()) else emptyList()
    }
    var title by remember(recording) { mutableStateOf(recording?.title.orEmpty()) }
    var project by remember { mutableStateOf(app.kbSession.projectSlug) }
    var confirmDelete by remember { mutableStateOf(false) }

    fun done() {
        RecordingNotifications.cancelReady(context, id)
        onDone()
    }

    Box(Modifier.fillMaxSize().background(Color(0x99000000)), contentAlignment = Alignment.Center) {
        Column(
            Modifier.widthIn(max = 560.dp).fillMaxWidth().padding(24.dp)
                .clip(RoundedCornerShape(24.dp)).background(CardColor).padding(24.dp),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            val rec = recording
            if (rec == null) {
                Text("Сохраняю запись…", color = TextMain, fontSize = 18.sp)
                return@Column
            }
            Text("Куда сохранить запись?", color = TextMain, fontSize = 22.sp)
            val length = if (rec.durationMs > 0) MeetingPlan.duration(rec.durationMs) else "длина неизвестна"
            val parts = RecordingStore.parts(context, id).size
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    length + if (parts > 1) " · $parts части — в KB уйдут отдельными встречами" else "",
                    color = TextMuted, fontSize = 14.sp, modifier = Modifier.weight(1f),
                )
                TextButton(onClick = { context.listen(id) }) { Text("▶ Прослушать", color = Accent) }
            }
            OutlinedTextField(title, { title = it }, label = { Text("Название") }, singleLine = true, modifier = Modifier.fillMaxWidth())

            val list = projects
            when {
                !app.kbSession.isLoggedIn -> Text("Вы не вошли в KB — пока можно сохранить без проекта.", color = TextMuted, fontSize = 14.sp)
                list == null -> Text("Загружаю проекты…", color = TextMuted, fontSize = 14.sp)
                list.isEmpty() -> Text("KB недоступна — сохраните без проекта и отправьте позже.", color = TextMuted, fontSize = 14.sp)
                else -> LazyColumn(Modifier.fillMaxWidth().heightIn(max = 260.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                    items(list, key = { it.slug }) { p ->
                        val selected = p.slug == project
                        Text(
                            p.name,
                            color = if (selected) CardColor else TextMain,
                            fontSize = 16.sp,
                            modifier = Modifier.fillMaxWidth()
                                .clip(RoundedCornerShape(12.dp))
                                .background(if (selected) Accent else CardRaised)
                                .clickable { project = p.slug }
                                .padding(horizontal = 14.dp, vertical = 10.dp),
                        )
                    }
                }
            }

            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                TextButton(onClick = { confirmDelete = true }) { Text("Не сохранять", color = Down) }
                Box(Modifier.weight(1f))
                TextButton(onClick = {
                    RecordingStore.keep(context, id, title)
                    done()
                }) { Text("Сохранить без проекта", color = TextMain) }
                val target = list?.firstOrNull { it.slug == project }
                TextButton(enabled = target != null, onClick = {
                    RecordingStore.sendToProject(context, id, target!!.slug, title)
                    done()
                }) { Text(target?.let { "В «${it.name}»" } ?: "В проект", color = if (target != null) Accent else TextMuted) }
            }
        }
    }

    if (confirmDelete) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Не сохранять запись?") },
            text = { Text("Запись удалится с планшета, вернуть её будет нельзя.") },
            confirmButton = {
                TextButton(onClick = {
                    RecordingStore.delete(context, id)
                    done()
                }) { Text("Удалить", color = Down) }
            },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Отмена") } },
        )
    }
}

/** Прослушать первую часть во внешнем плеере. */
private fun Context.listen(recordingId: String) {
    val part = RecordingStore.parts(this, recordingId).firstOrNull() ?: return
    val uri = FileProvider.getUriForFile(this, "$packageName.files", part)
    try {
        startActivity(
            Intent(Intent.ACTION_VIEW).setDataAndType(uri, "audio/mp4")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_ACTIVITY_NEW_TASK),
        )
    } catch (_: ActivityNotFoundException) {
        // Плеера нет.
    }
}
