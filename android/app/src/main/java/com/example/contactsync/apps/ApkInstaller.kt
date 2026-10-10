package com.example.contactsync.apps

import android.Manifest
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.core.content.IntentCompat
import com.example.contactsync.App
import com.example.contactsync.data.Api
import com.example.contactsync.data.BackedUpApp
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.flow.first
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.SharedFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asSharedFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.withContext
import java.io.File
import java.io.IOException

/** Итог установки, приходит от системы асинхронно. */
data class InstallEvent(val packageName: String, val success: Boolean, val message: String?)

/**
 * Установка сохранённых APK: скачать все части, проверить хэши и подпись, поставить одной сессией
 * PackageInstaller. Каждую установку подтверждает пользователь — так устроен Android для обычных приложений.
 * Исключение — Contact Sync, встроенный в прошивку с INSTALL_PACKAGES: он ставит молча, как Google Play.
 */
class ApkInstaller(private val context: Context, private val api: Api) {

    private val _events = MutableSharedFlow<InstallEvent>(extraBufferCapacity = 8)
    val events: SharedFlow<InstallEvent> = _events.asSharedFlow()

    private val _downloads = MutableStateFlow<Map<String, DownloadProgress>>(emptyMap())

    /** Идущие скачивания по пакетам. Живёт дольше экрана: установка продолжается и после ухода с него. */
    val downloads: StateFlow<Map<String, DownloadProgress>> = _downloads.asStateFlow()

    /** Встроено в прошивку как привилегированное (priv-app + privapp-permissions). */
    val isSystemInstaller: Boolean
        get() = context.checkSelfPermission(Manifest.permission.INSTALL_PACKAGES) == PackageManager.PERMISSION_GRANTED

    fun canInstall(): Boolean = isSystemInstaller || context.packageManager.canRequestPackageInstalls()

    /**
     * Системный диалог удаления приложения. Нужен для отката: Android не ставит старую версию
     * поверх новой, сначала текущую надо удалить (данные приложения при этом удаляются).
     */
    fun uninstallIntent(packageName: String): Intent =
        Intent(Intent.ACTION_DELETE, Uri.parse("package:$packageName"))

    /** Экран системных настроек «Установка неизвестных приложений» для нашего приложения. */
    fun permissionSettingsIntent(): Intent =
        Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:${context.packageName}"))

    /** @param previous поставить прошлую сохранённую версию (откат) вместо текущей */
    suspend fun install(app: BackedUpApp, previous: Boolean = false) = withContext(Dispatchers.IO) {
        val meta = if (previous) requireNotNull(app.previous) { "Прошлой версии нет" }.files else app.files
        val dir = File(context.cacheDir, "apk").apply { mkdirs() }
        val total = meta.sumOf { it.size }
        var done = 0L
        reportDownload(app.packageName, DownloadProgress(0, total))
        val files = try {
            meta.map { file ->
                File(dir, "${file.sha256}.apk").also { target ->
                    val onProgress = { bytes: Long -> reportDownload(app.packageName, DownloadProgress(done + bytes, total)) }
                    if (!target.exists() || AppInventory.sha256(target) != file.sha256) {
                        // Недокачанный в прошлый раз файл докачивается; не сошёлся хэш и после этого —
                        // значит, испорчен, качаем заново целиком.
                        api.downloadApk(file.sha256, target, onProgress)
                        if (AppInventory.sha256(target) != file.sha256) {
                            target.delete()
                            api.downloadApk(file.sha256, target, onProgress)
                        }
                        if (AppInventory.sha256(target) != file.sha256) {
                            target.delete()
                            throw IOException("Файл ${file.name} повреждён при скачивании")
                        }
                    }
                    done += file.size
                    reportDownload(app.packageName, DownloadProgress(done, total))
                }
            }
        } finally {
            _downloads.update { it - app.packageName }
        }
        val baseIndex = meta.indexOfFirst { it.name == "base.apk" }.coerceAtLeast(0)
        verifySignature(app, files[baseIndex])

        val installer = context.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
            setAppPackageName(app.packageName)
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                setRequireUserAction(
                    if (isSystemInstaller) PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED
                    else PackageInstaller.SessionParams.USER_ACTION_REQUIRED,
                )
            }
        }
        val sessionId = installer.createSession(params)
        installer.openSession(sessionId).use { session ->
            meta.zip(files).forEach { (apk, file) ->
                session.openWrite(apk.name, 0, file.length()).use { out ->
                    file.inputStream().use { it.copyTo(out) }
                    session.fsync(out)
                }
            }
            session.commit(InstallResultReceiver.intentSender(context, sessionId, app.packageName))
        }
    }

    /** Ставит и ждёт итога от системы — чтобы восстанавливать приложения по очереди. */
    suspend fun installAndAwait(app: BackedUpApp): InstallEvent = coroutineScope {
        val result = async(start = CoroutineStart.UNDISPATCHED) { events.first { it.packageName == app.packageName } }
        try {
            install(app)
        } catch (e: Exception) {
            result.cancel()
            throw e
        }
        result.await()
    }

    /**
     * Подпись скачанного APK должна совпасть с сохранённой при бэкапе и с уже установленной версией,
     * если она есть. Иначе это другой (возможно, подменённый) пакет.
     */
    private fun verifySignature(app: BackedUpApp, apk: File) {
        val pm = context.packageManager
        val flags = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) PackageManager.GET_SIGNING_CERTIFICATES else @Suppress("DEPRECATION") PackageManager.GET_SIGNATURES
        val archive = pm.getPackageArchiveInfo(apk.path, flags) ?: throw IOException("Не удалось прочитать APK")
        if (archive.packageName != app.packageName) throw IOException("В APK другой пакет: ${archive.packageName}")

        val signature = AppInventory.signingSha256(archive)
        if (app.signingSha256 != null && signature != app.signingSha256) {
            throw IOException("Подпись APK не совпадает с сохранённой")
        }
        val installed = runCatching { pm.getPackageInfo(app.packageName, flags) }.getOrNull()
        if (installed != null && AppInventory.signingSha256(installed) != signature) {
            throw IOException("Установлена версия с другой подписью — удалите её перед восстановлением")
        }
    }

    /** Колбэк приходит на каждый буфер чтения — экран обновляем только при смене целого процента. */
    private fun reportDownload(packageName: String, progress: DownloadProgress) {
        _downloads.update { current ->
            if (current[packageName]?.percent == progress.percent) current else current + (packageName to progress)
        }
    }

    fun onResult(event: InstallEvent) {
        _events.tryEmit(event)
    }
}

/** Получает от системы статус сессии установки. */
class InstallResultReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val packageName = intent.getStringExtra(EXTRA_PACKAGE).orEmpty()
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)
        val installer = (context.applicationContext as App).apkInstaller
        when (status) {
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                // Система просит подтверждения у пользователя — показываем её диалог.
                IntentCompat.getParcelableExtra(intent, Intent.EXTRA_INTENT, Intent::class.java)?.let {
                    context.startActivity(it.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                }
            }
            PackageInstaller.STATUS_SUCCESS -> installer.onResult(InstallEvent(packageName, true, null))
            else -> installer.onResult(
                InstallEvent(packageName, false, intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE) ?: "Установка отменена"),
            )
        }
    }

    companion object {
        private const val EXTRA_PACKAGE = "package"

        fun intentSender(context: Context, sessionId: Int, packageName: String) = PendingIntent.getBroadcast(
            context,
            sessionId,
            Intent(context, InstallResultReceiver::class.java).putExtra(EXTRA_PACKAGE, packageName),
            PendingIntent.FLAG_UPDATE_CURRENT or PendingIntent.FLAG_MUTABLE,
        ).intentSender
    }
}
