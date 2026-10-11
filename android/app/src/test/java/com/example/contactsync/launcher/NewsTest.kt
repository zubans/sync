package com.example.contactsync.launcher

import com.example.contactsync.data.NewsItemDto
import com.example.contactsync.data.NewsSourceDto
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
    fun `next page is appended without repeating items`() {
        val first = NewsPage(listOf(1L, 2L).map { NewsFeed.item(dto(it, "rbc", "2026-10-11T20:00:00+00:00")) }, "c1")
        val next = NewsPage(listOf(2L, 3L).map { NewsFeed.item(dto(it, "rbc", "2026-10-11T19:00:00+00:00")) }, null)

        val page = NewsFeed.append(first, next)

        assertEquals(listOf(1L, 2L, 3L), page.items.map { it.id })
        assertEquals(null, page.nextCursor)
    }
}
