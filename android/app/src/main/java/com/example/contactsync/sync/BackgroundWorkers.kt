package com.example.contactsync.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.example.contactsync.App
import com.example.contactsync.data.UnauthorizedException
import java.io.IOException

/** Синхронизация хранилища паролей. Ключ не нужен: гоняется только шифротекст. */
class VaultSyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as App
        if (!app.session.isLoggedIn) return Result.success()
        return try {
            app.vault.sync()
            Result.success()
        } catch (e: UnauthorizedException) {
            Result.failure()
        } catch (e: IOException) {
            Log.w("VaultSyncWorker", "Не удалось синхронизировать хранилище", e)
            Result.retry()
        }
    }
}

/** Резервное копирование приложений: список + загрузка недостающих APK. */
class ApkBackupWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as App
        if (!app.session.isLoggedIn) return Result.success()
        return try {
            val report = app.apkBackup.run()
            app.session.lastAppsBackupAt = System.currentTimeMillis()
            app.session.lastAppsBackupSummary = "Приложений: ${report.apps}, загружено APK: ${report.uploaded}" +
                if (report.remaining > 0) ", осталось: ${report.remaining}" else ""
            // Не всё успели (лимит работы воркера ~10 минут) — продолжим с места обрыва.
            if (report.remaining > 0) Result.retry() else Result.success()
        } catch (e: UnauthorizedException) {
            Result.failure()
        } catch (e: IOException) {
            Log.w("ApkBackupWorker", "Бэкап приложений прерван", e)
            Result.retry()
        }
    }
}
