package com.example.contactsync.autofill

import android.os.Build
import android.os.CancellationSignal
import android.service.autofill.AutofillService
import android.service.autofill.FillCallback
import android.service.autofill.FillRequest
import android.service.autofill.SaveCallback
import android.service.autofill.SaveRequest
import com.example.contactsync.App
import com.example.contactsync.vault.SavePlan
import com.example.contactsync.vault.VaultStatus
import kotlinx.coroutines.launch
import java.util.UUID
import java.util.concurrent.ConcurrentHashMap

/** Логин, который пользователь ввёл в другом приложении и который ждёт подтверждения сохранения. */
data class PendingSave(val uri: String, val title: String, val username: String, val password: String)

/** Живут в памяти процесса: пароль не пишется на диск и не передаётся через Intent. */
object PendingSaves {
    private val saves = ConcurrentHashMap<String, PendingSave>()

    fun put(save: PendingSave): String = UUID.randomUUID().toString().also { saves[it] = save }

    fun take(id: String): PendingSave? = saves.remove(id)

    fun peek(id: String): PendingSave? = saves[id]
}

/**
 * Сервис автозаполнения: подставляет логины из хранилища и предлагает сохранить новые или изменённые пароли.
 */
class VaultAutofillService : AutofillService() {

    private val app get() = application as App

    override fun onFillRequest(request: FillRequest, cancellationSignal: CancellationSignal, callback: FillCallback) {
        val form = FormParser.parse(request.fillContexts.last().structure)
        if (form == null || form.packageName == packageName || !app.session.isLoggedIn) {
            callback.onSuccess(null)
            return
        }
        val vault = app.vault
        val response = when {
            vault.status.value == VaultStatus.NOT_SET_UP -> null
            vault.isUnlocked() -> AutofillResponses.fill(this, form, vault.recordsFor(form.uri))
            else -> AutofillResponses.locked(this, form)
        }
        callback.onSuccess(response)
    }

    override fun onSaveRequest(request: SaveRequest, callback: SaveCallback) {
        val form = FormParser.parse(request.fillContexts.last().structure)
        val password = form?.newPassword
        if (form == null || password == null || app.vault.status.value == VaultStatus.NOT_SET_UP) {
            callback.onFailure("Нечего сохранить")
            return
        }
        val save = PendingSave(form.uri, titleFor(form), form.username, password)

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            // Покажем своё окно: что именно сохраняем или обновляем, и при необходимости — разблокировку.
            callback.onSuccess(SaveActivity.intentSender(this, PendingSaves.put(save)))
        } else if (app.vault.isUnlocked()) {
            val plan = app.vault.planSave(save.uri, save.title, save.username, save.password)
            app.scope.launch {
                app.vault.applySave(plan)
                if (plan !is SavePlan.Unchanged) app.syncVaultSoon()
            }
            callback.onSuccess()
        } else {
            callback.onFailure("Хранилище заблокировано: откройте Contact Sync и сохраните пароль вручную")
        }
    }

    private fun titleFor(form: ParsedForm): String = form.webDomain ?: runCatching {
        packageManager.getApplicationLabel(packageManager.getApplicationInfo(form.packageName, 0)).toString()
    }.getOrDefault(form.packageName)
}
