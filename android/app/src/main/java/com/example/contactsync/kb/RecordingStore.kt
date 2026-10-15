package com.example.contactsync.kb

import android.content.Context
import android.media.MediaExtractor
import android.os.Environment
import com.example.contactsync.files.hasStorageAccess
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File
import java.time.Instant
import java.time.LocalDateTime
import java.time.ZoneId

/** Что записывали — от этого зависят источник звука и название по умолчанию. */
@Serializable
enum class RecordingSource(val label: String) {
    /** Живая встреча: только микрофон. */
    MIC("Встреча"),
    /** Созвон в другом приложении (Телемост и т.п.): микрофон + голоса собеседников. */
    CALL("Созвон"),
    /** Телефонный звонок: линия звонка, если её отдаёт прошивка, иначе микрофон. */
    PHONE("Звонок"),
}

/**
 * Запись на планшете. Пока владелец не решил, что с ней делать, она «не разобрана»;
 * «сохранить без проекта» — `kept`; отправленная в проект уходит в очередь [MeetingUploads] и отсюда удаляется.
 */
@Serializable
data class Recording(
    val id: String,
    val source: RecordingSource,
    /** Начало, мс с эпохи. */
    val startedAt: Long,
    val title: String,
    /** recording | unsorted | kept */
    val status: String = STATUS_RECORDING,
    val durationMs: Long = 0,
) {
    companion object {
        const val STATUS_RECORDING = "recording"
        const val STATUS_UNSORTED = "unsorted"
        const val STATUS_KEPT = "kept"
    }
}

/**
 * Записи лежат в общей памяти `Records/<id>/`, чтобы их было видно в файловом менеджере и можно было скопировать:
 * части `part-N.m4a` (длина части — лимит KB) и `meta.json`. Без «Доступа ко всем файлам» — во внутренней
 * `files/recordings/<id>/`; когда доступ появится, они переедут в `Records` при следующем запуске.
 * Файлы переживают перезапуск; удаляются только по выбору владельца или после приёма в KB.
 */
object RecordingStore {
    private val json = Json { ignoreUnknownKeys = true; encodeDefaults = true }
    private val PART = Regex("""part-(\d+)\.m4a""")

    fun create(context: Context, source: RecordingSource): Recording {
        val startedAt = System.currentTimeMillis()
        val recording = Recording(
            id = "rec-$startedAt",
            source = source,
            startedAt = startedAt,
            title = MeetingPlan.title(source, LocalDateTime.ofInstant(Instant.ofEpochMilli(startedAt), ZoneId.systemDefault())),
        )
        save(context, recording)
        return recording
    }

    fun save(context: Context, recording: Recording) =
        meta(context, recording.id).also { it.parentFile?.mkdirs() }.writeText(json.encodeToString(recording))

    fun load(context: Context, id: String): Recording? =
        meta(context, id).takeIf { it.exists() }?.let { runCatching { json.decodeFromString<Recording>(it.readText()) }.getOrNull() }

    /** Записи, ждущие решения или сохранённые без проекта, — новые сверху. */
    fun list(context: Context): List<Recording> =
        root(context).listFiles().orEmpty().mapNotNull { load(context, it.name) }
            .filter { it.status != Recording.STATUS_RECORDING }
            .sortedByDescending { it.startedAt }

    fun partFile(context: Context, id: String, part: Int) = File(dir(context, id), "part-$part.m4a")

    fun parts(context: Context, id: String): List<File> =
        dir(context, id).listFiles().orEmpty()
            .mapNotNull { f -> PART.matchEntire(f.name)?.let { it.groupValues[1].toInt() to f } }
            .sortedBy { it.first }
            .map { it.second }

    fun keep(context: Context, id: String, title: String) {
        val recording = load(context, id) ?: return
        save(context, recording.copy(status = Recording.STATUS_KEPT, title = title.ifBlank { recording.title }))
    }

    /** Отменить: запись удаляется безвозвратно. */
    fun delete(context: Context, id: String) {
        dir(context, id).deleteRecursively()
    }

    /** Каждая часть становится встречей проекта в KB и уходит в очередь отправки. */
    fun sendToProject(context: Context, id: String, projectSlug: String, title: String) {
        val recording = load(context, id) ?: return
        val name = title.ifBlank { recording.title }
        val date = Instant.ofEpochMilli(recording.startedAt).atZone(ZoneId.systemDefault()).toLocalDate().toString()
        parts(context, id).forEachIndexed { index, part ->
            val target = File(MeetingUploads.dir(context), "meeting-$id-${index + 1}.m4a")
            MeetingUploads.save(target, PendingMeeting(projectSlug, MeetingPlan.partTitle(name, index + 1), date))
            if (!part.renameTo(target)) {
                part.copyTo(target, overwrite = true)
                part.delete()
            }
            MeetingUploads.enqueue(context, target)
        }
        delete(context, id)
    }

    /**
     * Процесс умер посреди записи: незакрытая часть не читается (у m4a нет индекса) — её убираем,
     * остальное становится неразобранной записью.
     */
    fun recoverInterrupted(context: Context) {
        moveToPublic(context)
        root(context).listFiles().orEmpty().mapNotNull { load(context, it.name) }
            .filter { it.status == Recording.STATUS_RECORDING }
            .forEach { recording ->
                parts(context, recording.id).filterNot(::readable).forEach(File::delete)
                if (parts(context, recording.id).isEmpty()) {
                    delete(context, recording.id)
                } else {
                    save(context, recording.copy(status = Recording.STATUS_UNSORTED))
                }
            }
    }

    private fun readable(file: File): Boolean {
        val extractor = MediaExtractor()
        return try {
            extractor.setDataSource(file.absolutePath)
            extractor.trackCount > 0
        } catch (e: Exception) {
            false
        } finally {
            extractor.release()
        }
    }

    /** Записи, сделанные без доступа к общей памяти, переносим в `Records`, как только доступ есть. */
    private fun moveToPublic(context: Context) {
        if (!hasStorageAccess(context)) return
        val target = publicRoot().apply { mkdirs() }
        internalRoot(context).listFiles().orEmpty().forEach { dir ->
            val moved = File(target, dir.name)
            if (!dir.renameTo(moved) && dir.copyRecursively(moved, overwrite = true)) dir.deleteRecursively()
        }
    }

    private fun root(context: Context) =
        (if (hasStorageAccess(context)) publicRoot() else internalRoot(context)).apply { mkdirs() }

    private fun publicRoot() = File(Environment.getExternalStorageDirectory(), "Records")

    private fun internalRoot(context: Context) = File(context.filesDir, "recordings")

    private fun dir(context: Context, id: String) = File(root(context), id)

    private fun meta(context: Context, id: String) = File(dir(context, id), "meta.json")
}
