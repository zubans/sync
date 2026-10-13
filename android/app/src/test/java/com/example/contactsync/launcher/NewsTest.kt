package com.example.contactsync.launcher

import com.example.contactsync.data.NewsArticleDto
import com.example.contactsync.data.NewsDigestDto
import com.example.contactsync.data.NewsItemDto
import com.example.contactsync.data.NewsOriginalDto
import com.example.contactsync.data.NewsSourceDto
import com.example.contactsync.data.NewsStoryDto
import com.example.contactsync.data.NewsStorySourceDto
import com.example.contactsync.data.StorylineDto
import org.junit.Assert.assertEquals
import org.junit.Test
import java.time.Instant
import java.time.ZoneId

class NewsTest {
    private val zone = ZoneId.of("Europe/Moscow")
    private val now = Instant.parse("2026-10-11T20:50:00Z")
    private val sources = listOf(NewsSource("rbc", "РБК"), NewsSource("nyt", "NYT", stale = true))

    private fun dto(id: Long, source: String, time: String) =
        NewsItemDto(id = id, source = source, title = "Новость $id", link = "https://example.com/$id", time = time)

    @Test
    fun `server item keeps time with offset and empty summary`() {
        val item = NewsFeed.item(dto(1, "rbc", "2026-10-11T20:08:52+00:00"))
        assertEquals(Instant.parse("2026-10-11T20:08:52Z"), item.time)
        assertEquals("", item.summary)
        assertEquals(NewsSource("nyt", "NYT", true), NewsFeed.source(NewsSourceDto("nyt", "NYT", stale = true)))
    }

    @Test
    fun `caption shows source title and time today or date for older items`() {
        assertEquals("РБК · 23:08", NewsFeed.caption(NewsFeed.item(dto(1, "rbc", "2026-10-11T20:08:52+00:00")), sources, now, zone))
        assertEquals("NYT · 9 окт., 21:00", NewsFeed.caption(NewsFeed.item(dto(2, "nyt", "2026-10-09T18:00:00+00:00")), sources, now, zone))
        assertEquals("cnn · 22:00", NewsFeed.caption(NewsFeed.item(dto(3, "cnn", "2026-10-11T19:00:00+00:00")), sources, now, zone))
    }

    @Test
    fun `digest keeps period and stories with source links`() {
        val dto = dto(9, "digest", "2026-10-11T14:00:00+00:00").copy(
            digest = NewsDigestDto(
                period = "hour",
                start = "2026-10-11T13:00:00+00:00",
                end = "2026-10-11T14:00:00+00:00",
                newsCount = 48,
                stories = listOf(NewsStoryDto("Сюжет", "Суть.", listOf(NewsStorySourceDto("rbc", "Новость", "https://rbc/1")))),
            ),
        )

        val digest = NewsFeed.item(dto).digest!!

        assertEquals("За час · 16:00–17:00", NewsFeed.period(digest, zone))
        assertEquals("https://rbc/1", digest.stories.single().sources.single().link)
        assertEquals(
            "За сутки · 11 окт., 07:00 – 12 окт., 07:00",
            NewsFeed.period(digest.copy(daily = true, start = Instant.parse("2026-10-11T04:00:00Z"), end = Instant.parse("2026-10-12T04:00:00Z")), zone),
        )
    }

    @Test
    fun `digest article splits into paragraphs and sources are listed once`() {
        val source = NewsStorySourceDto("rbc", "Новость", "https://rbc/1")
        val dto = dto(10, "digest", "2026-10-11T14:00:00+00:00").copy(
            digest = NewsDigestDto(
                period = "day",
                start = "2026-10-10T04:00:00+00:00",
                end = "2026-10-11T04:00:00+00:00",
                stories = listOf(NewsStoryDto("А", "", listOf(source)), NewsStoryDto("Б", "", listOf(source))),
                article = NewsArticleDto("Главное за сутки", "Первый абзац.\n\n  \nВторой абзац.\n\n"),
            ),
        )

        val digest = NewsFeed.item(dto).digest!!

        assertEquals(NewsArticle("Главное за сутки", listOf("Первый абзац.", "Второй абзац.")), digest.article)
        assertEquals(listOf("https://rbc/1"), digest.sources.map { it.link })
        assertEquals(null, NewsFeed.item(dto.copy(digest = dto.digest!!.copy(article = null))).digest!!.article)
    }

    @Test
    fun `full text is taken only when server says it is full`() {
        val full = dto(11, "rg", "2026-10-11T14:00:00+00:00").copy(content = listOf("Первый.", "Второй."), fullText = true)
        val teaser = full.copy(content = listOf("Анонс."), fullText = false)

        assertEquals(listOf("Первый.", "Второй."), NewsFeed.item(full).content)
        assertEquals(emptyList<String>(), NewsFeed.item(teaser).content)
    }

    @Test
    fun `translated item keeps original title and paragraphs`() {
        val dto = dto(13, "bbc", "2026-10-11T19:00:00+00:00").copy(
            title = "Удар по аэропорту",
            summary = "Хуситы атакуют.",
            language = "en",
            translated = true,
            original = NewsOriginalDto("Airport hit", "Houthis strike."),
            content = listOf("Раз.", "Два."),
            originalContent = listOf("One.", "Two."),
            fullText = true,
        )

        val item = NewsFeed.item(dto)

        assertEquals("Удар по аэропорту", item.title)
        assertEquals(listOf("Раз.", "Два."), item.content)
        assertEquals(NewsOriginal("Airport hit", "Houthis strike.", listOf("One.", "Two.")), item.original)
        assertEquals("en", item.language)
        assertEquals(null, NewsFeed.item(dto.copy(translated = false, original = null)).original)
        assertEquals("старый сервер — русский", "ru", NewsFeed.item(dto(14, "rbc", "2026-10-11T19:00:00+00:00")).language)
    }

    @Test
    fun `language caption for translated news`() {
        assertEquals("с английского", NewsFeed.languageName("en"))
        assertEquals("с французского", NewsFeed.languageName("fr"))
        assertEquals("с украинского", NewsFeed.languageName("uk"))
        assertEquals(null, NewsFeed.languageName("de"))
    }

    @Test
    fun `digest-only source is captioned by its server title`() {
        val item = NewsFeed.item(dto(12, "lemonde", "2026-10-11T19:00:00+00:00").copy(sourceTitle = "Le Monde"))
        assertEquals("Le Monde · 22:00", NewsFeed.caption(item, sources, now, zone))
    }

    @Test
    fun `storyline caption counts days news and recent ones`() {
        val line = NewsFeed.storyline(
            StorylineDto(
                id = 1, title = "Удары по Эр-Рияду", firstSeenAt = "2026-10-09T10:00:00+00:00", lastSeenAt = "2026-10-11T19:00:00+00:00",
                newsCount = 45, recent = 12, sources = mapOf("BBC" to 5, "РБК" to 20),
            ),
        )

        assertEquals("3-й день · 45 новостей · 12 за сутки", NewsFeed.storylineCaption(line, now, zone))
        assertEquals(listOf("РБК" to 20, "BBC" to 5), line.sources)
        assertEquals("сегодня · 1 новость", NewsFeed.storylineCaption(line.copy(firstSeen = now, newsCount = 1, recent = 1), now, zone))
        assertEquals("сегодня · 3 новости", NewsFeed.storylineCaption(line.copy(firstSeen = now, newsCount = 3, recent = 3), now, zone))
    }

    @Test
    fun `swipe moves to the neighbour tab in the order of the tab strip`() {
        val tabs = NewsFeed.tabCodes(sources + NewsSource("digest", "Сводка"))
        assertEquals(listOf(null, "digest", "storylines", "rbc", "nyt"), tabs)

        assertEquals("палец влево — следующая вкладка", "rbc", NewsFeed.swipeTab(tabs, "storylines", dragX = -200f))
        assertEquals("палец вправо — предыдущая вкладка", "digest", NewsFeed.swipeTab(tabs, "storylines", dragX = 200f))
        assertEquals("левее «Все» вкладок нет", null, NewsFeed.swipeTab(tabs, null, dragX = 200f))
        assertEquals("правее последней вкладок нет", "nyt", NewsFeed.swipeTab(tabs, "nyt", dragX = -200f))
    }

    @Test
    fun `digest tab goes first`() {
        val tabs = NewsFeed.tabs(sources + NewsSource("digest", "Сводка"))
        assertEquals(listOf("digest", "rbc", "nyt"), tabs.map { it.code })
    }

    @Test
    fun `next page is appended without repeating items`() {
        val first = NewsPage(listOf(1L, 2L).map { NewsFeed.item(dto(it, "rbc", "2026-10-11T20:00:00+00:00")) }, "c1")
        val next = NewsPage(listOf(2L, 3L).map { NewsFeed.item(dto(it, "rbc", "2026-10-11T19:00:00+00:00")) }, null)

        val page = NewsFeed.append(first, next)

        assertEquals(listOf(1L, 2L, 3L), page.items.map { it.id })
        assertEquals(null, page.nextCursor)
    }
}
