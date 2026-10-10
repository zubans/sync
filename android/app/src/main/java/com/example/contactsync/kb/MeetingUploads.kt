package com.example.contactsync.kb

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.workDataOf
import com.example.contactsync.App
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File
import java.io.IOException
import java.util.concurrent.TimeUnit

/** Записанная встреча, ждущая отправки в KB. Лежит рядом с аудио (`<имя>.json`) и переживает перезапуск. */
@Serializable
data class PendingMeeting(
    val projectSlug: String,
    val title: String,
    /** YYYY-MM-DD */
    val date: String,
    /** Встреча уже создана в KB — при повторной попытке не создавать вторую. */
    val meetingId: Int? = null,
)

/**
 * Очередь отправки записей. Файл удаляется с планшета только после того, как KB приняла аудио.
 * Если сессия кончилась, запись ждёт: после входа [resumeAll] ставит её снова.
 */
object MeetingUploads {
    const val TAG = "kb-meeting-upload"
    private val json = Json { ignoreUnknownKeys = true }

    fun dir(context: Context): File = File(context.filesDir, "kb-meetings").apply { mkdirs() }

    fun save(audio: File, meta: PendingMeeting) = meta(audio).writeText(json.encodeToString(meta))

    fun load(audio: File): PendingMeeting? =
        meta(audio).takeIf { it.exists() }?.let { runCatching { json.decodeFromString<PendingMeeting>(it.readText()) }.getOrNull() }

    fun remove(audio: File) {
        audio.delete()
        meta(audio).delete()
    }

    /** Записи, которые ещё не отправлены. */
    fun pending(context: Context): List<File> =
        dir(context).listFiles { f -> f.extension == "m4a" && meta(f).exists() }.orEmpty().sortedBy { it.name }

    fun enqueue(context: Context, audio: File) {
        val request = OneTimeWorkRequestBuilder<MeetingUploadWorker>()
            .setInputData(workDataOf(KEY_FILE to audio.absolutePath))
            .setConstraints(Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 1, TimeUnit.MINUTES)
            .addTag(TAG)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork("$TAG-${audio.name}", ExistingWorkPolicy.KEEP, request)
    }

    fun resumeAll(context: Context) = pending(context).forEach { enqueue(context, it) }

    private fun meta(audio: File) = File(audio.parentFile, audio.nameWithoutExtension + ".json")

    internal const val KEY_FILE = "file"
}

class MeetingUploadWorker(context: Context, params: WorkerParameters) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val app = applicationContext as App
        val audio = File(inputData.getString(MeetingUploads.KEY_FILE) ?: return Result.success())
        var meta = MeetingUploads.load(audio) ?: return Result.success()
        if (!audio.exists()) {
            MeetingUploads.remove(audio)
            return Result.success()
        }
        // Без входа отправлять некуда; запись остаётся и уйдёт после входа (resumeAll).
        if (!app.kbSession.isLoggedIn) return Result.success()

        return try {
            val meetingId = meta.meetingId ?: app.kbApi.createMeeting(meta.projectSlug, KbNewMeeting(meta.title, meta.date)).id.also {
                meta = meta.copy(meetingId = it)
                MeetingUploads.save(audio, meta)
            }
            val modelId = app.kbApi.defaultTranscriptionModel(meta.projectSlug, meetingId)
            // Подписанная ссылка и временный файл в хранилище живут недолго — на каждой попытке загружаем заново.
            val key = app.kbApi.uploadAudio(audio)
            app.kbApi.attachAudio(meetingId, key, modelId)
            app.kbSession.rememberMeeting(meetingId)
            MeetingUploads.remove(audio)
            Result.success()
        } catch (e: KbUnauthorizedException) {
            Result.success()
        } catch (e: IOException) {
            if (runAttemptCount < MAX_ATTEMPTS) Result.retry() else Result.failure()
        }
    }

    private companion object {
        /** С экспоненциальной паузой от минуты это больше суток попыток; файл при этом не удаляется. */
        const val MAX_ATTEMPTS = 12
    }
}
