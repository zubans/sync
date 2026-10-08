package com.example.contactsync.ui

import android.text.format.DateUtils
import android.text.format.Formatter
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.material3.AlertDialog
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
import com.example.contactsync.apps.AppStore
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
    var rollbackBlocked by remember { mutableStateOf<BackedUpApp?>(null) }
    val backupWork by SyncScheduler.appsBackupState(context).collectAsState(initial = emptyList())
    val running = backupWork.firstOrNull { it.state == WorkInfo.State.RUNNING }
    val retrying = backupWork.firstOrNull { it.state == WorkInfo.State.ENQUEUED && it.runAttemptCount > 0 }
    val manualWaiting = backupWork.firstOrNull {
        SyncScheduler.isManualAppsBackup(it) && it.state == WorkInfo.State.ENQUEUED && it.runAttemptCount == 0
    }
    val backupStatus = when {
        running != null -> running.progress.getString(ApkBackupWorker.PROGRESS) ?: "Выполняется…"
        retrying != null -> "Повтор после ошибки" + (app.session.lastAppsBackupError?.let { ": $it" } ?: "")
        manualWaiting != null -> "Ждёт Wi-Fi"
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
                    "Список приложений и их APK, откуда бы они ни были установлены. Системные не сохраняются. Обновляется раз в неделю ночью, когда телефон на зарядке и в Wi-Fi.",
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
                // Недоступна только во время загрузки; при ожидании повтора — перезапускает сразу.
                Button(enabled = running == null, onClick = { SyncScheduler.backupAppsNow(context) }) {
                    Text(if (retrying != null) "Повторить сейчас" else "Сохранить сейчас")
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
                    onStore = { store ->
                        if (!store.open(context, item.packageName)) installing[item.packageName] = "${store.name} не установлен"
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
                    onRollback = rollback@{
                        val previous = item.previous ?: return@rollback
                        if (installed != null && installed > previous.versionCode) {
                            rollbackBlocked = item
                            return@rollback
                        }
                        if (!app.apkInstaller.canInstall()) {
                            context.startActivity(app.apkInstaller.permissionSettingsIntent())
                            return@rollback
                        }
                        installing[item.packageName] = "Скачиваю версию ${previous.versionName ?: previous.versionCode}…"
                        app.scope.launch {
                            runCatching { app.apkInstaller.install(item, previous = true) }
                                .onSuccess { installing[item.packageName] = "Ожидает подтверждения" }
                                .onFailure { installing[item.packageName] = "Ошибка: ${it.message}" }
                        }
                    },
                )
            }
        }
        if (apps?.isEmpty() == true) Text("На сервере пока нет сохранённых приложений.")
    }

    // Android не ставит старую версию поверх новой: сначала нужно удалить текущую.
    rollbackBlocked?.let { item ->
        AlertDialog(
            onDismissRequest = { rollbackBlocked = null },
            title = { Text("Откат ${item.label ?: item.packageName}") },
            text = {
                Text(
                    "Android не устанавливает старую версию поверх новой. Удалите приложение " +
                        "(его данные на телефоне будут удалены), затем снова нажмите «Прошлая версия».",
                )
            },
            confirmButton = {
                TextButton(onClick = {
                    context.startActivity(app.apkInstaller.uninstallIntent(item.packageName))
                    rollbackBlocked = null
                }) { Text("Удалить приложение") }
            },
            dismissButton = { TextButton(onClick = { rollbackBlocked = null }) { Text("Отмена") } },
        )
    }
}

@Composable
private fun AppRow(
    item: BackedUpApp,
    installed: Boolean,
    status: String?,
    onStore: (AppStore) -> Unit,
    onInstall: () -> Unit,
    onRollback: () -> Unit,
) {
    val context = LocalContext.current
    // Старый сервер не отдаёт installer — тогда знаем только, что из Play.
    val store = AppStore.of(item.installer ?: AppStore.PLAY.takeIf { item.fromPlay })
    Card(Modifier.fillMaxWidth()) {
        Row(Modifier.padding(12.dp), verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(item.label ?: item.packageName, style = MaterialTheme.typography.titleSmall)
                Text(
                    listOfNotNull(item.versionName, Formatter.formatShortFileSize(context, item.size), store?.name)
                        .joinToString(" · "),
                    style = MaterialTheme.typography.bodySmall,
                )
                status?.let { Text(it, style = MaterialTheme.typography.bodySmall, color = MaterialTheme.colorScheme.primary) }
                item.previous?.let { previous ->
                    TextButton(onClick = onRollback, contentPadding = PaddingValues(0.dp)) {
                        Text("Прошлая версия ${previous.versionName ?: previous.versionCode}", style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
            // Восстановить можно из архива или из магазина, откуда приложение ставили, — выбирает пользователь.
            Column(horizontalAlignment = Alignment.End) {
                when {
                    installed -> Text("Установлено", style = MaterialTheme.typography.bodySmall)
                    !item.backedUp && store == null -> Text("Нет копии APK", style = MaterialTheme.typography.bodySmall)
                    else -> {
                        if (item.backedUp) TextButton(onClick = onInstall) { Text("Из архива") }
                        store?.let { TextButton(onClick = { onStore(it) }) { Text(it.name) } }
                    }
                }
            }
        }
    }
}
