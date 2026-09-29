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
import androidx.work.WorkInfo
import androidx.work.WorkManager
import androidx.work.WorkQuery
import androidx.work.workDataOf
import java.util.concurrent.TimeUnit

object SyncScheduler {

    private const val PERIODIC = "sync-periodic"
    private const val ON_CHANGE = "sync-on-contacts-change"
    private const val VAULT_NOW = "vault-sync-now"
    private const val APPS_PERIODIC = "apps-backup-periodic"
    private const val APPS_NOW = "apps-backup-now"
    private const val APPS_NOW_TAG = "apps-backup-manual"

    private val network = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()

    fun enable(context: Context) {
        val periodic = PeriodicWorkRequestBuilder<SyncWorker>(6, TimeUnit.HOURS)
            .setConstraints(network)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 5, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(PERIODIC, ExistingPeriodicWorkPolicy.KEEP, periodic)
        observeContacts(context, ExistingWorkPolicy.KEEP)

        // APK — тяжёлые файлы: раз в сутки, только по Wi-Fi и на зарядке.
        val apps = PeriodicWorkRequestBuilder<ApkBackupWorker>(1, TimeUnit.DAYS)
            .setConstraints(
                Constraints.Builder()
                    .setRequiredNetworkType(NetworkType.UNMETERED)
                    .setRequiresCharging(true)
                    .build(),
            )
            .setBackoffCriteria(BackoffPolicy.LINEAR, 15, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(APPS_PERIODIC, ExistingPeriodicWorkPolicy.KEEP, apps)
    }

    fun disable(context: Context) {
        WorkManager.getInstance(context).apply {
            cancelUniqueWork(PERIODIC)
            cancelUniqueWork(ON_CHANGE)
            cancelUniqueWork(APPS_PERIODIC)
        }
    }

    /** Отправить правки хранилища сразу, как будет сеть. */
    fun syncVaultNow(context: Context) {
        val request = OneTimeWorkRequestBuilder<VaultSyncWorker>()
            .setConstraints(network)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(VAULT_NOW, ExistingWorkPolicy.REPLACE, request)
    }

    /** Бэкап приложений по кнопке: только Wi-Fi, без требования зарядки. */
    fun backupAppsNow(context: Context) {
        val request = OneTimeWorkRequestBuilder<ApkBackupWorker>()
            .addTag(APPS_NOW_TAG)
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.UNMETERED).build())
            .setBackoffCriteria(BackoffPolicy.LINEAR, 1, TimeUnit.MINUTES)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(APPS_NOW, ExistingWorkPolicy.KEEP, request)
    }

    /** Состояние бэкапа приложений: ручной запуск и ежесуточный. */
    fun appsBackupState(context: Context) =
        WorkManager.getInstance(context).getWorkInfosFlow(WorkQuery.fromUniqueWorkNames(APPS_NOW, APPS_PERIODIC))

    fun isManualAppsBackup(info: WorkInfo) = APPS_NOW_TAG in info.tags

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
