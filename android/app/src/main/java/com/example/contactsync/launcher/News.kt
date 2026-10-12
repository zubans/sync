package com.example.contactsync.launcher

import com.example.contactsync.data.NewsDigestDto
import com.example.contactsync.data.NewsItemDto
import com.example.contactsync.data.NewsSourceDto
import com.example.contactsync.data.StorylineDto
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
    /** Сводка: сюжеты со ссылками на оригиналы; null — обычная новость. */
    val digest: NewsDigest? = null,
    /** Полный текст абзацами — приходит, когда новость открыта для чтения; пусто — ещё не загружен или его нет. */
    val content: List<String> = emptyList(),
    /** Текст загружен с сервера: [content] — полный текст, а если пусто — у издания есть только анонс. */
    val loaded: Boolean = false,
    val sourceTitle: String? = null,
    /** Сюжетная линия новости: id, название и сколько в ней новостей; null — не разобрана или без названия. */
    val storyline: StorylineRef? = null,
    /** Язык издания: ru, en, fr, uk. */
    val language: String = "ru",
    /** Новость на языке издания — у переведённых сервером; [title], [summary] и [content] тогда перевод. */
    val original: NewsOriginal? = null,
)

/** Оригинал переведённой новости: заголовок, анонс и абзацы полного текста (пусто — текст не переведён или не загружен). */
data class NewsOriginal(val title: String, val summary: String, val paragraphs: List<String> = emptyList())

data class StorylineRef(val id: Long, val title: String, val newsCount: Int)

/** Сюжетная линия — одна история во времени из новостей разных изданий; её строит сервер (`app:news:analyze`). */
data class NewsStoryline(
    val id: Long,
    val title: String,
    val summary: String,
    val firstSeen: Instant,
    val lastSeen: Instant,
    val newsCount: Int,
    /** Новостей за последние сутки — набирает история обороты или затихает. */
    val recent: Int,
    /** Издания по убыванию числа новостей: название → число. */
    val sources: List<Pair<String, Int>>,
)

/** Экран чтения: новость или хроника сюжета. Экраны открываются стопкой, «Назад» снимает верхний. */
sealed interface ReaderPage {
    data class News(val item: NewsItem) : ReaderPage

    data class Story(val line: NewsStoryline, val items: List<NewsItem> = emptyList(), val loaded: Boolean = false) : ReaderPage
}

/** Сводка новостей за час или за сутки — её пишет сервер (`app:news:digest`). */
data class NewsDigest(
    val daily: Boolean,
    val start: Instant,
    val end: Instant,
    /** Сколько новостей ушло в сводку. */
    val newsCount: Int,
    val stories: List<NewsStory>,
    /** Та же сводка одной статьёй с общим заголовком; null — у старых сводок, только сюжеты. */
    val article: NewsArticle? = null,
) {
    /** Все исходные новости сводки без повторов — список «Источники» под статьёй. */
    val sources: List<NewsStorySource> get() = stories.flatMap { it.sources }.distinctBy { it.link }
}

data class NewsArticle(val title: String, val paragraphs: List<String>)

/** Как показывать сводку: списком сюжетов или одной статьёй. */
enum class DigestFormat { STORIES, ARTICLE }

data class NewsStory(val title: String, val text: String, val sources: List<NewsStorySource>)

/** Исходная новость сюжета: код и название издания, заголовок, адрес статьи и id новости на сервере. */
data class NewsStorySource(
    val source: String,
    val title: String,
    val link: String,
    val id: Long = 0,
    val sourceTitle: String? = null,
) {
    /** Новость для экрана чтения: полный текст и время экран догрузит с сервера по id. */
    fun toItem(now: java.time.Instant) = NewsItem(id = id, source = source, title = title, link = link, summary = "", time = now)
}

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
        digest = dto.digest?.let(::digest),
        content = if (dto.fullText) dto.content else emptyList(),
        sourceTitle = dto.sourceTitle,
        storyline = dto.storyline?.let { StorylineRef(it.id, it.title, it.newsCount) },
        language = dto.language,
        original = dto.original?.takeIf { dto.translated }?.let {
            NewsOriginal(it.title, it.summary.orEmpty(), if (dto.fullText) dto.originalContent else emptyList())
        },
    )

    /** Подпись перевода: «с английского»; null — язык неизвестен клиенту. */
    fun languageName(code: String): String? = when (code) {
        "en" -> "с английского"
        "fr" -> "с французского"
        "uk" -> "с украинского"
        else -> null
    }

    fun storyline(dto: StorylineDto) = NewsStoryline(
        id = dto.id,
        title = dto.title.orEmpty(),
        summary = dto.summary.orEmpty(),
        firstSeen = OffsetDateTime.parse(dto.firstSeenAt).toInstant(),
        lastSeen = OffsetDateTime.parse(dto.lastSeenAt).toInstant(),
        newsCount = dto.newsCount,
        recent = dto.recent,
        sources = dto.sources.entries.sortedByDescending { it.value }.map { it.key to it.value },
    )

    /** Подпись сюжета: «3-й день · 45 новостей · 12 за сутки». */
    fun storylineCaption(line: NewsStoryline, now: Instant, zone: ZoneId): String {
        val days = java.time.temporal.ChronoUnit.DAYS.between(line.firstSeen.atZone(zone).toLocalDate(), now.atZone(zone).toLocalDate()) + 1
        val parts = mutableListOf(if (days <= 1) "сегодня" else "$days-й день", "${line.newsCount} ${plural(line.newsCount, "новость", "новости", "новостей")}")
        if (line.recent > 0 && line.recent < line.newsCount) parts += "${line.recent} за сутки"
        return parts.joinToString(" · ")
    }

    private fun plural(n: Int, one: String, few: String, many: String): String {
        val mod100 = n % 100
        val mod10 = n % 10
        return when {
            mod100 in 11..14 -> many
            mod10 == 1 -> one
            mod10 in 2..4 -> few
            else -> many
        }
    }

    /** Вкладка «Сюжеты» — не издание, а список сюжетных линий. */
    const val STORYLINES = "storylines"

    private fun digest(dto: NewsDigestDto) = NewsDigest(
        daily = dto.period == "day",
        start = OffsetDateTime.parse(dto.start).toInstant(),
        end = OffsetDateTime.parse(dto.end).toInstant(),
        newsCount = dto.newsCount,
        stories = dto.stories.map { story ->
            NewsStory(story.title, story.text, story.sources.map { NewsStorySource(it.source, it.title, it.link, it.id, it.sourceTitle) })
        },
        article = dto.article?.let { article ->
            NewsArticle(article.title, article.text.split(Regex("\\n\\s*\\n")).map { it.trim() }.filter { it.isNotEmpty() })
        },
    )

    /** Вкладки: «Сводка» первой — это выжимка всех остальных изданий. */
    fun tabs(sources: List<NewsSource>): List<NewsSource> = sources.sortedBy { it.code != DIGEST }

    /**
     * Коды вкладок по порядку, как в полосе вкладок: «Все» (null), сводка, «Сюжеты» сразу после неё,
     * затем издания. По этому порядку свайп переходит на соседнюю вкладку.
     */
    fun tabCodes(sources: List<NewsSource>): List<String?> =
        listOf<String?>(null) + tabs(sources).flatMap { if (it.code == DIGEST) listOf(it.code, STORYLINES) else listOf(it.code) }

    /**
     * Вкладка после свайпа: палец влево — вкладка левее, вправо — правее; у края полосы — та же вкладка.
     *
     * @param dragX смещение пальца по горизонтали: меньше нуля — влево
     */
    fun swipeTab(tabs: List<String?>, current: String?, dragX: Float): String? {
        val index = tabs.indexOf(current).takeIf { it >= 0 } ?: return current
        val next = if (dragX < 0) index - 1 else index + 1
        return tabs.getOrNull(next) ?: current
    }

    /** Подпись периода сводки: «За час · 14:00–15:00», «За сутки · 11 окт., 07:00 – 12 окт., 07:00». */
    fun period(digest: NewsDigest, zone: ZoneId): String {
        val start = digest.start.atZone(zone)
        val end = digest.end.atZone(zone)
        return if (digest.daily) {
            "За сутки · ${start.format(DAY_TIME)} – ${end.format(DAY_TIME)}"
        } else {
            "За час · ${start.format(TIME)}–${end.format(TIME)}"
        }
    }

    /** Код издания-сводки на сервере. */
    const val DIGEST = "digest"

    fun source(dto: NewsSourceDto) = NewsSource(dto.code, dto.title, dto.stale)

    /** Подпись под заголовком: «РБК · 23:08», за прошлые дни — «NYT · 9 окт., 21:00». */
    fun caption(item: NewsItem, sources: List<NewsSource>, now: Instant, zone: ZoneId): String {
        val title = sources.firstOrNull { it.code == item.source }?.title ?: item.sourceTitle ?: item.source
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
