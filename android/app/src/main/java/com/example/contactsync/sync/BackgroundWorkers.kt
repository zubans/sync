package com.example.contactsync.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.example.contactsync.App
import com.example.contactsync.data.UnauthorizedException
import androidx.work.workDataOf
import java.io.IOException
import java.net.ConnectException
import java.net.SocketTimeoutException
import java.net.UnknownHostException

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
            val report = app.apkBackup.run { setProgress(workDataOf(PROGRESS to it)) }
            app.session.lastAppsBackupAt = System.currentTimeMillis()
            app.session.lastAppsBackupError = null
            app.session.lastAppsBackupSummary = "Приложений: ${report.apps}, загружено APK: ${report.uploaded}" +
                if (report.remaining > 0) ", осталось: ${report.remaining}" else ""
            // Не всё успели (лимит работы воркера ~10 минут) — продолжим с места обрыва.
            if (report.remaining > 0) Result.retry() else Result.success()
        } catch (e: UnauthorizedException) {
            Result.failure()
        } catch (e: IOException) {
            Log.w("ApkBackupWorker", "Бэкап приложений прерван", e)
            app.session.lastAppsBackupError = describe(e)
            Result.retry()
        }
    }

    private fun describe(e: IOException): String = when (e) {
        is SocketTimeoutException, is ConnectException, is UnknownHostException -> "сервер недоступен"
        else -> e.message ?: e.javaClass.simpleName
    }

    companion object {
        const val PROGRESS = "progress"
    }
}
