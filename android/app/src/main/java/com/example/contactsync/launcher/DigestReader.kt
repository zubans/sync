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
import java.time.ZoneId

/**
 * Сводка на весь экран — по нажатию на неё в карточке «Новости» или в списке сводок.
 * Формат — как выбран на вкладке «Сводка»: одна статья или список сюжетов; у старых сводок
 * без статьи — всегда сюжеты. Строка текста ограничена по ширине: так длинный текст читать легче.
 */
@Composable
internal fun DigestReader(state: LauncherState, vm: LauncherViewModel) {
    // Последняя открытая сводка: пока экран уезжает, он должен показывать её, а не пустоту.
    var shown by remember { mutableStateOf<NewsItem?>(null) }
    state.reading?.let { shown = it }
    AnimatedVisibility(
        visible = state.reading != null,
        enter = fadeIn(tween(300)) + slideInVertically(tween(300)) { it / 12 },
        exit = fadeOut(tween(250)) + slideOutVertically(tween(250)) { it / 12 },
    ) {
        val item = shown ?: return@AnimatedVisibility
        val digest = item.digest ?: return@AnimatedVisibility
        BackHandler { vm.read(null) }
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
                        "${NewsFeed.period(digest, ZoneId.systemDefault())} · новостей ${digest.newsCount}",
                        color = Accent,
                        fontSize = 16.sp,
                        modifier = Modifier.weight(1f),
                    )
                    DigestFormatToggle(state, vm)
                    Spacer(Modifier.size(12.dp))
                    Box(
                        Modifier.size(52.dp).clip(CircleShape).background(CardRaised).clickable { vm.read(null) },
                        contentAlignment = Alignment.Center,
                    ) {
                        Text("✕", color = TextMain, fontSize = 22.sp)
                    }
                }
                Spacer(Modifier.height(20.dp))
                val article = digest.article
                if (state.digestFormat == DigestFormat.ARTICLE && article != null) {
                    ArticleText(article, digest.sources, state)
                } else {
                    StoriesText(item, digest, state, note = if (state.digestFormat == DigestFormat.ARTICLE) "У этой сводки нет статьи — показаны сюжеты" else null)
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
private fun ArticleText(article: NewsArticle, sources: List<NewsStorySource>, state: LauncherState) {
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
                        .clickable { context.openUrl(source.link) }
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
private fun StoriesText(item: NewsItem, digest: NewsDigest, state: LauncherState, note: String?) {
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
                                    .clickable { context.openUrl(source.link) }
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
    state.newsSources.firstOrNull { it.code == source.source }?.title ?: source.source
