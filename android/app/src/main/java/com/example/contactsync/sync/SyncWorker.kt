package com.example.contactsync.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.example.contactsync.App
import com.example.contactsync.data.UnauthorizedException
import java.io.IOException

/**
 * Фоновая синхронизация. Запускается периодически и при изменении телефонной книги.
 */
class SyncWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as App
        val observer = inputData.getBoolean(KEY_OBSERVER, false)
        try {
            if (!app.session.isLoggedIn || !app.session.backgroundSync) return Result.success()
            if (!app.engine.hasPermissions()) {
                runCatching { app.vault.sync() }
                return Result.success()
            }

            app.engine.sync()
            // Хранилище паролей — заодно: без ключа, только шифротекст. Его сбой не должен ломать контакты.
            runCatching { app.vault.sync() }.onFailure { Log.w(TAG, "Хранилище не синхронизировано", it) }
            return Result.success()
        } catch (e: UnauthorizedException) {
            Log.w(TAG, "Токен отклонён, фоновая синхронизация остановлена")
            app.clearAccountData()
            return Result.failure()
        } catch (e: IOException) {
            Log.w(TAG, "Сеть недоступна, повторим позже", e)
            return Result.retry()
        } finally {
            // Триггер по content URI одноразовый: после срабатывания ставим наблюдение снова.
            if (observer && app.session.isLoggedIn && app.session.backgroundSync) {
                SyncScheduler.observeContacts(applicationContext)
            }
        }
    }

    companion object {
        const val KEY_OBSERVER = "observer"
        private const val TAG = "SyncWorker"
    }
}
