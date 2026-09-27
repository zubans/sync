package com.example.contactsync.sync

import android.content.Context
import android.provider.ContactsContract
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.workDataOf
import java.util.concurrent.TimeUnit

object SyncScheduler {

    private const val PERIODIC = "sync-periodic"
    private const val ON_CHANGE = "sync-on-contacts-change"

    private val network = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun enable(context: Context) {
        val periodic = PeriodicWorkRequestBuilder<SyncWorker>(6, TimeUnit.HOURS)
            .setConstraints(network)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 5, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.KEEP, periodic)
        observeContacts(context, ExistingWorkPolicy.KEEP)
    }

    fun disable(context: Context) {
        WorkManager.getInstance(context).apply {
            cancelUniqueWork(PERIODIC)
            cancelUniqueWork(ON_CHANGE)
        }
    }

    /**
     * Синхронизация после изменения контактов. Задержки собирают серию правок в один запуск.
     *
     * Из самого воркера вызывается с APPEND_OR_REPLACE: новое наблюдение встаёт после текущего запуска,
     * а не отменяет его. При включении — KEEP, чтобы не наращивать очередь.
     */
    fun observeContacts(context: Context, policy: ExistingWorkPolicy = ExistingWorkPolicy.APPEND_OR_REPLACE) {
        val request = OneTimeWorkRequestBuilder<SyncWorker>()
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.CONNECTED)
                    .addContentUriTrigger(ContactsContract.Contacts.CONTENT_URI, true)
                    .setTriggerContentUpdateDelay(1, TimeUnit.MINUTES)
                    .setTriggerContentMaxDelay(10, TimeUnit.MINUTES)
                    .build(),
            )
            .setInputData(workDataOf(SyncWorker.KEY_OBSERVER to true))
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(ON_CHANGE, policy, request)
    }
}
