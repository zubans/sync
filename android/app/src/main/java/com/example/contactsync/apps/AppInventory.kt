package com.example.contactsync.apps

import android.content.Context
import android.content.pm.ApplicationInfo
import android.content.pm.PackageInfo
import android.content.pm.PackageManager
import android.os.Build
import com.example.contactsync.data.ApkFileDto
import com.example.contactsync.data.AppDto
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File
import java.security.MessageDigest

/** Установленное приложение вместе с путями к его APK. */
data class LocalApp(val dto: AppDto, val paths: Map<String, File>)

/**
 * Список пользовательских приложений устройства: версия, источник установки,
 * подпись и файлы base/split APK с SHA-256. Системные не входят — ни встроенные в прошивку,
 * ни служебные компоненты без иконки в лаунчере (например, Android System SafetyCore из Play).
 */
class AppInventory(private val context: Context) {

    private val pm = context.packageManager
    private val hashCache = HashCache(File(context.filesDir, "apk-hashes.json"))

    fun collect(): List<LocalApp> {
        val result = pm.getInstalledApplications(0)
            .filter { isUserApp(it) }
            .mapNotNull { info -> runCatching { describe(info) }.getOrNull() }
            .sortedBy { it.dto.label?.lowercase() ?: it.dto.packageName }
        hashCache.retainOnly(result.flatMap { it.paths.values })
        return result
    }

    private fun isUserApp(info: ApplicationInfo): Boolean =
        info.flags and ApplicationInfo.FLAG_SYSTEM == 0 &&
            info.packageName != context.packageName &&
            pm.getLaunchIntentForPackage(info.packageName) != null

    /** Установлено ли приложение и какой версии. */
    fun installedVersion(packageName: String): Long? = runCatching {
        pm.getPackageInfo(packageName, 0).longVersionCodeCompat()
    }.getOrNull()

    private fun describe(info: ApplicationInfo): LocalApp {
        val pkg = packageInfo(info.packageName)
        val files = buildList {
            add(File(info.sourceDir))
            info.splitSourceDirs?.forEach { add(File(it)) }
        }.filter { it.canRead() }

        val dtoFiles = files.map { ApkFileDto(it.name, hashCache.sha256(it), it.length()) }
        return LocalApp(
            dto = AppDto(
                packageName = info.packageName,
                label = pm.getApplicationLabel(info).toString(),
                versionName = pkg.versionName,
                versionCode = pkg.longVersionCodeCompat(),
                installer = installerOf(info.packageName),
                signingSha256 = signingSha256(pkg),
                files = dtoFiles,
            ),
            paths = files.associateBy { hashCache.sha256(it) },
        )
    }

    private fun packageInfo(packageName: String): PackageInfo =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
            pm.getPackageInfo(packageName, PackageManager.GET_SIGNING_CERTIFICATES)
        } else {
            @Suppress("DEPRECATION")
            pm.getPackageInfo(packageName, PackageManager.GET_SIGNATURES)
        }

    private fun installerOf(packageName: String): String? = runCatching {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            pm.getInstallSourceInfo(packageName).installingPackageName
        } else {
            @Suppress("DEPRECATION")
            pm.getInstallerPackageName(packageName)
        }
    }.getOrNull()

    companion object {
        /** SHA-256 сертификата, которым подписано содержимое APK. Одинаково для установленного пакета и файла. */
        fun signingSha256(pkg: PackageInfo): String? {
            val cert = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
                pkg.signingInfo?.apkContentsSigners?.firstOrNull()
            } else {
                @Suppress("DEPRECATION")
                pkg.signatures?.firstOrNull()
            } ?: return null
            return MessageDigest.getInstance("SHA-256").digest(cert.toByteArray()).toHex()
        }

        fun sha256(file: File): String {
            val digest = MessageDigest.getInstance("SHA-256")
            file.inputStream().use { input ->
                val buffer = ByteArray(1 shl 16)
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    digest.update(buffer, 0, read)
                }
            }
            return digest.digest().toHex()
        }

        private fun ByteArray.toHex() = joinToString("") { "%02x".format(it) }
    }
}

fun PackageInfo.longVersionCodeCompat(): Long =
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) longVersionCode else @Suppress("DEPRECATION") versionCode.toLong()

/**
 * Кэш хэшей по (путь, размер, время изменения): APK на сотни мегабайт не пересчитываются при каждой синхронизации.
 */
private class HashCache(private val file: File) {

    @Serializable
    data class Entry(val size: Long, val modified: Long, val sha256: String)

    private val json = Json { ignoreUnknownKeys = true }
    private val entries: MutableMap<String, Entry> =
        runCatching { json.decodeFromString<Map<String, Entry>>(file.readText()) }.getOrDefault(emptyMap()).toMutableMap()
    private var dirty = false

    @Synchronized
    fun sha256(apk: File): String {
        val cached = entries[apk.path]
        if (cached != null && cached.size == apk.length() && cached.modified == apk.lastModified()) return cached.sha256
        return AppInventory.sha256(apk).also {
            entries[apk.path] = Entry(apk.length(), apk.lastModified(), it)
            dirty = true
        }
    }

    @Synchronized
    fun retainOnly(files: List<File>) {
        val paths = files.map { it.path }.toSet()
        if (entries.keys.retainAll(paths)) dirty = true
        if (dirty) {
            file.writeText(json.encodeToString(entries.toMap()))
            dirty = false
        }
    }
}
