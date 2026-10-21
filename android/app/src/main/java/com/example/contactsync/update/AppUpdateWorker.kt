package com.example.contactsync.update

import android.Manifest
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.app.NotificationCompat
import androidx.core.app.NotificationManagerCompat
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.NetworkType
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import com.example.contactsync.App
import com.example.contactsync.data.ClientReleaseDto
import com.example.contactsync.ui.MainActivity
import java.util.concurrent.TimeUnit

/**
 * Раз в 6 часов спрашивает сервер о новой версии и напоминает уведомлением — один раз на версию.
 * Нужен там, где приложение редко открывают (на планшете главный экран — лаунчер).
 */
class AppUpdateWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as App
        val remote = runCatching { app.api.appVersion() }.getOrNull()
        if (UpdatePolicy.shouldNotify(remote, app.updater.installedVersionCode, app.session.notifiedUpdateVersion)) {
            if (notify(applicationContext, remote!!)) app.session.notifiedUpdateVersion = remote.versionCode
        }
        return Result.success()
    }

    companion object {
        private const val WORK = "app-update-check"
        private const val CHANNEL = "app-update"
        private const val NOTIFICATION_ID = 7001

        fun schedule(context: Context) {
            val request = PeriodicWorkRequestBuilder<AppUpdateWorker>(6, TimeUnit.HOURS)
                .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork(WORK, ExistingPeriodicWorkPolicy.KEEP, request)
        }

        /** @return показано ли уведомление (на Android 13+ без разрешения — нет) */
        private fun notify(context: Context, version: ClientReleaseDto): Boolean {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU &&
                context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
            ) {
                return false
            }
            context.getSystemService(NotificationManager::class.java)
                .createNotificationChannel(NotificationChannel(CHANNEL, "Обновление приложения", NotificationManager.IMPORTANCE_DEFAULT))
            val open = PendingIntent.getActivity(
                context, NOTIFICATION_ID, MainActivity.updateIntent(context),
                PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT,
            )
            val notification = NotificationCompat.Builder(context, CHANNEL)
                .setSmallIcon(android.R.drawable.stat_sys_download_done)
                .setContentTitle("Доступна версия ${version.versionName}")
                .setContentText("Нажмите, чтобы обновить Contact Sync")
                .setContentIntent(open)
                .setAutoCancel(true)
                .build()
            NotificationManagerCompat.from(context).notify(NOTIFICATION_ID, notification)
            return true
        }
    }
}
