package com.example.contactsync.autofill

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.IntentSender
import android.service.autofill.Dataset
import android.service.autofill.FillResponse
import android.service.autofill.SaveInfo
import android.view.autofill.AutofillValue
import android.widget.RemoteViews
import com.example.contactsync.R
import com.example.contactsync.vault.VaultRecord

/** Сборка ответов системе автозаполнения. */
@Suppress("DEPRECATION") // Конструкторы Dataset с RemoteViews — единственный вариант для API 26–32.
object AutofillResponses {

    /** Ответ для разблокированного хранилища: подходящие записи + предложение сохранить введённое. */
    fun fill(context: Context, form: ParsedForm, records: List<VaultRecord>): FillResponse? {
        val builder = FillResponse.Builder()
        var hasContent = false
        for (record in records.take(MAX_DATASETS)) {
            val presentation = item(context, record.entry.title, record.entry.username.ifBlank { "без логина" })
            val dataset = Dataset.Builder(presentation)
            form.usernameIds.forEach { dataset.setValue(it, AutofillValue.forText(record.entry.username)) }
            form.passwordIds.forEach { dataset.setValue(it, AutofillValue.forText(record.entry.password)) }
            if (form.allIds.isNotEmpty()) {
                builder.addDataset(dataset.build())
                hasContent = true
            }
        }
        saveInfo(form)?.let {
            builder.setSaveInfo(it)
            hasContent = true
        }
        return if (hasContent) builder.build() else null
    }

    /** Хранилище заблокировано: пункт «Разблокировать», по нажатию — [UnlockActivity]. */
    fun locked(context: Context, form: ParsedForm): FillResponse =
        FillResponse.Builder()
            .setAuthentication(
                form.allIds.toTypedArray(),
                UnlockActivity.intentSender(context),
                item(context, "Contact Sync", "Разблокировать, чтобы заполнить"),
            )
            .apply { saveInfo(form)?.let(::setSaveInfo) }
            .build()

    /**
     * Просим систему предложить сохранение, когда пользователь отправит форму с паролем.
     * Так ловятся и новые логины, и смена пароля на сайте или в приложении.
     */
    private fun saveInfo(form: ParsedForm): SaveInfo? {
        if (form.passwordIds.isEmpty()) return null
        return SaveInfo.Builder(SaveInfo.SAVE_DATA_TYPE_PASSWORD or SaveInfo.SAVE_DATA_TYPE_USERNAME, form.passwordIds.toTypedArray())
            .apply { if (form.usernameIds.isNotEmpty()) setOptionalIds(form.usernameIds.toTypedArray()) }
            // Одностраничные формы в WebView не закрывают экран — сохраняем, когда поля пропали.
            .setFlags(SaveInfo.FLAG_SAVE_ON_ALL_VIEWS_INVISIBLE)
            .build()
    }

    private fun item(context: Context, title: String, subtitle: String) =
        RemoteViews(context.packageName, R.layout.autofill_item).apply {
            setTextViewText(R.id.title, title)
            setTextViewText(R.id.subtitle, subtitle)
        }

    fun pendingIntentSender(context: Context, requestCode: Int, intent: Intent): IntentSender =
        PendingIntent.getActivity(
            context,
            requestCode,
            intent,
            // Система дописывает в интент структуру формы, поэтому он должен быть изменяемым.
            PendingIntent.FLAG_CANCEL_CURRENT or PendingIntent.FLAG_MUTABLE,
        ).intentSender

    private const val MAX_DATASETS = 10
}
