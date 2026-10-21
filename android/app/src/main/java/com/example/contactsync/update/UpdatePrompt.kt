package com.example.contactsync.update

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import com.example.contactsync.App
import com.example.contactsync.data.ClientReleaseDto
import kotlinx.coroutines.launch

/**
 * Предложение обновиться, если на сервере версия новее установленной. Проверяет при открытии приложения;
 * «Позже» — до следующего запуска. Скачивание идёт в фоне приложения и переживает поворот экрана.
 */
@Composable
fun UpdatePrompt() {
    val context = LocalContext.current
    val app = context.applicationContext as App
    var version by remember { mutableStateOf<ClientReleaseDto?>(null) }
    var progress by remember { mutableStateOf<Float?>(null) }
    var error by remember { mutableStateOf<String?>(null) }

    LaunchedEffect(Unit) { version = app.updater.check() }
    LaunchedEffect(Unit) {
        // Установку отменили или система отказала — сказать об этом; при успехе процесс перезапустится.
        app.apkInstaller.events.collect { event ->
            if (event.packageName == context.packageName && !event.success) {
                progress = null
                error = event.message
            }
        }
    }

    val current = version ?: return
    AlertDialog(
        onDismissRequest = { if (progress == null) version = null },
        title = { Text("Доступна версия ${current.versionName}") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Установлена ${app.updater.installedVersionName}. Данные приложения сохранятся.")
                progress?.let {
                    Text(if (it < 1f) "Скачиваю… ${(it * 100).toInt()}%" else "Жду подтверждения установки…")
                    LinearProgressIndicator(progress = { it }, modifier = Modifier.fillMaxWidth())
                }
                error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = {
            TextButton(
                enabled = progress == null,
                onClick = {
                    if (!app.apkInstaller.canInstall()) {
                        // Без разрешения «Установка неизвестных приложений» система не даст обновиться.
                        context.startActivity(app.apkInstaller.permissionSettingsIntent())
                        return@TextButton
                    }
                    error = null
                    progress = 0f
                    app.scope.launch {
                        runCatching { app.updater.install(current) { progress = it } }
                            .onSuccess { progress = 1f }
                            .onFailure {
                                progress = null
                                error = it.message ?: "Не удалось обновить"
                            }
                    }
                },
            ) { Text(if (error != null) "Повторить" else "Обновить") }
        },
        dismissButton = {
            if (progress == null) TextButton(onClick = { version = null }) { Text("Позже") }
        },
    )
}
