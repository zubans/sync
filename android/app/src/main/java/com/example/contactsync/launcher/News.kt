package com.example.contactsync.launcher

import com.example.contactsync.data.NewsItemDto
import com.example.contactsync.data.NewsSourceDto
import java.time.Instant
import java.time.OffsetDateTime
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/**
 * Издание во вкладках виджета. Новости собирает сервер sync (`app:news:collect`), список изданий
 * тоже приходит с сервера — новое издание добавляется в админке без новой сборки приложения.
 */
data class NewsSource(
    /** Код в API: rg, rbc, cnn… */
    val code: String,
    val title: String,
    /** Сервер не может собрать издание дольше 30 минут — во вкладке горит красная точка. */
    val stale: Boolean = false,
)

data class NewsItem(
    val id: Long,
    /** Код издания. */
    val source: String,
    val title: String,
    val link: String,
    /** Анонс; пусто — у издания его нет (CNN). */
    val summary: String,
    /** Время публикации, а если издание его не даёт — когда сервер впервые увидел новость. */
    val time: Instant,
)

/** Новости одной вкладки и курсор следующей страницы; null — дальше новостей нет. */
data class NewsPage(val items: List<NewsItem>, val nextCursor: String?)

object NewsFeed {

    fun item(dto: NewsItemDto) = NewsItem(
        id = dto.id,
        source = dto.source,
        title = dto.title,
        link = dto.link,
        summary = dto.summary.orEmpty(),
        // OffsetDateTime, а не Instant.parse: старые Android не разбирают «+00:00» в Instant.
        time = OffsetDateTime.parse(dto.time).toInstant(),
    )

    fun source(dto: NewsSourceDto) = NewsSource(dto.code, dto.title, dto.stale)

    /** Подпись под заголовком: «РБК · 23:08», за прошлые дни — «NYT · 9 окт., 21:00». */
    fun caption(item: NewsItem, sources: List<NewsSource>, now: Instant, zone: ZoneId): String {
        val title = sources.firstOrNull { it.code == item.source }?.title ?: item.source
        val local = item.time.atZone(zone)
        val time = if (local.toLocalDate() == now.atZone(zone).toLocalDate()) local.format(TIME) else local.format(DAY_TIME)
        return "$title · $time"
    }

    /** Следующая страница дописывается к уже показанным; новость с тем же id не повторяется. */
    fun append(page: NewsPage, next: NewsPage): NewsPage {
        val seen = page.items.mapTo(HashSet()) { it.id }
        return NewsPage(page.items + next.items.filter { it.id !in seen }, next.nextCursor)
    }

    private val TIME = DateTimeFormatter.ofPattern("HH:mm")
    private val DAY_TIME = DateTimeFormatter.ofPattern("d MMM, HH:mm", RU)
}
