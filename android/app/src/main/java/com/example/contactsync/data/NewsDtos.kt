package com.example.contactsync.data

import kotlinx.serialization.Serializable

/** Новости, собранные сервером (`GET /api/news`). */
@Serializable
data class NewsFeedResponse(val items: List<NewsItemDto>, val nextCursor: String? = null)

@Serializable
data class NewsItemDto(
    val id: Long,
    val source: String,
    /** Название издания — у изданий «только для сводки» вкладки нет. */
    val sourceTitle: String? = null,
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
    /** Сюжетная линия новости, если у неё уже есть название. */
    val storyline: StorylineRefDto? = null,
    /** Язык издания (ru, en, fr, uk); у старого сервера поля нет — русский. */
    val language: String = "ru",
    /** Новость переведена на русский: [title] и [summary] — перевод, оригинал — в [original]. */
    val translated: Boolean = false,
    /** Заголовок и анонс на языке издания — только у переведённых новостей. */
    val original: NewsOriginalDto? = null,
    /** Только в `GET /api/news/{id}` у переведённых статей: полный текст на языке издания, [content] — перевод. */
    val originalContent: List<String> = emptyList(),
)

@Serializable
data class NewsOriginalDto(val title: String, val summary: String? = null)

@Serializable
data class StorylineRefDto(val id: Long, val title: String, val newsCount: Int = 0)

/** Сюжетная линия: одна история во времени из новостей разных изданий (`GET /api/news/storylines`). */
@Serializable
data class StorylineDto(
    val id: Long,
    val title: String? = null,
    val summary: String? = null,
    val firstSeenAt: String,
    val lastSeenAt: String,
    val newsCount: Int = 0,
    /** Новостей за последние сутки. */
    val recent: Int = 0,
    /** Сколько новостей каждого издания: название → число. */
    val sources: Map<String, Int> = emptyMap(),
    /** Только в `GET /api/news/storylines/{id}`: новости линии, свежие первыми. */
    val items: List<NewsItemDto> = emptyList(),
)

@Serializable
data class StorylinesResponse(val storylines: List<StorylineDto>)

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
data class NewsStorySourceDto(
    val source: String,
    val title: String,
    val link: String,
    /** id исходной новости — открыть её с сервера; 0 — у старых ответов сервера. */
    val id: Long = 0,
    /** Название издания: у изданий «только для сводки» вкладки нет, и взять его больше негде. */
    val sourceTitle: String? = null,
)

@Serializable
data class NewsSourcesResponse(val sources: List<NewsSourceDto>)

@Serializable
data class NewsSourceDto(val code: String, val title: String, val stale: Boolean = false)
