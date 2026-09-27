package com.example.contactsync.autofill

import android.app.assist.AssistStructure
import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.os.Bundle
import android.view.autofill.AutofillManager
import androidx.activity.compose.setContent
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.padding
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Surface
import androidx.compose.material3.Text
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.core.content.IntentCompat
import androidx.fragment.app.FragmentActivity
import com.example.contactsync.App
import com.example.contactsync.ui.vault.UnlockPanel

/**
 * Разблокировка хранилища по запросу автозаполнения. После разблокировки возвращает системе
 * подходящие логины для формы, с которой пришёл запрос.
 */
class UnlockActivity : FragmentActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val app = application as App
        val structure = IntentCompat.getParcelableExtra(intent, AutofillManager.EXTRA_ASSIST_STRUCTURE, AssistStructure::class.java)
        val form = structure?.let(FormParser::parse)
        if (form == null) {
            finish()
            return
        }

        setContent {
            MaterialTheme {
                Surface {
                    Column(Modifier.padding(24.dp), verticalArrangement = Arrangement.spacedBy(16.dp)) {
                        Text("Contact Sync", style = MaterialTheme.typography.headlineSmall)
                        Text("Разблокируйте хранилище, чтобы заполнить вход в ${form.webDomain ?: form.packageName}")
                        UnlockPanel(app.vault, onUnlocked = {
                            val response = AutofillResponses.fill(this@UnlockActivity, form, app.vault.recordsFor(form.uri))
                            if (response != null) {
                                setResult(RESULT_OK, Intent().putExtra(AutofillManager.EXTRA_AUTHENTICATION_RESULT, response))
                            } else {
                                setResult(RESULT_CANCELED)
                            }
                            finish()
                        })
                    }
                }
            }
        }
    }

    companion object {
        fun intentSender(context: Context): IntentSender =
            AutofillResponses.pendingIntentSender(context, 1, Intent(context, UnlockActivity::class.java))
    }
}
