package com.example.contactsync.launcher

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

class NewsTest {
    private val zone = ZoneId.of("Europe/Moscow")
    private val now = Instant.parse("2026-10-10T20:50:00Z")

    private val rss = """<?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel><title>Главные новости</title>
        <item><title><![CDATA[Правительство постановило создать нацпарк «Вулканы Камчатки»]]></title>
            <link>https://www.rbc.ru/society/10/10/2026/6aca</link>
            <pubDate>Sat, 10 Oct 2026 23:08:52 +0300</pubDate></item>
        <item><title>&#8216;No other date&#8217;: pastor &amp; prophecy</title>
            <link> https://nypost.com/2026/10/10/pastor/ </link>
            <pubDate>Sat, 10 Oct 2026 16:44:53 -0400</pubDate></item>
        <item><title>Без ссылки</title></item>
        </channel></rss>"""

    private val cnn = """<ul>
          <li class="card--lite">
            <a href="/2026/10/10/us/philadelphia-officer-killed">
                Philadelphia police officer killed &amp; another injured
            </a>
        </li>
          <li class="card--lite">
            <a href="/2026/10/09/world/old-story">
                Yesterday&#39;s story
            </a>
        </li></ul>"""

    @Test
    fun `rss items take title link and publication time and skip items without link`() {
        val items = NewsParser.parse(NewsSource.RBC, rss)
        assertEquals(2, items.size)
        assertEquals("Правительство постановило создать нацпарк «Вулканы Камчатки»", items[0].title)
        assertEquals(Instant.parse("2026-10-10T20:08:52Z"), items[0].published)
        assertEquals("‘No other date’: pastor & prophecy", items[1].title)
        assertEquals("https://nypost.com/2026/10/10/pastor/", items[1].link)
    }

    @Test
    fun `cnn lite gives absolute links and date from url without time`() {
        val items = NewsParser.parse(NewsSource.CNN, cnn)
        assertEquals("Philadelphia police officer killed & another injured", items[0].title)
        assertEquals("https://edition.cnn.com/2026/10/10/us/philadelphia-officer-killed", items[0].link)
        assertEquals(LocalDate.of(2026, 10, 10), items[0].date)
        assertNull(items[0].published)
        assertEquals("Yesterday's story", items[1].title)
    }

    @Test
    fun `merged feed puts todays cnn headlines as just now in editorial order`() {
        val rssItems = NewsParser.parse(NewsSource.RBC, rss)
        val cnnItems = NewsParser.parse(NewsSource.CNN, cnn)
        val merged = NewsFeed.merge(rssItems + cnnItems, now, zone)
        assertEquals(
            listOf("Philadelphia police officer killed & another injured", rssItems[1].title, rssItems[0].title, "Yesterday's story"),
            merged.map { it.title },
        )
    }

    @Test
    fun `caption shows time today and date for older items`() {
        val items = NewsParser.parse(NewsSource.RBC, rss) + NewsParser.parse(NewsSource.CNN, cnn)
        assertEquals("РБК · 23:08", NewsFeed.caption(items[0], now, zone))
        assertEquals("CNN · сегодня", NewsFeed.caption(items[2], now, zone))
        assertEquals("CNN · 9 окт.", NewsFeed.caption(items[3], now, zone))
    }
}
