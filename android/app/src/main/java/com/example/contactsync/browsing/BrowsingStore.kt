package com.example.contactsync.browsing

import android.content.Context
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File

/** Одно посещение страницы: адрес из строки браузера, заголовок окна, пакет браузера и время. */
@Serializable
data class Visit(
    val url: String,
    val title: String? = null,
    val browser: String? = null,
    val visitedAt: Long,
)

/**
 * Очередь посещений браузера до выгрузки на сервер. Служба специальных возможностей ([NavBarService])
 * дописывает сюда адреса, а почасовой воркер их отправляет и удаляет отправленное.
 *
 * Файл в каталоге приложения, привязан к вошедшему пользователю и очищается при выходе.
 * Старую историю браузеров прочитать нельзя (она в их закрытых базах), поэтому пишется только
 * то, что открыто после включения службы.
 */
class BrowsingStore(context: Context) {

    private val file = File(context.filesDir, FILE_NAME)
    private val json = Json { ignoreUnknownKeys = true }
    private val lock = Any()

    /** Добавляет посещение. Повтор того же адреса подряд не пишется — браузер шлёт событие не раз. */
    fun record(url: String, title: String?, browser: String?) {
        synchronized(lock) {
            val visits = load().toMutableList()
            if (visits.lastOrNull()?.url == url) return
            visits.add(Visit(url, title, browser, System.currentTimeMillis()))
            // Храним ограниченное число самых свежих: при долгом отсутствии сети очередь не растёт без предела.
            save(if (visits.size > MAX_VISITS) visits.subList(visits.size - MAX_VISITS, visits.size) else visits)
        }
    }

    fun snapshot(): List<Visit> = synchronized(lock) { load() }

    /** Убирает уже выгруженные посещения; те, что добавились во время выгрузки, остаются. */
    fun remove(uploaded: List<Visit>) {
        synchronized(lock) {
            val sent = uploaded.toHashSet()
            save(load().filterNot { it in sent })
        }
    }

    fun clear() {
        synchronized(lock) { file.delete() }
    }

    private fun load(): List<Visit> =
        runCatching { json.decodeFromString<List<Visit>>(file.readText()) }.getOrDefault(emptyList())

    private fun save(visits: List<Visit>) {
        val tmp = File(file.parentFile, file.name + ".tmp")
        tmp.writeText(json.encodeToString(visits))
        tmp.renameTo(file)
    }

    private companion object {
        const val FILE_NAME = "browsing.json"
        const val MAX_VISITS = 10_000
    }
}
