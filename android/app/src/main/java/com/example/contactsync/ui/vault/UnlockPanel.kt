package com.example.contactsync.ui.vault

import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.Button
import androidx.compose.material3.LinearProgressIndicator
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import com.example.contactsync.vault.VaultRepository
import com.example.contactsync.vault.WrongMasterPasswordException
import com.example.contactsync.vault.authenticate
import kotlinx.coroutines.launch

/**
 * Разблокировка хранилища: мастер-паролем или отпечатком (если включён).
 * Используется на экране паролей, в окне автозаполнения и в окне сохранения пароля.
 */
@Composable
fun UnlockPanel(vault: VaultRepository, onUnlocked: () -> Unit, autoBiometric: Boolean = true) {
    val activity = LocalContext.current as FragmentActivity
    val scope = rememberCoroutineScope()
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    fun unlockWithPassword() {
        if (busy || password.isEmpty()) return
        busy = true
        error = null
        scope.launch {
            try {
                vault.unlock(password.toCharArray())
                password = ""
                onUnlocked()
            } catch (e: WrongMasterPasswordException) {
                error = e.message
            } catch (e: Exception) {
                error = e.message ?: "Не удалось разблокировать"
            } finally {
                busy = false
            }
        }
    }

    fun unlockWithBiometric() {
        val biometric = vault.biometric ?: return
        val cipher = biometric.decryptCipher() ?: run {
            error = "Отпечаток изменился — войдите мастер-паролем и включите разблокировку заново"
            return
        }
        scope.launch {
            val authenticated = activity.authenticate(cipher, "Разблокировать пароли") ?: return@launch
            vault.unlockWith(biometric.open(authenticated))
            onUnlocked()
        }
    }

    val biometricEnabled = vault.biometric?.isEnabled == true
    LaunchedEffect(Unit) {
        if (autoBiometric && biometricEnabled) unlockWithBiometric()
    }

    Column(verticalArrangement = Arrangement.spacedBy(12.dp)) {
        OutlinedTextField(
            value = password,
            onValueChange = { password = it },
            label = { Text("Мастер-пароль") },
            singleLine = true,
            visualTransformation = PasswordVisualTransformation(),
            keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password, imeAction = ImeAction.Done),
            keyboardActions = KeyboardActions(onDone = { unlockWithPassword() }),
            modifier = Modifier.fillMaxWidth(),
        )
        if (busy) LinearProgressIndicator(modifier = Modifier.fillMaxWidth())
        error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
        Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
            Button(onClick = ::unlockWithPassword, enabled = !busy && password.isNotEmpty()) { Text("Разблокировать") }
            if (biometricEnabled) OutlinedButton(onClick = ::unlockWithBiometric, enabled = !busy) { Text("По отпечатку") }
        }
    }
}
