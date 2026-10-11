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
)

@Serializable
data class NewsSourcesResponse(val sources: List<NewsSourceDto>)

@Serializable
data class NewsSourceDto(val code: String, val title: String, val stale: Boolean = false)
