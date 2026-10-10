package com.example.contactsync.launcher

import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.jsonArray
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.util.concurrent.TimeUnit

/**
 * Поиск Яндекса со строки на главном экране: подсказки — открытый suggest.yandex.ru (без ключа),
 * сам поиск — страница yandex.ru в браузере по умолчанию. Готовый виджет Яндекса не используем.
 */
object YandexSearch {
    private val client = OkHttpClient.Builder().callTimeout(5, TimeUnit.SECONDS).build()

    fun searchUrl(query: String): String =
        "https://yandex.ru/search/".toHttpUrl().newBuilder().addQueryParameter("text", query.trim()).build().toString()

    suspend fun suggestions(query: String): List<String> = withContext(Dispatchers.IO) {
        val url = "https://suggest.yandex.ru/suggest-ff.cgi".toHttpUrl().newBuilder()
            .addQueryParameter("part", query)
            .addQueryParameter("uil", "ru")
            .build()
        client.newCall(Request.Builder().url(url).build()).execute().use {
            if (!it.isSuccessful) throw IOException("HTTP ${it.code}")
            parseSuggestions(it.body!!.string())
        }
    }

    /** Ответ suggest-ff: `["запрос", ["подсказка 1", "подсказка 2", …]]`. */
    fun parseSuggestions(body: String, limit: Int = 6): List<String> {
        val root = Json.parseToJsonElement(body) as? JsonArray ?: return emptyList()
        return root.getOrNull(1)?.jsonArray.orEmpty()
            .mapNotNull { (it as? JsonPrimitive)?.content?.takeIf(String::isNotBlank) }
            .distinct()
            .take(limit)
    }
}
