package com.example.contactsync.apps

import android.content.Context
import android.os.Build
import com.example.contactsync.data.Api
import com.example.contactsync.data.DeviceDto
import com.example.contactsync.data.InventoryRequest
import com.example.contactsync.data.Session
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.withContext
import java.io.File
import java.io.RandomAccessFile

data class ApkBackupReport(val apps: Int, val uploaded: Int, val uploadedBytes: Long, val remaining: Int)

/**
 * Резервное копирование приложений: отправить список, затем загрузить APK, которых нет на сервере.
 * Загрузка идёт частями и продолжается с места обрыва — в том числе после перезапуска воркера.
 */
class ApkBackup(
    private val context: Context,
    private val session: Session,
    private val api: Api,
) {
    private val inventory = AppInventory(context)

    suspend fun run(onProgress: suspend (String) -> Unit = {}): ApkBackupReport = withContext(Dispatchers.IO) {
        onProgress("Составляю список приложений…")
        val apps = inventory.collect()
        val missing = api.inventory(
            InventoryRequest(
                device = DeviceDto(session.installId, "${Build.MANUFACTURER} ${Build.MODEL}"),
                apps = apps.map { it.dto },
            ),
        ).missing

        val files = apps.flatMap { it.paths.entries }.associate { it.key to it.value }
        var uploaded = 0
        var bytes = 0L
        for ((index, sha256) in missing.withIndex()) {
            currentCoroutineContext().ensureActive()
            val file = files[sha256] ?: continue
            val app = apps.first { sha256 in it.paths }.dto
            onProgress("Загружаю ${app.label ?: app.packageName} (${index + 1} из ${missing.size})")
            bytes += upload(sha256, file)
            uploaded++
        }
        // Файлы, которые сервер ждёт, но прочитать их нельзя, повторять бессмысленно — в остаток не считаем.
        ApkBackupReport(apps.size, uploaded, bytes, missing.count { it in files } - uploaded)
    }

    /** Загружает файл с места, до которого сервер уже дошёл. Возвращает число отправленных байт. */
    private suspend fun upload(sha256: String, file: File): Long {
        var state = api.uploadState(sha256)
        var sent = 0L
        val buffer = ByteArray(CHUNK_SIZE)
        RandomAccessFile(file, "r").use { raf ->
            while (!state.complete && state.offset < raf.length()) {
                currentCoroutineContext().ensureActive()
                raf.seek(state.offset)
                val read = raf.read(buffer)
                if (read <= 0) break
                val next = api.uploadChunk(sha256, state.offset, buffer, read)
                if (next.offset > state.offset) sent += next.offset - state.offset
                state = next
            }
        }
        if (!state.complete) api.completeUpload(sha256)
        return sent
    }

    private companion object {
        const val CHUNK_SIZE = 4 * 1024 * 1024
    }
}
