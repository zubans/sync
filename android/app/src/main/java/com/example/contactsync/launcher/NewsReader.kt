package com.example.contactsync.launcher

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.contactsync.kb.openUrl
import java.time.Instant
import java.time.ZoneId

/**
 * Новость на весь экран — по нажатию на неё в карточке «Новости» или в списке.
 * Обычная новость — полный текст с сервера, сверху ссылка на оригинал на сайте издания.
 * Сводка — в формате, выбранном на вкладке «Сводка»: одна статья или список сюжетов; у старых
 * сводок без статьи — всегда сюжеты. Строка текста ограничена по ширине: так длинный текст читать легче.
 */
@Composable
internal fun NewsReader(state: LauncherState, vm: LauncherViewModel) {
    // Последняя открытая сводка: пока экран уезжает, он должен показывать её, а не пустоту.
    var shown by remember { mutableStateOf<NewsItem?>(null) }
    state.reading?.let { shown = it }
    AnimatedVisibility(
        visible = state.reading != null,
        enter = fadeIn(tween(300)) + slideInVertically(tween(300)) { it / 12 },
        exit = fadeOut(tween(250)) + slideOutVertically(tween(250)) { it / 12 },
    ) {
        val item = shown ?: return@AnimatedVisibility
        BackHandler { vm.closeReading() }
        val digest = item.digest
        Box(
            Modifier
                .fillMaxSize()
                .background(Background)
                // Нажатия не проваливаются в экран под статьёй.
                .pointerInput(Unit) { detectTapGestures { } }
                .safeDrawingPadding()
                .padding(horizontal = 32.dp, vertical = 24.dp),
            contentAlignment = Alignment.TopCenter,
        ) {
            Column(Modifier.widthIn(max = 860.dp).fillMaxSize()) {
                Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
                    Text(
                        if (digest != null) {
                            "${NewsFeed.period(digest, ZoneId.systemDefault())} · новостей ${digest.newsCount}"
                        } else {
                            NewsFeed.caption(item, state.newsSources, Instant.now(), ZoneId.systemDefault())
                        },
                        color = Accent,
                        fontSize = 16.sp,
                        modifier = Modifier.weight(1f),
                    )
                    if (digest != null) DigestFormatToggle(state, vm)
                    Spacer(Modifier.size(12.dp))
                    Box(
                        Modifier.size(52.dp).clip(CircleShape).background(CardRaised).clickable { vm.closeReading() },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("✕", color = TextMain, fontSize = 22.sp)
                    }
                }
                Spacer(Modifier.height(20.dp))
                if (digest == null) {
                    NewsText(item, state)
                    return@Column
                }
                val article = digest.article
                if (state.digestFormat == DigestFormat.ARTICLE && article != null) {
                    ArticleText(article, digest.sources, state, vm)
                } else {
                    StoriesText(item, digest, state, vm, note = if (state.digestFormat == DigestFormat.ARTICLE) "У этой сводки нет статьи — показаны сюжеты" else null)
                }
            }
        }
    }
}

/**
 * Обычная новость: ссылка на оригинал, заголовок и полный текст, сохранённый сервером.
 * Полного текста нет (сайт издания закрыт) — анонс и пояснение.
 */
@Composable
private fun NewsText(item: NewsItem, state: LauncherState) {
    val context = LocalContext.current
    val source = item.sourceTitle ?: state.newsSources.firstOrNull { it.code == item.source }?.title ?: item.source
    LazyColumn(verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item {
            Text(
                "Оригинал на сайте: $source ↗",
                color = Accent,
                fontSize = 16.sp,
                modifier = Modifier
                    .clip(RoundedCornerShape(12.dp))
                    .background(CardRaised)
                    .clickable { context.openUrl(item.link) }
                    .padding(horizontal = 14.dp, vertical = 10.dp),
            )
        }
        item {
            Text(item.title, color = TextMain, fontSize = 32.sp, fontWeight = FontWeight.Medium, lineHeight = 40.sp)
        }
        when {
            item.content.isNotEmpty() -> items(item.content) { paragraph ->
                Text(paragraph, color = TextMain, fontSize = 20.sp, lineHeight = 31.sp)
            }
            !item.loaded -> item { Text("Загрузка…", color = TextMuted, fontSize = 18.sp) }
            else -> {
                if (item.summary.isNotEmpty()) {
                    item { Text(item.summary, color = TextMain, fontSize = 20.sp, lineHeight = 31.sp) }
                }
                item {
                    Text(
                        "Полного текста нет: издание не отдаёт статью. Её можно открыть на сайте — ссылка выше.",
                        color = TextMuted,
                        fontSize = 16.sp,
                    )
                }
            }
        }
    }
}

/** Переключатель формата сводки: «Сюжеты» или «Статья». Выбор запоминается. */
@Composable
internal fun DigestFormatToggle(state: LauncherState, vm: LauncherViewModel) {
    Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
        NewsChip("Сюжеты", state.digestFormat == DigestFormat.STORIES, failed = false) { vm.setDigestFormat(DigestFormat.STORIES) }
        NewsChip("Статья", state.digestFormat == DigestFormat.ARTICLE, failed = false) { vm.setDigestFormat(DigestFormat.ARTICLE) }
    }
}

@Composable
private fun ArticleText(article: NewsArticle, sources: List<NewsStorySource>, state: LauncherState, vm: LauncherViewModel) {
    val context = LocalContext.current
    LazyColumn(verticalArrangement = Arrangement.spacedBy(18.dp)) {
        item {
            Text(article.title, color = TextMain, fontSize = 34.sp, fontWeight = FontWeight.Medium, lineHeight = 42.sp)
        }
        items(article.paragraphs) { paragraph ->
            Text(paragraph, color = TextMain, fontSize = 20.sp, lineHeight = 31.sp)
        }
        if (sources.isNotEmpty()) {
            item {
                Text("Источники", color = TextMuted, fontSize = 16.sp, modifier = Modifier.padding(top = 16.dp))
            }
            items(sources, key = { it.link }) { source ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(12.dp))
                        .clickable { openSource(source, vm, context) }
                        .padding(vertical = 6.dp),
                ) {
                    Text(sourceTitle(source, state), color = Accent, fontSize = 15.sp, modifier = Modifier.widthIn(min = 80.dp))
                    Text(source.title, color = TextMuted, fontSize = 15.sp, modifier = Modifier.padding(start = 8.dp))
                }
            }
        }
    }
}

@Composable
private fun StoriesText(item: NewsItem, digest: NewsDigest, state: LauncherState, vm: LauncherViewModel, note: String?) {
    val context = LocalContext.current
    LazyColumn(verticalArrangement = Arrangement.spacedBy(22.dp)) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(item.title, color = TextMain, fontSize = 32.sp, fontWeight = FontWeight.Medium, lineHeight = 40.sp)
                if (note != null) Text(note, color = TextMuted, fontSize = 15.sp)
            }
        }
        items(digest.stories) { story ->
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(story.title, color = TextMain, fontSize = 23.sp, fontWeight = FontWeight.Medium, lineHeight = 30.sp)
                if (story.text.isNotEmpty()) Text(story.text, color = TextMain, fontSize = 19.sp, lineHeight = 29.sp)
                if (story.sources.isNotEmpty()) {
                    Row(Modifier.horizontalScroll(rememberScrollState()), horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                        story.sources.forEach { source ->
                            Text(
                                sourceTitle(source, state),
                                color = Accent,
                                fontSize = 14.sp,
                                modifier = Modifier
                                    .clip(RoundedCornerShape(10.dp))
                                    .background(CardRaised)
                                    .clickable { openSource(source, vm, context) }
                                    .padding(horizontal = 12.dp, vertical = 6.dp),
                            )
                        }
                    }
                }
            }
        }
    }
}

private fun sourceTitle(source: NewsStorySource, state: LauncherState): String =
    source.sourceTitle ?: state.newsSources.firstOrNull { it.code == source.source }?.title ?: source.source

/** Исходная новость сюжета — с сервера, поверх сводки; у старых ответов без id — на сайте издания. */
private fun openSource(source: NewsStorySource, vm: LauncherViewModel, context: android.content.Context) {
    if (source.id != 0L) vm.read(source.toItem(Instant.now())) else context.openUrl(source.link)
}
