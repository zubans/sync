package com.example.contactsync.autofill

import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.Button
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.fragment.app.FragmentActivity
import com.example.contactsync.App
import com.example.contactsync.ui.vault.UnlockPanel
import com.example.contactsync.vault.SavePlan
import com.example.contactsync.vault.VaultStatus
import kotlinx.coroutines.launch

/**
 * Подтверждение сохранения логина, введённого в другом приложении. Показывает, что произойдёт:
 * новая запись, обновление пароля (старый уйдёт в историю) или ничего — пароль не изменился.
 */
class SaveActivity : FragmentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as App
        val saveId = intent.getStringExtra(EXTRA_SAVE_ID)
        val save = saveId?.let(PendingSaves::peek)
        if (save == null) {
            finish()
            return
        }

        setContent {
            MaterialTheme {
                Surface {
                    val status by app.vault.status.collectAsState()
                    val scope = rememberCoroutineScope()
                    Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
                        Text("Contact Sync", style = MaterialTheme.typography.headlineSmall)
                        if (status != VaultStatus.UNLOCKED) {
                            Text("Разблокируйте хранилище, чтобы сохранить пароль для ${save.title}")
                            UnlockPanel(app.vault, onUnlocked = {})
                        } else {
                            val plan = app.vault.planSave(save.uri, save.title, save.username, save.password)
                            Text(describe(plan, save))
                            Row(horizontalArrangement = Arrangement.spacedBy(12.dp)) {
                                if (plan !is SavePlan.Unchanged) {
                                    Button(onClick = {
                                        scope.launch {
                                            app.vault.applySave(plan)
                                            PendingSaves.take(saveId)
                                            app.syncVaultSoon()
                                            finish()
                                        }
                                    }) { Text(if (plan is SavePlan.Update) "Обновить" else "Сохранить") }
                                }
                                TextButton(onClick = {
                                    PendingSaves.take(saveId)
                                    finish()
                                }) { Text(if (plan is SavePlan.Unchanged) "Закрыть" else "Не сохранять") }
                            }
                        }
                    }
                }
            }
        }
    }

    private fun describe(plan: SavePlan, save: PendingSave): String {
        val who = save.username.ifBlank { "без логина" }
        return when (plan) {
            is SavePlan.Create -> "Сохранить новый пароль для ${save.title} ($who)?"
            is SavePlan.Update -> "Пароль для «${plan.before.title}» (${plan.after.username.ifBlank { who }}) изменился. " +
                "Обновить? Прежний пароль останется в истории записи."
            is SavePlan.Unchanged -> "Этот пароль для ${save.title} уже сохранён."
        }
    }

    companion object {
        private const val EXTRA_SAVE_ID = "save_id"

        fun intentSender(context: Context, saveId: String): IntentSender =
            AutofillResponses.pendingIntentSender(
                context,
                saveId.hashCode(),
                Intent(context, SaveActivity::class.java).putExtra(EXTRA_SAVE_ID, saveId),
            )
    }
}
