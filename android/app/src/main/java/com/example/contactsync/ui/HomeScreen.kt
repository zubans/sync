package com.example.contactsync.ui

import android.Manifest
import android.content.pm.PackageManager
import android.text.format.DateUtils
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.core.content.ContextCompat

/** READ/WRITE — работа с книгой; GET_ACCOUNTS — список Google-аккаунтов на старых Android. */
private val PERMISSIONS = arrayOf(
    Manifest.permission.READ_CONTACTS,
    Manifest.permission.WRITE_CONTACTS,
    Manifest.permission.GET_ACCOUNTS,
)

@Composable
fun HomeScreen(state: UiState, vm: AppViewModel) {
    val context = LocalContext.current
    val launcher = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        val contactsGranted = result[Manifest.permission.READ_CONTACTS] == true &&
            result[Manifest.permission.WRITE_CONTACTS] == true
        if (contactsGranted) vm.syncNow() else vm.onPermissionsDenied()
    }

    fun withPermissions(action: () -> Unit) {
        val granted = PERMISSIONS.all { ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED }
        if (granted) action() else launcher.launch(PERMISSIONS)
    }

    // Сразу после входа: запросить доступ к контактам и восстановить их.
    LaunchedEffect(state.pendingRestore, state.busy) {
        if (state.pendingRestore && !state.busy) withPermissions(vm::syncNow)
    }

    Scaffold { padding ->
        Column(
            modifier = Modifier
                .fillMaxSize()
                .padding(padding)
                .padding(16.dp)
                .verticalScroll(rememberScrollState()),
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            Text("Contact Sync", style = MaterialTheme.typography.headlineMedium)

            Card(modifier = Modifier.fillMaxWidth()) {
                Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                    Text(state.email.orEmpty(), style = MaterialTheme.typography.titleMedium)
                    Text(
                        state.familyName?.let { "Семья: $it" } ?: "Не состоит в семейной группе",
                        style = MaterialTheme.typography.bodyMedium,
                    )
                    Text(
                        if (state.lastSyncAt > 0) {
                            "Последняя синхронизация: " + DateUtils.getRelativeTimeSpanString(state.lastSyncAt)
                        } else {
                            "Ещё не синхронизировались"
                        },
                        style = MaterialTheme.typography.bodySmall,
                    )
                    state.lastSyncSummary?.let { Text(it, style = MaterialTheme.typography.bodySmall) }
                }
            }

            if (state.busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
            state.message?.let { Text(it, color = MaterialTheme.colorScheme.primary) }
            state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }

            Button(onClick = { withPermissions(vm::syncNow) }, enabled = !state.busy, modifier = Modifier.fillMaxWidth()) {
                Text("Синхронизировать сейчас")
            }
            OutlinedButton(onClick = { withPermissions(vm::restore) }, enabled = !state.busy, modifier = Modifier.fillMaxWidth()) {
                Text("Восстановить контакты с сервера")
            }

            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("Фоновая синхронизация")
                    Text(
                        "Раз в 6 часов и после изменения контактов",
                        style = MaterialTheme.typography.bodySmall,
                    )
                }
                Switch(checked = state.backgroundSync, onCheckedChange = vm::setBackgroundSync)
            }

            if (state.googleAccounts.isNotEmpty()) {
                HorizontalDivider()
                Text("Google-аккаунты на устройстве", style = MaterialTheme.typography.titleSmall)
                state.googleAccounts.forEach { Text(it, style = MaterialTheme.typography.bodyMedium) }
            }

            HorizontalDivider()
            TextButton(onClick = vm::logout, enabled = !state.busy) { Text("Выйти") }
        }
    }
}
