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
import androidx.compose.ui.platform.LocalConfiguration
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.ExperimentalTextApi
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontVariation
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.sp
import com.example.contactsync.R
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
    // Последний открытый экран: пока он уезжает, он должен показывать содержимое, а не пустоту.
    var shown by remember { mutableStateOf<ReaderPage?>(null) }
    state.reading?.let { shown = it }
    AnimatedVisibility(
        visible = state.reading != null,
        enter = fadeIn(tween(300)) + slideInVertically(tween(300)) { it / 12 },
        exit = fadeOut(tween(250)) + slideOutVertically(tween(250)) { it / 12 },
    ) {
        val page = shown ?: return@AnimatedVisibility
        BackHandler { vm.closeReading() }
        val zone = ZoneId.systemDefault()
        val item = (page as? ReaderPage.News)?.item
        val digest = item?.digest
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
                        when {
                            page is ReaderPage.Story -> "Сюжет · " + NewsFeed.storylineCaption(page.line, Instant.now(), zone)
                            digest != null -> "${NewsFeed.period(digest, zone)} · новостей ${digest.newsCount}"
                            else -> NewsFeed.caption(item!!, state.newsSources, Instant.now(), zone)
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
                if (page is ReaderPage.Story) {
                    StoryTimeline(page, state, vm)
                    return@Column
                }
                if (digest == null) {
                    NewsText(item!!, state, vm)
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
 * Хроника сюжетной линии: название, о чём история, издания и все её новости по дням, свежие сверху.
 * Нажатие на новость открывает её поверх хроники.
 */
@Composable
private fun StoryTimeline(page: ReaderPage.Story, state: LauncherState, vm: LauncherViewModel) {
    val zone = ZoneId.systemDefault()
    val days = page.items.groupBy { it.time.atZone(zone).toLocalDate() }
    LazyColumn(verticalArrangement = Arrangement.spacedBy(10.dp)) {
        item {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(page.line.title, color = TextMain, fontSize = readingType.title, fontWeight = FontWeight.SemiBold, lineHeight = readingType.titleLine, fontFamily = Reading)
                if (page.line.summary.isNotEmpty()) Text(page.line.summary, color = TextMain, fontSize = readingType.body, lineHeight = readingType.bodyLine, fontFamily = Reading)
                val sources = page.items.groupingBy { it.sourceTitle ?: it.source }.eachCount().entries.sortedByDescending { it.value }
                if (sources.isNotEmpty()) {
                    Text(sources.joinToString(" · ") { "${it.key} ${it.value}" }, color = TextMuted, fontSize = 15.sp)
                }
            }
        }
        if (!page.loaded) item { Text("Загрузка…", color = TextMuted, fontSize = 18.sp) }
        days.forEach { (day, items) ->
            item(key = "day-$day") {
                Text(
                    day.format(java.time.format.DateTimeFormatter.ofPattern("EEEE, d MMMM", RU)).replaceFirstChar { it.uppercase() },
                    color = TextMuted,
                    fontSize = 16.sp,
                    fontWeight = FontWeight.Medium,
                    modifier = Modifier.padding(top = 14.dp),
                )
            }
            items(items, key = { it.id }) { news ->
                Row(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(14.dp))
                        .background(CardColor)
                        .clickable { vm.read(news) }
                        .padding(horizontal = 16.dp, vertical = 12.dp),
                ) {
                    Text(news.time.atZone(zone).format(java.time.format.DateTimeFormatter.ofPattern("HH:mm")), color = TextMuted, fontSize = 15.sp, modifier = Modifier.widthIn(min = 56.dp))
                    Text(news.sourceTitle ?: news.source, color = Accent, fontSize = 15.sp, modifier = Modifier.widthIn(min = 110.dp).padding(end = 8.dp))
                    Text(news.title, color = TextMain, fontSize = 17.sp, lineHeight = 24.sp, fontFamily = Reading)
                }
            }
        }
    }
}

/**
 * Обычная новость: ссылка на оригинал, заголовок и полный текст, сохранённый сервером.
 * Полного текста нет (сайт издания закрыт) — анонс и пояснение.
 * Переведённая сервером новость показывается по-русски; плашка под ссылкой переключает на оригинал
 * и обратно — только на время чтения, в настройки выбор не пишется.
 */
@Composable
private fun NewsText(item: NewsItem, state: LauncherState, vm: LauncherViewModel) {
    val context = LocalContext.current
    val source = item.sourceTitle ?: state.newsSources.firstOrNull { it.code == item.source }?.title ?: item.source
    var showOriginal by remember(item.id) { mutableStateOf(false) }
    val original = item.original?.takeIf { showOriginal }
    val title = original?.title ?: item.title
    val summary = original?.summary ?: item.summary
    // Текст статьи не переведён — в content уже оригинал.
    val paragraphs = original?.paragraphs?.ifEmpty { item.content } ?: item.content
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
        if (item.original != null) {
            item {
                val from = NewsFeed.languageName(item.language)?.let { " $it" }.orEmpty()
                Text(
                    if (showOriginal) "Оригинал · Показать перевод" else "Перевод$from · Показать оригинал",
                    color = Accent,
                    fontSize = 16.sp,
                    modifier = Modifier
                        .clip(RoundedCornerShape(12.dp))
                        .background(Accent.copy(alpha = 0.12f))
                        .clickable { showOriginal = !showOriginal }
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                )
            }
        }
        item.storyline?.let { line ->
            item {
                // История, к которой относится новость: вся хроника — одним нажатием.
                Text(
                    "Сюжет: ${line.title} · ${line.newsCount} →",
                    color = Accent,
                    fontSize = 16.sp,
                    modifier = Modifier
                        .clip(RoundedCornerShape(12.dp))
                        .background(Accent.copy(alpha = 0.12f))
                        .clickable { vm.openStoryline(line) }
                        .padding(horizontal = 14.dp, vertical = 10.dp),
                )
            }
        }
        item {
            Text(title, color = TextMain, fontSize = readingType.title, fontWeight = FontWeight.SemiBold, lineHeight = readingType.titleLine, fontFamily = Reading)
        }
        when {
            paragraphs.isNotEmpty() -> items(paragraphs) { paragraph ->
                Text(paragraph, color = TextMain, fontSize = readingType.body, lineHeight = readingType.bodyLine, fontFamily = Reading)
            }
            !item.loaded -> item { Text("Загрузка…", color = TextMuted, fontSize = 18.sp) }
            else -> {
                if (summary.isNotEmpty()) {
                    item { Text(summary, color = TextMain, fontSize = readingType.body, lineHeight = readingType.bodyLine, fontFamily = Reading) }
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
            Text(article.title, color = TextMain, fontSize = readingType.title, fontWeight = FontWeight.SemiBold, lineHeight = readingType.titleLine, fontFamily = Reading)
        }
        items(article.paragraphs) { paragraph ->
            Text(paragraph, color = TextMain, fontSize = readingType.body, lineHeight = readingType.bodyLine, fontFamily = Reading)
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
                Text(item.title, color = TextMain, fontSize = readingType.title, fontWeight = FontWeight.SemiBold, lineHeight = readingType.titleLine, fontFamily = Reading)
                if (note != null) Text(note, color = TextMuted, fontSize = 15.sp)
            }
        }
        items(digest.stories) { story ->
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text(story.title, color = TextMain, fontSize = readingType.story, fontWeight = FontWeight.SemiBold, lineHeight = readingType.storyLine, fontFamily = Reading)
                if (story.text.isNotEmpty()) Text(story.text, color = TextMain, fontSize = readingType.body, lineHeight = readingType.bodyLine, fontFamily = Reading)
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

/**
 * Шрифт статей — Source Serif 4 (Adobe, лицензия OFL — `assets/licenses/source-serif-4-OFL.txt`): спокойный книжный
 * шрифт с засечками, длинный текст читается легче. Файл переменный — жирность и оптический размер задаются
 * осями: текст — 400 при размере ~20 pt, заголовки — 600 при ~30 pt (у крупного начертания штрихи тоньше
 * и плотнее). Подписи, источники и кнопки — прежним шрифтом, чтобы служебное не путалось со статьёй.
 */
@OptIn(ExperimentalTextApi::class)
private val Reading = FontFamily(
    Font(R.font.source_serif_4, FontWeight.Normal, variationSettings = FontVariation.Settings(FontVariation.weight(400), FontVariation.Setting("opsz", 20f))),
    Font(R.font.source_serif_4, FontWeight.SemiBold, variationSettings = FontVariation.Settings(FontVariation.weight(600), FontVariation.Setting("opsz", 30f))),
)

/** Размеры текста статьи: на планшете — крупно, на телефоне (уже 600 dp) — чтобы заголовок не занимал пол-экрана. */
private class ReadingType(val title: TextUnit, val titleLine: TextUnit, val story: TextUnit, val storyLine: TextUnit, val body: TextUnit, val bodyLine: TextUnit)

private val WideReading = ReadingType(32.sp, 40.sp, 23.sp, 30.sp, 20.sp, 31.sp)
private val NarrowReading = ReadingType(24.sp, 31.sp, 20.sp, 27.sp, 18.sp, 28.sp)

private val readingType: ReadingType
    @Composable get() = if (LocalConfiguration.current.screenWidthDp >= 600) WideReading else NarrowReading
