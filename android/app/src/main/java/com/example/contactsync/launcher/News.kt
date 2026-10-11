package com.example.contactsync.launcher

import org.w3c.dom.Element
import java.io.ByteArrayInputStream
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.ZonedDateTime
import java.time.format.DateTimeFormatter
import java.time.temporal.ChronoUnit
import javax.xml.XMLConstants
import javax.xml.parsers.DocumentBuilderFactory

/** Издание в виджете новостей. */
enum class NewsSource(val title: String, val url: String, val format: NewsFormat) {
    RG("РГ", "https://rg.ru/xml/index.xml", NewsFormat.RSS),
    RBC("РБК", "https://rssexport.rbc.ru/rbcnews/news/30/full.rss", NewsFormat.RSS),
    // RSS CNN (rss.cnn.com) не обновляется с 2023 года — берём текстовую версию сайта.
    CNN("CNN", "https://lite.cnn.com/", NewsFormat.CNN_LITE),
    NYPOST("NY Post", "https://nypost.com/feed/", NewsFormat.RSS),
    NYT("NYT", "https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml", NewsFormat.RSS),
}

enum class NewsFormat { RSS, CNN_LITE }

data class NewsItem(
    val source: NewsSource,
    val title: String,
    val link: String,
    /** Время публикации; null — источник его не даёт (CNN), известна только дата [date]. */
    val published: Instant?,
    val date: LocalDate?,
    /** Место в выдаче источника: у CNN порядок задаёт редакция, по нему и сортируем. */
    val rank: Int,
    /** Анонс из ленты без HTML — для подробного экрана; пусто — у источника его нет (CNN). */
    val summary: String = "",
)

/**
 * Разбор лент. RSS 2.0 читаем стандартным DOM-парсером (есть и на Android, и в JVM-тестах),
 * текстовую страницу CNN — регулярным выражением: разметка там простая, список ссылок `card--lite`.
 */
object NewsParser {

    fun parse(source: NewsSource, body: String, limit: Int = LIMIT): List<NewsItem> = when (source.format) {
        NewsFormat.RSS -> rss(source, body, limit)
        NewsFormat.CNN_LITE -> cnnLite(source, body, limit)
    }

    fun rss(source: NewsSource, xml: String, limit: Int = LIMIT): List<NewsItem> {
        val factory = DocumentBuilderFactory.newInstance()
        // Внешние сущности и DTD ленте не нужны; на Android часть флагов не поддерживается — не страшно.
        runCatching { factory.setFeature(XMLConstants.FEATURE_SECURE_PROCESSING, true) }
        runCatching { factory.setFeature("http://apache.org/xml/features/disallow-doctype-decl", true) }
        val doc = factory.newDocumentBuilder().parse(ByteArrayInputStream(xml.toByteArray(Charsets.UTF_8)))
        val items = doc.getElementsByTagName("item")
        return (0 until items.length).asSequence()
            .map { items.item(it) as Element }
            .mapIndexedNotNull { index, item ->
                val title = item.child("title")?.let(::clean)?.takeIf { it.isNotEmpty() } ?: return@mapIndexedNotNull null
                val link = item.child("link")?.trim()?.takeIf { it.isNotEmpty() } ?: return@mapIndexedNotNull null
                val published = item.child("pubDate")?.let(::rfc1123)
                val summary = item.child("description")?.replace(TAG, " ")?.let(::clean).orEmpty().take(SUMMARY_LIMIT)
                NewsItem(source, title, link, published?.toInstant(), published?.toLocalDate(), index, summary)
            }
            .take(limit)
            .toList()
    }

    /** Заголовки lite.cnn.com: ссылка вида /2026/10/10/us/…, время публикации страница не показывает. */
    fun cnnLite(source: NewsSource, html: String, limit: Int = LIMIT): List<NewsItem> =
        CNN_CARD.findAll(html)
            .mapIndexed { index, match ->
                val path = match.groupValues[1]
                val date = CNN_DATE.find(path)?.destructured?.let { (y, m, d) -> LocalDate.of(y.toInt(), m.toInt(), d.toInt()) }
                NewsItem(source, clean(match.groupValues[2]), "https://edition.cnn.com$path", null, date, index)
            }
            .filter { it.title.isNotEmpty() }
            .distinctBy { it.link }
            .take(limit)
            .toList()

    private fun Element.child(tag: String): String? =
        getElementsByTagName(tag).takeIf { it.length > 0 }?.item(0)?.textContent

    private fun rfc1123(text: String): ZonedDateTime? =
        runCatching { ZonedDateTime.parse(text.trim(), DateTimeFormatter.RFC_1123_DATE_TIME) }.getOrNull()

    /** Пробелы в одну строку и HTML-сущности, которые встречаются в заголовках. */
    private fun clean(text: String): String =
        ENTITY.replace(text.replace(WHITESPACE, " ").trim()) { m ->
            val code = m.groupValues[1]
            when {
                code.startsWith("#x", ignoreCase = true) -> code.drop(2).toIntOrNull(16)?.let { String(Character.toChars(it)) }
                code.startsWith("#") -> code.drop(1).toIntOrNull()?.let { String(Character.toChars(it)) }
                else -> NAMED_ENTITIES[code]
            } ?: m.value
        }

    private const val LIMIT = 30
    private const val SUMMARY_LIMIT = 400
    private val TAG = Regex("""<[^>]*>""")
    private val CNN_CARD = Regex("""<li class="card--lite">\s*<a href="(/[^"]+)">\s*([^<]+?)\s*</a>""")
    private val CNN_DATE = Regex("""^/(\d{4})/(\d{2})/(\d{2})/""")
    private val WHITESPACE = Regex("""\s+""")
    private val ENTITY = Regex("""&(#?[xX]?[0-9a-zA-Z]+);""")
    private val NAMED_ENTITIES = mapOf(
        "amp" to "&", "quot" to "\"", "apos" to "'", "lt" to "<", "gt" to ">", "nbsp" to " ",
        "laquo" to "«", "raquo" to "»", "mdash" to "—", "ndash" to "–", "hellip" to "…",
        "lsquo" to "‘", "rsquo" to "’", "ldquo" to "“", "rdquo" to "”",
    )
}

/** Общая лента виджета. */
object NewsFeed {

    /**
     * Все издания вперемешку, свежие сверху. У CNN времени нет: сегодняшние заголовки — это то,
     * что редакция держит наверху прямо сейчас, поэтому ставим их как «только что» в порядке выдачи
     * (каждый следующий — на 10 минут старше); заголовки прошлых дней — на начало своего дня.
     */
    fun merge(items: List<NewsItem>, now: Instant, zone: ZoneId): List<NewsItem> {
        val today = now.atZone(zone).toLocalDate()
        return items.sortedByDescending { item ->
            item.published ?: when (item.date) {
                null, today -> now.minus(item.rank * 10L, ChronoUnit.MINUTES)
                else -> item.date.atStartOfDay(zone).toInstant()
            }
        }
    }

    /** Подпись под заголовком: «РБК · 23:08», «CNN · сегодня», «NYT · 9 окт, 21:00». */
    fun caption(item: NewsItem, now: Instant, zone: ZoneId): String {
        val today = now.atZone(zone).toLocalDate()
        val local = item.published?.atZone(zone)
        val time = when {
            local == null && (item.date == null || item.date == today) -> "сегодня"
            local == null -> item.date!!.format(DAY)
            local.toLocalDate() == today -> local.format(TIME)
            else -> local.format(DAY_TIME)
        }
        return "${item.source.title} · $time"
    }

    private val TIME = DateTimeFormatter.ofPattern("HH:mm")
    private val DAY = DateTimeFormatter.ofPattern("d MMM", RU)
    private val DAY_TIME = DateTimeFormatter.ofPattern("d MMM, HH:mm", RU)
}
