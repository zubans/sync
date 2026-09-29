package com.example.contactsync.ui

import android.content.ActivityNotFoundException
import android.content.Intent
import android.net.Uri
import android.text.format.DateUtils
import android.text.format.Formatter
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateMapOf
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.work.WorkInfo
import com.example.contactsync.App
import com.example.contactsync.apps.AppInventory
import com.example.contactsync.data.BackedUpApp
import com.example.contactsync.sync.ApkBackupWorker
import com.example.contactsync.sync.SyncScheduler
import kotlinx.coroutines.launch

@Composable
fun AppsScreen() {
    val context = LocalContext.current
    val app = context.applicationContext as App
    val scope = rememberCoroutineScope()
    val inventory = remember { AppInventory(context) }

    var apps by remember { mutableStateOf<List<BackedUpApp>?>(null) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val installing = remember { mutableStateMapOf<String, String>() }
    val backupWork by SyncScheduler.appsBackupState(context).collectAsState(initial = emptyList())
    val running = backupWork.firstOrNull { it.state == WorkInfo.State.RUNNING }
    // Ежесуточная задача всегда «в очереди» — это нормальное состояние; блокирует кнопку только ручной запуск.
    val manualPending = backupWork.firstOrNull { SyncScheduler.isManualAppsBackup(it) && it.state == WorkInfo.State.ENQUEUED }
    val backupRunning = running != null || manualPending != null
    val backupStatus = when {
        running != null -> running.progress.getString(ApkBackupWorker.PROGRESS) ?: "Выполняется…"
        manualPending != null && manualPending.runAttemptCount > 0 ->
            "Повтор после ошибки" + (app.session.lastAppsBackupError?.let { ": $it" } ?: "")
        manualPending != null -> "Ждёт Wi-Fi"
        else -> null
    }

    fun load() {
        loading = true
        error = null
        scope.launch {
            runCatching { app.api.backedUpApps() }
                .onSuccess { apps = it }
                .onFailure { error = it.message }
            loading = false
        }
    }

    LaunchedEffect(Unit) { load() }
    LaunchedEffect(Unit) {
        app.apkInstaller.events.collect { event ->
            installing[event.packageName] = if (event.success) "Установлено" else "Ошибка: ${event.message}"
        }
    }

    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Приложения", style = MaterialTheme.typography.headlineMedium)

        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Резервная копия", style = MaterialTheme.typography.titleMedium)
                Text(
                    "Список приложений и APK, установленных не из Google Play. Загрузка — раз в сутки по Wi-Fi на зарядке.",
                    style = MaterialTheme.typography.bodySmall,
                )
                val last = app.session.lastAppsBackupAt
                Text(
                    if (last > 0) "Последняя: ${DateUtils.getRelativeTimeSpanString(last)}. ${app.session.lastAppsBackupSummary.orEmpty()}" else "Ещё не выполнялась",
                    style = MaterialTheme.typography.bodySmall,
                )
                if (running != null) LinearProgressIndicator(Modifier.fillMaxWidth())
                backupStatus?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary) }
                if (backupStatus == null) {
                    app.session.lastAppsBackupError?.let {
                        Text("Последняя попытка не удалась: $it", style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.error)
                    }
                }
                Button(enabled = !backupRunning, onClick = { SyncScheduler.backupAppsNow(context) }) {
                    Text("Сохранить сейчас")
                }
            }
        }

        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Восстановление", style = MaterialTheme.typography.titleMedium, modifier = Modifier.weight(1f))
            TextButton(onClick = ::load, enabled = !loading) { Text("Обновить") }
        }
        if (loading) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        if (!app.apkInstaller.canInstall()) {
            OutlinedButton(onClick = { context.startActivity(app.apkInstaller.permissionSettingsIntent()) }) {
                Text("Разрешить установку приложений")
            }
        }

        LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
            items(apps.orEmpty(), key = { it.packageName }) { item ->
                val installed = inventory.installedVersion(item.packageName)
                AppRow(
                    item = item,
                    installed = installed != null && installed >= item.versionCode,
                    status = installing[item.packageName],
                    onPlay = {
                        val market = Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=${item.packageName}"))
                        try {
                            context.startActivity(market)
                        } catch (e: ActivityNotFoundException) {
                            context.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse("https://play.google.com/store/apps/details?id=${item.packageName}")))
                        }
                    },
                    onInstall = {
                        if (!app.apkInstaller.canInstall()) {
                            context.startActivity(app.apkInstaller.permissionSettingsIntent())
                            return@AppRow
                        }
                        installing[item.packageName] = "Скачиваю…"
                        app.scope.launch {
                            runCatching { app.apkInstaller.install(item) }
                                .onSuccess { installing[item.packageName] = "Ожидает подтверждения" }
                                .onFailure { installing[item.packageName] = "Ошибка: ${it.message}" }
                        }
                    },
                )
            }
        }
        if (apps?.isEmpty() == true) Text("На сервере пока нет сохранённых приложений.")
    }
}

@Composable
private fun AppRow(item: BackedUpApp, installed: Boolean, status: String?, onPlay: () -> Unit, onInstall: () -> Unit) {
    val context = LocalContext.current
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(item.label ?: item.packageName, style = MaterialTheme.typography.titleSmall)
                Text(
                    listOfNotNull(item.versionName, Formatter.formatShortFileSize(context, item.size), if (item.fromPlay) "Google Play" else null)
                        .joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                )
                status?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary) }
            }
            when {
                installed -> Text("Установлено", style = MaterialTheme.typography.bodySmall)
                item.fromPlay -> TextButton(onClick = onPlay) { Text("Google Play") }
                item.backedUp -> TextButton(onClick = onInstall) { Text("Установить") }
                else -> Text("Нет копии APK", style = MaterialTheme.typography.bodySmall)
            }
        }
    }
}
