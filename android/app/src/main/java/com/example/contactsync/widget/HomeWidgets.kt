package com.example.contactsync.widget

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import androidx.compose.runtime.Composable
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.action.ActionParameters
import androidx.glance.action.clickable
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.appwidget.action.ActionCallback
import androidx.glance.appwidget.action.actionRunCallback
import androidx.glance.appwidget.action.actionStartActivity
import androidx.glance.appwidget.cornerRadius
import androidx.glance.appwidget.lazy.LazyColumn
import androidx.glance.appwidget.lazy.items
import androidx.glance.appwidget.provideContent
import androidx.glance.appwidget.updateAll
import androidx.glance.background
import androidx.glance.layout.Alignment
import androidx.glance.layout.Box
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxSize
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.width
import androidx.glance.text.FontFamily
import androidx.glance.text.FontWeight
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import com.example.contactsync.App
import com.example.contactsync.calendar.CalendarAccount
import com.example.contactsync.data.UnauthorizedException
import com.example.contactsync.launcher.Agenda
import com.example.contactsync.launcher.AgendaRow
import com.example.contactsync.launcher.CalendarSource
import com.example.contactsync.launcher.NewsFeed
import com.example.contactsync.launcher.NewsItem
import com.example.contactsync.launcher.NewsSource
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.delay
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.ZoneId

/*
 * Виджеты для обычного рабочего стола — на устройствах, где главный экран свой, а не sync:
 * «Ближайшее» (события календаря) и «Новости» (с сервера sync). Обновляются раз в 30 минут
 * (минимум, который Android даёт виджетам) и по нажатию на заголовок.
 */

private val WidgetBackground = Color(0xF0171A20)
private val Main = ColorProvider(Color(0xFFECEEF2))
private val Muted = ColorProvider(Color(0xFF8B93A1))
private val AccentColor = ColorProvider(Color(0xFF8AB4F8))

/**
 * Рамка виджета: нажатие на заголовок и на свободное место — [onOpen] (календарь на месяц, экран новостей),
 * «↻» — [onRefresh], [action] справа — своё действие.
 */
@Composable
private fun WidgetFrame(
    title: String,
    action: String?,
    onOpen: androidx.glance.action.Action,
    onRefresh: androidx.glance.action.Action,
    onAction: androidx.glance.action.Action?,
    content: @Composable () -> Unit,
) {
    Column(GlanceModifier.fillMaxSize().background(WidgetBackground).cornerRadius(20.dp).padding(14.dp).clickable(onOpen)) {
        Row(GlanceModifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(
                title,
                style = TextStyle(color = Main, fontSize = 18.sp, fontWeight = FontWeight.Medium),
                modifier = GlanceModifier.defaultWeight().clickable(onOpen),
            )
            Text("↻", style = TextStyle(color = Muted, fontSize = 18.sp), modifier = GlanceModifier.clickable(onRefresh).padding(horizontal = 8.dp))
            if (action != null && onAction != null) {
                Text(action, style = TextStyle(color = AccentColor, fontSize = 15.sp), modifier = GlanceModifier.clickable(onAction).padding(start = 4.dp))
            }
        }
        Spacer(GlanceModifier.height(8.dp))
        content()
    }
}

@Composable
private fun Message(text: String) {
    Text(text, style = TextStyle(color = Muted, fontSize = 14.sp))
}

/* ---------- Ближайшее ---------- */

class AgendaWidget : GlanceAppWidget() {

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val allowed = context.checkSelfPermission(Manifest.permission.READ_CALENDAR) == PackageManager.PERMISSION_GRANTED
        val rows = if (!allowed) {
            null
        } else {
            withContext(Dispatchers.IO) {
                Agenda.rows(CalendarSource(context).upcoming(), Instant.now(), ZoneId.systemDefault(), limit = 20)
            }
        }
        provideContent {
            WidgetFrame(
                title = "Ближайшее",
                action = "+ Событие",
                onOpen = actionStartActivity(CalendarActionActivity.openCalendar(context)),
                onRefresh = actionRunCallback<RefreshAgenda>(),
                onAction = actionStartActivity(CalendarActionActivity.newEvent(context)),
            ) {
                when {
                    rows == null -> Message("Нет доступа к календарю — откройте sync и разрешите")
                    rows.isEmpty() -> Message("На две недели вперёд событий нет")
                    else -> LazyColumn {
                        items(rows, itemId = { it.eventId * 31 + it.begin }) { row -> AgendaWidgetRow(context, row) }
                    }
                }
            }
        }
    }
}

@Composable
private fun AgendaWidgetRow(context: Context, row: AgendaRow) {
    Row(
        GlanceModifier.fillMaxWidth().padding(vertical = 6.dp).clickable(actionStartActivity(CalendarActionActivity.openEvent(context, row))),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(GlanceModifier.width(4.dp).height(36.dp).background(Color(row.color or 0xFF000000.toInt())).cornerRadius(2.dp)) {}
        Spacer(GlanceModifier.width(10.dp))
        Column(GlanceModifier.defaultWeight()) {
            Text(row.title.ifEmpty { "Без названия" }, style = TextStyle(color = Main, fontSize = 15.sp), maxLines = 1)
            Text(listOfNotNull(row.time, row.soon).joinToString(" · "), style = TextStyle(color = Muted, fontSize = 13.sp), maxLines = 1)
        }
        Text(row.day, style = TextStyle(color = Muted, fontSize = 13.sp))
    }
}

/**
 * «↻» в «Ближайшем»: синхронизировать календари sync с сервером и перечитать события.
 * На устройстве без лаунчера sync иначе синхронизация идёт раз в час (и после правок в календаре).
 */
class RefreshAgenda : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        CalendarAccount.requestSync(context)
        AgendaWidget().updateAll(context)
        // Синхронизация идёт в фоне несколько секунд — перечитываем ещё раз, когда она, скорее всего, закончилась.
        delay(SYNC_SETTLE_MS)
        AgendaWidget().updateAll(context)
    }

    private companion object {
        const val SYNC_SETTLE_MS = 8_000L
    }
}

class AgendaWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = AgendaWidget()
}

/* ---------- Новости ---------- */

/** Что показать в виджете новостей: последняя сводка и лента «Все», или текст ошибки. */
private data class NewsWidgetData(
    val digest: NewsItem? = null,
    val items: List<NewsItem> = emptyList(),
    val sources: List<NewsSource> = emptyList(),
    val error: String? = null,
)

class NewsWidget : GlanceAppWidget() {

    override suspend fun provideGlance(context: Context, id: GlanceId) {
        val data = load(context)
        val zone = ZoneId.systemDefault()
        val now = Instant.now()
        provideContent {
            WidgetFrame(
                title = "Новости",
                action = null,
                onOpen = actionStartActivity(NewsActivity.intent(context, source = "")),
                onRefresh = actionRunCallback<RefreshNews>(),
                onAction = null,
            ) {
                if (data.error != null) {
                    Message(data.error)
                } else {
                    LazyColumn {
                        data.digest?.let { digest ->
                            item(itemId = -1L) {
                                NewsWidgetRow(
                                    title = digest.title,
                                    caption = "Сводка · " + NewsFeed.caption(digest, data.sources, now, zone).substringAfter(" · "),
                                    accent = true,
                                    onClick = actionStartActivity(NewsActivity.intent(context, source = NewsFeed.DIGEST)),
                                )
                            }
                        }
                        items(data.items, itemId = { it.id }) { item ->
                            NewsWidgetRow(
                                title = item.title,
                                caption = NewsFeed.caption(item, data.sources, now, zone),
                                accent = false,
                                onClick = actionStartActivity(NewsActivity.intent(context, newsId = item.id)),
                            )
                        }
                    }
                }
            }
        }
    }

    private suspend fun load(context: Context): NewsWidgetData = withContext(Dispatchers.IO) {
        val api = (context.applicationContext as App).api
        try {
            val sources = api.newsSources().map(NewsFeed::source)
            val digest = api.news(NewsFeed.DIGEST, 1).items.firstOrNull()?.let(NewsFeed::item)
            NewsWidgetData(digest, api.news(null, NEWS_LIMIT).items.map(NewsFeed::item), sources)
        } catch (e: CancellationException) {
            throw e
        } catch (e: UnauthorizedException) {
            NewsWidgetData(error = "Войдите в sync, чтобы видеть новости")
        } catch (e: Exception) {
            NewsWidgetData(error = "Сервер новостей не ответил — нажмите ↻, чтобы повторить")
        }
    }

    private companion object {
        const val NEWS_LIMIT = 30
    }
}

@Composable
private fun NewsWidgetRow(title: String, caption: String, accent: Boolean, onClick: androidx.glance.action.Action) {
    Column(GlanceModifier.fillMaxWidth().padding(vertical = 6.dp).clickable(onClick)) {
        // Заголовки новостей — шрифтом с засечками, как статьи на экране чтения.
        Text(title, style = TextStyle(color = if (accent) AccentColor else Main, fontSize = 16.sp, fontFamily = FontFamily.Serif), maxLines = 3)
        Text(caption, style = TextStyle(color = Muted, fontSize = 12.sp), maxLines = 1)
    }
}

class RefreshNews : ActionCallback {
    override suspend fun onAction(context: Context, glanceId: GlanceId, parameters: ActionParameters) {
        NewsWidget().updateAll(context)
    }
}

class NewsWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = NewsWidget()
}
