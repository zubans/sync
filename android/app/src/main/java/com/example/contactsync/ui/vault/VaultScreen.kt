package com.example.contactsync.ui.vault

import android.content.ClipData
import android.content.ClipDescription
import android.content.ClipboardManager
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.PersistableBundle
import android.provider.Settings
import android.text.format.DateUtils
import android.view.autofill.AutofillManager
import android.widget.Toast
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.window.Dialog
import androidx.compose.ui.window.DialogProperties
import androidx.fragment.app.FragmentActivity
import com.example.contactsync.App
import com.example.contactsync.vault.VaultEntry
import com.example.contactsync.vault.VaultLogic
import com.example.contactsync.vault.VaultRecord
import com.example.contactsync.vault.VaultRepository
import com.example.contactsync.vault.VaultStatus
import com.example.contactsync.vault.authenticate
import kotlinx.coroutines.launch
import java.security.SecureRandom

@Composable
fun VaultScreen() {
    val app = LocalContext.current.applicationContext as App
    val vault = app.vault
    val status by vault.status.collectAsState()

    // Хранилище могли создать или сбросить на другом устройстве.
    LaunchedEffect(Unit) { runCatching { vault.refreshKey() } }

    Column(Modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Пароли", style = MaterialTheme.typography.headlineMedium)
        when (status) {
            VaultStatus.NOT_SET_UP -> SetUpPanel(vault)
            VaultStatus.LOCKED -> {
                Text("Хранилище заблокировано. Пароли зашифрованы на устройстве, сервер их не видит.")
                UnlockPanel(vault, onUnlocked = { app.syncVaultSoon() })
            }
            VaultStatus.UNLOCKED -> UnlockedVault(app, vault)
        }
    }
}

@Composable
private fun SetUpPanel(vault: VaultRepository) {
    val scope = rememberCoroutineScope()
    var password by remember { mutableStateOf("") }
    var confirm by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    val problem = when {
        password.length < MIN_MASTER_LENGTH -> "Не короче $MIN_MASTER_LENGTH символов"
        password != confirm -> "Пароли не совпадают"
        else -> null
    }

    Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Text("Создайте мастер-пароль. Им шифруются все пароли; на сервер он не отправляется.")
        Text(
            "Мастер-пароль невозможно восстановить. Если забудете его, хранилище придётся сбросить вместе с паролями.",
            color = MaterialTheme.colorScheme.error,
        )
        PasswordField("Мастер-пароль", password) { password = it }
        PasswordField("Повторите мастер-пароль", confirm) { confirm = it }
        if (password.isNotEmpty()) problem?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
        if (busy) LinearProgressIndicator(Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Button(
            enabled = !busy && problem == null,
            onClick = {
                busy = true
                error = null
                scope.launch {
                    try {
                        vault.setUp(password.toCharArray())
                    } catch (e: Exception) {
                        error = e.message
                        runCatching { vault.refreshKey() }
                    } finally {
                        busy = false
                    }
                }
            },
        ) { Text("Создать хранилище") }
    }
}

@Composable
private fun UnlockedVault(app: App, vault: VaultRepository) {
    val records by vault.records.collectAsState()
    val scope = rememberCoroutineScope()
    val context = LocalContext.current
    var query by remember { mutableStateOf("") }
    var editing by remember { mutableStateOf<VaultRecord?>(null) }
    var creating by remember { mutableStateOf(false) }
    var showSettings by remember { mutableStateOf(false) }
    var syncing by remember { mutableStateOf(false) }

    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        Button(onClick = { creating = true }) { Text("Добавить") }
        OutlinedButton(enabled = !syncing, onClick = {
            syncing = true
            scope.launch {
                val message = runCatching { vault.sync() }.fold(
                    onSuccess = { if (it.pendingConflicts > 0) "Есть неотправленные правки" else "Синхронизировано" },
                    onFailure = { it.message ?: "Ошибка синхронизации" },
                )
                Toast.makeText(context, message, Toast.LENGTH_SHORT).show()
                syncing = false
            }
        }) { Text("Синхронизировать") }
        TextButton(onClick = { showSettings = true }) { Text("Настройки") }
    }
    if (syncing) LinearProgressIndicator(Modifier.fillMaxWidth())

    AutofillHint()

    OutlinedTextField(
        value = query,
        onValueChange = { query = it },
        label = { Text("Поиск") },
        singleLine = true,
        modifier = Modifier.fillMaxWidth(),
    )

    val filtered = records.filter {
        query.isBlank() || listOf(it.entry.title, it.entry.username, it.entry.uris.joinToString(" "))
            .any { field -> field.contains(query.trim(), ignoreCase = true) }
    }
    if (records.isEmpty()) {
        Text("Паролей пока нет. Они появятся, когда вы войдёте куда-нибудь с автозаполнением, или добавьте вручную.")
    }
    LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
        items(filtered, key = { it.id }) { record ->
            Card(Modifier.fillMaxWidth().clickable { editing = record }) {
                Column(Modifier.padding(12.dp)) {
                    Text(record.entry.title, style = MaterialTheme.typography.titleMedium)
                    Text(
                        listOf(record.entry.username, record.entry.uris.firstOrNull().orEmpty()).filter { it.isNotBlank() }.joinToString(" · "),
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
            }
        }
    }

    if (creating || editing != null) {
        EntryEditor(
            record = editing,
            onDismiss = { creating = false; editing = null },
            onSave = { id, entry ->
                scope.launch {
                    vault.upsert(id, entry)
                    app.syncVaultSoon()
                }
                creating = false
                editing = null
            },
            onDelete = { id ->
                scope.launch {
                    vault.delete(id)
                    app.syncVaultSoon()
                }
                editing = null
            },
        )
    }
    if (showSettings) VaultSettings(vault, onDismiss = { showSettings = false })
}

/** Подсказка включить автозаполнение, если наш сервис не выбран. */
@Composable
private fun AutofillHint() {
    val context = LocalContext.current
    val manager = context.getSystemService(AutofillManager::class.java) ?: return
    if (manager.hasEnabledAutofillServices()) return
    Card(Modifier.fillMaxWidth()) {
        Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
            Text("Включите Contact Sync как сервис автозаполнения — тогда пароли будут подставляться и сохраняться сами.")
            Button(onClick = {
                context.startActivity(
                    Intent(Settings.ACTION_REQUEST_SET_AUTOFILL_SERVICE, Uri.parse("package:${context.packageName}")),
                )
            }) { Text("Включить автозаполнение") }
        }
    }
}

@Composable
private fun EntryEditor(
    record: VaultRecord?,
    onDismiss: () -> Unit,
    onSave: (String?, VaultEntry) -> Unit,
    onDelete: (String) -> Unit,
) {
    val context = LocalContext.current
    val original = record?.entry
    var title by remember { mutableStateOf(original?.title.orEmpty()) }
    var username by remember { mutableStateOf(original?.username.orEmpty()) }
    var password by remember { mutableStateOf(original?.password.orEmpty()) }
    var uris by remember { mutableStateOf(original?.uris?.joinToString("\n").orEmpty()) }
    var notes by remember { mutableStateOf(original?.notes.orEmpty()) }
    var showPassword by remember { mutableStateOf(false) }
    var confirmDelete by remember { mutableStateOf(false) }

    Dialog(onDismissRequest = onDismiss, properties = DialogProperties(usePlatformDefaultWidth = false)) {
        Card(Modifier.fillMaxWidth().padding(16.dp)) {
            Column(
                Modifier.padding(16.dp).verticalScroll(rememberScrollState()),
                verticalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                Text(if (record == null) "Новый пароль" else "Изменить", style = MaterialTheme.typography.titleLarge)
                OutlinedTextField(title, { title = it }, label = { Text("Название") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(username, { username = it }, label = { Text("Логин") }, singleLine = true, modifier = Modifier.fillMaxWidth())
                OutlinedTextField(
                    value = password,
                    onValueChange = { password = it },
                    label = { Text("Пароль") },
                    singleLine = true,
                    visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
                    modifier = Modifier.fillMaxWidth(),
                )
                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    TextButton(onClick = { showPassword = !showPassword }) { Text(if (showPassword) "Скрыть" else "Показать") }
                    TextButton(onClick = { password = generatePassword(); showPassword = true }) { Text("Сгенерировать") }
                    TextButton(onClick = { copySecret(context, password) }) { Text("Копировать") }
                }
                OutlinedTextField(
                    value = uris,
                    onValueChange = { uris = it },
                    label = { Text("Сайты и приложения (по одному в строке)") },
                    supportingText = { Text("https://example.com или androidapp://com.example.app") },
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedTextField(notes, { notes = it }, label = { Text("Заметки") }, modifier = Modifier.fillMaxWidth())

                if (!original?.history.isNullOrEmpty()) {
                    HorizontalDivider()
                    Text("Прежние пароли", style = MaterialTheme.typography.titleSmall)
                    original!!.history.forEach { old ->
                        Row(verticalAlignment = Alignment.CenterVertically) {
                            Text(
                                "•••••••• до " + DateUtils.formatDateTime(context, old.changedAt, DateUtils.FORMAT_SHOW_DATE or DateUtils.FORMAT_SHOW_TIME),
                                modifier = Modifier.weight(1f),
                                style = MaterialTheme.typography.bodySmall,
                            )
                            TextButton(onClick = { copySecret(context, old.password) }) { Text("Копировать") }
                        }
                    }
                }

                Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                    Button(
                        enabled = title.isNotBlank() && password.isNotEmpty(),
                        onClick = {
                            val now = System.currentTimeMillis()
                            val uriList = uris.lines().map { it.trim() }.filter { it.isNotEmpty() }
                            val entry = if (original == null) {
                                VaultEntry.new(title.trim(), username.trim(), password, uriList, now)
                            } else {
                                // Смена пароля идёт через changePassword — старый попадёт в историю.
                                val fieldsChanged = original.title != title.trim() || original.username != username.trim() ||
                                    original.uris != uriList || original.notes != notes
                                val withFields = if (fieldsChanged) {
                                    original.copy(title = title.trim(), username = username.trim(), uris = uriList, notes = notes, modifiedAt = now)
                                } else original
                                VaultLogic.changePassword(withFields, password, now)
                            }
                            onSave(record?.id, if (original == null) entry.copy(notes = notes) else entry)
                        },
                    ) { Text("Сохранить") }
                    TextButton(onClick = onDismiss) { Text("Отмена") }
                    if (record != null) TextButton(onClick = { confirmDelete = true }) { Text("Удалить", color = MaterialTheme.colorScheme.error) }
                }
            }
        }
    }

    if (confirmDelete && record != null) {
        AlertDialog(
            onDismissRequest = { confirmDelete = false },
            title = { Text("Удалить «${record.entry.title}»?") },
            text = { Text("Запись удалится на всех устройствах.") },
            confirmButton = { TextButton(onClick = { onDelete(record.id) }) { Text("Удалить") } },
            dismissButton = { TextButton(onClick = { confirmDelete = false }) { Text("Отмена") } },
        )
    }
}

@Composable
private fun VaultSettings(vault: VaultRepository, onDismiss: () -> Unit) {
    val activity = LocalContext.current as FragmentActivity
    val scope = rememberCoroutineScope()
    val biometric = vault.biometric
    var biometricEnabled by remember { mutableStateOf(biometric?.isEnabled == true) }
    var newPassword by remember { mutableStateOf("") }
    var message by remember { mutableStateOf<String?>(null) }
    var confirmReset by remember { mutableStateOf(false) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Настройки хранилища") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
                if (biometric?.isAvailable == true) {
                    Row(verticalAlignment = Alignment.CenterVertically) {
                        Text("Разблокировка по отпечатку", Modifier.weight(1f))
                        Switch(checked = biometricEnabled, onCheckedChange = { enable ->
                            if (!enable) {
                                biometric.disable()
                                biometricEnabled = false
                                return@Switch
                            }
                            val key = vault.key() ?: return@Switch
                            scope.launch {
                                val cipher = activity.authenticate(biometric.encryptCipher(), "Включить разблокировку по отпечатку")
                                if (cipher != null) {
                                    biometric.store(cipher, key)
                                    biometricEnabled = true
                                }
                            }
                        })
                    }
                }
                HorizontalDivider()
                PasswordField("Новый мастер-пароль", newPassword) { newPassword = it }
                OutlinedButton(
                    enabled = newPassword.length >= MIN_MASTER_LENGTH,
                    onClick = {
                        scope.launch {
                            message = runCatching { vault.changeMasterPassword(newPassword.toCharArray()) }
                                .fold({ newPassword = ""; "Мастер-пароль изменён на всех устройствах" }, { it.message })
                        }
                    },
                ) { Text("Сменить мастер-пароль") }
                message?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                HorizontalDivider()
                TextButton(onClick = { vault.lock(); onDismiss() }) { Text("Заблокировать сейчас") }
                TextButton(onClick = { confirmReset = true }) { Text("Сбросить хранилище", color = MaterialTheme.colorScheme.error) }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Готово") } },
    )

    if (confirmReset) {
        AlertDialog(
            onDismissRequest = { confirmReset = false },
            title = { Text("Сбросить хранилище?") },
            text = { Text("Все пароли будут удалены с сервера и со всех устройств без возможности восстановления.") },
            confirmButton = {
                TextButton(onClick = {
                    scope.launch {
                        runCatching { vault.reset() }.onFailure { message = it.message }
                        confirmReset = false
                        onDismiss()
                    }
                }) { Text("Сбросить", color = MaterialTheme.colorScheme.error) }
            },
            dismissButton = { TextButton(onClick = { confirmReset = false }) { Text("Отмена") } },
        )
    }
}

@Composable
private fun PasswordField(label: String, value: String, onChange: (String) -> Unit) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        singleLine = true,
        visualTransformation = PasswordVisualTransformation(),
        keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
        modifier = Modifier.fillMaxWidth(),
    )
}

/** Копирует секрет, помечая его как чувствительный: Android 13+ не покажет его в превью буфера. */
private fun copySecret(context: Context, value: String) {
    val clip = ClipData.newPlainText("password", value)
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) {
        clip.description.extras = PersistableBundle().apply { putBoolean(ClipDescription.EXTRA_IS_SENSITIVE, true) }
    }
    context.getSystemService(ClipboardManager::class.java).setPrimaryClip(clip)
    Toast.makeText(context, "Скопировано", Toast.LENGTH_SHORT).show()
}

private val random = SecureRandom()
private const val ALPHABET = "abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789!@#$%^&*-_=+?"

fun generatePassword(length: Int = 20): String = buildString { repeat(length) { append(ALPHABET[random.nextInt(ALPHABET.length)]) } }

private const val MIN_MASTER_LENGTH = 10
