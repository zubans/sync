package com.example.contactsync.data

import kotlinx.serialization.Serializable

/** Новости, собранные сервером (`GET /api/news`). */
@Serializable
data class NewsFeedResponse(val items: List<NewsItemDto>, val nextCursor: String? = null)

@Serializable
data class NewsItemDto(
    val id: Long,
    val source: String,
    val title: String,
    val link: String,
    val summary: String? = null,
    /** ISO 8601: время публикации, а без него — когда сервер увидел новость. */
    val time: String,
    /** Только у сводок (издание digest): период и сюжеты со ссылками на исходные новости. */
    val digest: NewsDigestDto? = null,
    /** Только в `GET /api/news/{id}`: полный текст абзацами; пусто — есть только анонс. */
    val content: List<String> = emptyList(),
    /** Текст полный, а не анонс (у закрытых сайтов, как NYT, — false). */
    val fullText: Boolean = false,
)

@Serializable
data class NewsDigestDto(
    /** hour или day. */
    val period: String,
    val start: String,
    val end: String,
    val newsCount: Int = 0,
    val stories: List<NewsStoryDto> = emptyList(),
    /** Та же сводка одной статьёй; null — у сводок, написанных до этого формата. */
    val article: NewsArticleDto? = null,
)

@Serializable
data class NewsArticleDto(val title: String, val text: String)

@Serializable
data class NewsStoryDto(val title: String, val text: String = "", val sources: List<NewsStorySourceDto> = emptyList())

@Serializable
data class NewsStorySourceDto(val source: String, val title: String, val link: String)

@Serializable
data class NewsSourcesResponse(val sources: List<NewsSourceDto>)

@Serializable
data class NewsSourceDto(val code: String, val title: String, val stale: Boolean = false)
