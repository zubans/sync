package com.example.contactsync.kb

import android.annotation.SuppressLint
import android.media.AudioAttributes
import android.media.AudioFormat
import android.media.AudioPlaybackCaptureConfiguration
import android.media.AudioRecord
import android.media.MediaCodec
import android.media.MediaCodecInfo
import android.media.MediaFormat
import android.media.MediaMuxer
import android.media.MediaRecorder
import android.media.projection.MediaProjection
import android.os.Build
import androidx.annotation.RequiresApi
import java.io.File
import java.nio.ByteOrder

/**
 * Звук для записи: основной поток — микрофон (у телефонного звонка — линия звонка), к нему подмешивается
 * звук других приложений — голоса собеседников в созвоне.
 *
 * Пока созвон или звонок держит другое приложение, Android отдаёт остальным тишину вместо микрофона,
 * а голоса собеседников (`USAGE_VOICE_COMMUNICATION`) не отдаёт вовсе. Обходит это только право
 * `CAPTURE_AUDIO_OUTPUT` — оно есть у Contact Sync, встроенного в прошивку (priv-app).
 */
class AudioCapture private constructor(private val main: AudioRecord, private val others: AudioRecord?) {

    private val othersBuffer = ShortArray(CHUNK)

    /** Голоса собеседников подмешиваются. */
    val withOthers: Boolean get() = others != null

    fun start() {
        main.startRecording()
        others?.startRecording()
    }

    /** Блокирует до следующей порции (~100 мс). @return число отсчётов в [out]; меньше нуля — источник отвалился. */
    fun read(out: ShortArray): Int {
        val n = main.read(out, 0, out.size)
        if (n <= 0 || others == null) return n
        // Звук приложений приходит своим темпом; берём сколько есть, не дожидаясь, чтобы не рвать микрофон.
        val m = others.read(othersBuffer, 0, n, AudioRecord.READ_NON_BLOCKING)
        if (m > 0) AudioMix.mixInto(out, n, othersBuffer, m)
        return n
    }

    fun release() {
        listOfNotNull(main, others).forEach {
            runCatching { it.stop() }
            it.release()
        }
    }

    companion object {
        const val SAMPLE_RATE = 48_000
        /** 100 мс моно. */
        const val CHUNK = SAMPLE_RATE / 10

        private val FORMAT = AudioFormat.Builder()
            .setEncoding(AudioFormat.ENCODING_PCM_16BIT)
            .setSampleRate(SAMPLE_RATE)
            .setChannelMask(AudioFormat.CHANNEL_IN_MONO)
            .build()

        /** @return null — микрофон недоступен (нет разрешения или занят). */
        fun open(source: RecordingSource, projection: MediaProjection?): AudioCapture? {
            val main = (if (source == RecordingSource.PHONE) record(MediaRecorder.AudioSource.VOICE_CALL) else null)
                ?: record(MediaRecorder.AudioSource.MIC)
                ?: return null
            val others = if (projection != null && Build.VERSION.SDK_INT >= 29) playback(projection) else null
            return AudioCapture(main, others)
        }

        @SuppressLint("MissingPermission")
        private fun record(source: Int): AudioRecord? = try {
            AudioRecord.Builder()
                .setAudioSource(source)
                .setAudioFormat(FORMAT)
                .setBufferSizeInBytes(bufferBytes())
                .build()
                .takeIf { it.state == AudioRecord.STATE_INITIALIZED || run { it.release(); false } }
        } catch (e: Exception) {
            // VOICE_CALL без права или без поддержки в аудио-HAL — падаем на микрофон.
            null
        }

        /** Голоса в созвоне играют как `VOICE_COMMUNICATION`; без права на них берём хотя бы медиа. */
        @SuppressLint("MissingPermission")
        @RequiresApi(29)
        private fun playback(projection: MediaProjection): AudioRecord? {
            for (voice in listOf(true, false)) {
                try {
                    val config = AudioPlaybackCaptureConfiguration.Builder(projection)
                        .addMatchingUsage(AudioAttributes.USAGE_MEDIA)
                        .addMatchingUsage(AudioAttributes.USAGE_GAME)
                        .addMatchingUsage(AudioAttributes.USAGE_UNKNOWN)
                        .apply { if (voice) addMatchingUsage(AudioAttributes.USAGE_VOICE_COMMUNICATION) }
                        .build()
                    val record = AudioRecord.Builder()
                        .setAudioFormat(FORMAT)
                        .setBufferSizeInBytes(bufferBytes())
                        .setAudioPlaybackCaptureConfig(config)
                        .build()
                    if (record.state == AudioRecord.STATE_INITIALIZED) return record
                    record.release()
                } catch (e: Exception) {
                    // Следующая попытка — без голосов созвона.
                }
            }
            return null
        }

        private fun bufferBytes(): Int =
            maxOf(AudioRecord.getMinBufferSize(SAMPLE_RATE, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT), CHUNK * 2 * 4)
    }
}

/** Кодирует PCM (16 бит, моно) в AAC и пишет m4a: один экземпляр — одна часть записи. */
class AacWriter(file: File, private val sampleRate: Int) {
    private val codec = MediaCodec.createEncoderByType(MIME)
    private val muxer = MediaMuxer(file.absolutePath, MediaMuxer.OutputFormat.MUXER_OUTPUT_MPEG_4)
    private val info = MediaCodec.BufferInfo()
    private var track = -1
    private var samples = 0
    private var frames = 0L

    init {
        val format = MediaFormat.createAudioFormat(MIME, sampleRate, 1).apply {
            setInteger(MediaFormat.KEY_AAC_PROFILE, MediaCodecInfo.CodecProfileLevel.AACObjectLC)
            setInteger(MediaFormat.KEY_BIT_RATE, BIT_RATE)
            setInteger(MediaFormat.KEY_MAX_INPUT_SIZE, 16 * 1024)
        }
        codec.configure(format, null, null, MediaCodec.CONFIGURE_FLAG_ENCODE)
        codec.start()
    }

    val durationMs: Long get() = frames * 1000 / sampleRate

    fun write(pcm: ShortArray, count: Int) {
        var offset = 0
        while (offset < count) {
            val index = codec.dequeueInputBuffer(TIMEOUT_US)
            if (index < 0) {
                drain(endOfStream = false)
                continue
            }
            val buffer = codec.getInputBuffer(index) ?: continue
            buffer.clear()
            val n = minOf(count - offset, buffer.remaining() / 2)
            buffer.order(ByteOrder.nativeOrder()).asShortBuffer().put(pcm, offset, n)
            codec.queueInputBuffer(index, 0, n * 2, presentationUs(), 0)
            frames += n
            offset += n
            drain(endOfStream = false)
        }
    }

    /** @return в файле есть звук; иначе файл негоден и его надо удалить. */
    fun finish(): Boolean {
        try {
            val index = codec.dequeueInputBuffer(TIMEOUT_US * 10)
            if (index >= 0) codec.queueInputBuffer(index, 0, 0, presentationUs(), MediaCodec.BUFFER_FLAG_END_OF_STREAM)
            drain(endOfStream = true)
        } catch (e: IllegalStateException) {
            // Кодек уже сломан — сохраняем то, что успели записать.
        }
        runCatching { codec.stop() }
        codec.release()
        return try {
            if (track >= 0 && samples > 0) {
                muxer.stop()
                true
            } else {
                false
            }
        } catch (e: IllegalStateException) {
            false
        } finally {
            muxer.release()
        }
    }

    private fun presentationUs() = frames * 1_000_000 / sampleRate

    private fun drain(endOfStream: Boolean) {
        var idle = 0
        while (true) {
            val index = codec.dequeueOutputBuffer(info, if (endOfStream) TIMEOUT_US else 0)
            when {
                index == MediaCodec.INFO_TRY_AGAIN_LATER -> if (!endOfStream || ++idle > MAX_IDLE) return
                index == MediaCodec.INFO_OUTPUT_FORMAT_CHANGED -> {
                    track = muxer.addTrack(codec.outputFormat)
                    muxer.start()
                }
                index >= 0 -> {
                    val buffer = codec.getOutputBuffer(index)
                    if (info.flags and MediaCodec.BUFFER_FLAG_CODEC_CONFIG != 0) info.size = 0
                    if (buffer != null && info.size > 0 && track >= 0) {
                        buffer.position(info.offset)
                        buffer.limit(info.offset + info.size)
                        muxer.writeSampleData(track, buffer, info)
                        samples++
                    }
                    codec.releaseOutputBuffer(index, false)
                    if (info.flags and MediaCodec.BUFFER_FLAG_END_OF_STREAM != 0) return
                }
            }
        }
    }

    private companion object {
        const val MIME = MediaFormat.MIMETYPE_AUDIO_AAC
        /** Как у прежней записи через MediaRecorder: речь, моно. */
        const val BIT_RATE = 48_000
        const val TIMEOUT_US = 10_000L
        /** Ожидание хвоста кодека при закрытии — до секунды. */
        const val MAX_IDLE = 100
    }
}
