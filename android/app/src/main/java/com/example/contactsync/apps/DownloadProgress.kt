package com.example.contactsync.apps

/**
 * Скачивание APK приложения: все его части (split APK) считаются одним файлом.
 * Части, уже лежащие в кэше целыми, сразу засчитываются скачанными.
 */
data class DownloadProgress(
    /** Сколько байт уже на диске по всем частям. */
    val downloaded: Long,
    /** Суммарный размер частей; 0 — сервер размер не сообщил. */
    val total: Long,
) {
    /** Доля от 0 до 1; null — размер неизвестен, показывать бесконечный индикатор. */
    val fraction: Float?
        get() = if (total > 0) (downloaded.toFloat() / total).coerceIn(0f, 1f) else null

    /** Целые проценты — по ним решаем, стоит ли перерисовывать экран. */
    val percent: Int
        get() = fraction?.let { (it * 100).toInt() } ?: -1
}
