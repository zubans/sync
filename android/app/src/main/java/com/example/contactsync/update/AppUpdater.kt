package com.example.contactsync.update

import android.content.Context
import com.example.contactsync.BuildConfig
import com.example.contactsync.apps.ApkInstaller
import com.example.contactsync.apps.AppInventory
import com.example.contactsync.data.Api
import com.example.contactsync.data.ClientReleaseDto
import java.io.File
import java.io.IOException

/** Когда предлагать обновление. Чистая логика — покрыта unit-тестами. */
object UpdatePolicy {
    /** Обновляем только вперёд: активная прошлая версия на сервере — не повод откатывать телефон. */
    fun isNewer(remote: ClientReleaseDto?, installedCode: Int): Boolean = remote != null && remote.versionCode > installedCode

    /** Уведомление — один раз на каждую новую версию. */
    fun shouldNotify(remote: ClientReleaseDto?, installedCode: Int, notifiedCode: Int): Boolean =
        isNewer(remote, installedCode) && remote!!.versionCode != notifiedCode
}

/**
 * Обновление приложения с сервера: проверка версии (GET /api/app/version), скачивание сборки с докачкой
 * и проверкой SHA-256, установка через системный установщик (пользователь подтверждает).
 */
class AppUpdater(private val context: Context, private val api: Api, private val installer: ApkInstaller) {

    val installedVersionCode: Int get() = BuildConfig.VERSION_CODE
    val installedVersionName: String get() = BuildConfig.VERSION_NAME

    /** Новая версия на сервере или null — обновляться не нужно (или сервер недоступен). */
    suspend fun check(): ClientReleaseDto? =
        runCatching { api.appVersion() }.getOrNull()?.takeIf { UpdatePolicy.isNewer(it, installedVersionCode) }

    /** @param onProgress доля скачанного, 0..1 */
    suspend fun install(version: ClientReleaseDto, onProgress: (Float) -> Unit = {}) {
        val dir = File(context.cacheDir, "update").apply { mkdirs() }
        // Сборки других версий больше не нужны.
        dir.listFiles()?.filter { it.name != fileName(version) }?.forEach { it.delete() }
        val apk = File(dir, fileName(version))
        val report = { bytes: Long -> onProgress(if (version.size > 0) (bytes.toFloat() / version.size).coerceIn(0f, 1f) else 0f) }
        if (!apk.exists() || AppInventory.sha256(apk) != version.sha256) {
            api.downloadAppUpdate(version.versionCode, apk, report)
            if (AppInventory.sha256(apk) != version.sha256) {
                // Недокачанный в прошлый раз файл мог оказаться испорченным — качаем заново целиком.
                apk.delete()
                api.downloadAppUpdate(version.versionCode, apk, report)
            }
            if (AppInventory.sha256(apk) != version.sha256) {
                apk.delete()
                throw IOException("Сборка повреждена при скачивании")
            }
        }
        installer.installUpdate(apk)
    }

    private fun fileName(version: ClientReleaseDto) = "contactsync-${version.versionCode}.apk"
}
