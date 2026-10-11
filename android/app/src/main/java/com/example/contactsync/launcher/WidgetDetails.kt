package com.example.contactsync.launcher

import androidx.activity.compose.BackHandler
import androidx.compose.animation.core.Animatable
import androidx.compose.animation.core.FastOutSlowInEasing
import androidx.compose.animation.core.tween
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.RowScope
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.itemsIndexed
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.draw.drawWithContent
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Rect
import androidx.compose.ui.geometry.RoundRect
import androidx.compose.ui.geometry.lerp
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.drawscope.clipPath
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.contactsync.kb.openUrl
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import kotlin.math.abs

/** Виджеты дашборда, которые раскрываются на весь экран. */
enum class LauncherWidget { WEATHER, AGENDA, QUOTES, NEWS }

private const val EXPAND_MS = 500

/**
 * Раскрытие виджета: рамка карточки за 0,5 с растягивается до всего экрана, подробности проявляются
 * следом. Анимируется только отрисовка (обрезка по рамке и прозрачность) — подробный экран
 * раскладывается один раз в полный размер, а не пересчитывается на каждом кадре: на планшете
 * это заметно дороже.
 */
@Composable
internal fun ExpandedWidget(from: Rect, onClosed: () -> Unit, content: @Composable (close: () -> Unit) -> Unit) {
    val progress = remember { Animatable(0f) }
    val scope = rememberCoroutineScope()
    var closing by remember { mutableStateOf(false) }
    val close: () -> Unit = {
        if (!closing) {
            closing = true
            scope.launch {
                progress.animateTo(0f, tween(EXPAND_MS, easing = FastOutSlowInEasing))
                onClosed()
            }
        }
    }
    LaunchedEffect(Unit) { progress.animateTo(1f, tween(EXPAND_MS, easing = FastOutSlowInEasing)) }
    BackHandler(onBack = close)

    Box(
        Modifier
            .fillMaxSize()
            // Нажатия не должны проваливаться в дашборд под раскрытым виджетом.
            .pointerInput(Unit) { detectTapGestures { } }
            .drawWithContent {
                val p = progress.value
                drawRect(Color.Black.copy(alpha = 0.55f * p))
                val rect = lerp(from, Rect(Offset.Zero, size), p)
                val radius = 28.dp.toPx() * (1 - p)
                val shape = Path().apply { addRoundRect(RoundRect(rect, CornerRadius(radius))) }
                clipPath(shape) {
                    drawRect(Background, rect.topLeft, rect.size)
                    this@drawWithContent.drawContent()
                }
            },
    ) {
        Box(
            Modifier
                .fillMaxSize()
                .graphicsLayer { alpha = ((progress.value - 0.3f) / 0.7f).coerceIn(0f, 1f) }
                .safeDrawingPadding()
                .padding(horizontal = 32.dp, vertical = 24.dp),
        ) {
            content(close)
        }
    }
}

/** Заголовок подробного экрана: название, действие и «закрыть». */
@Composable
private fun DetailHeader(title: String, subtitle: String?, onClose: () -> Unit, actions: @Composable RowScope.() -> Unit = {}) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(title, color = TextMain, fontSize = 34.sp, fontWeight = FontWeight.Medium)
            if (subtitle != null) Text(subtitle, color = TextMuted, fontSize = 15.sp)
        }
        actions()
        Spacer(Modifier.width(12.dp))
        Box(
            Modifier.size(52.dp).clip(CircleShape).background(CardRaised).clickable(onClick = onClose),
            contentAlignment = Alignment.Center,
        ) {
            Text("✕", color = TextMain, fontSize = 22.sp)
        }
    }
    Spacer(Modifier.height(20.dp))
}

@Composable
private fun DetailSection(title: String, modifier: Modifier = Modifier, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.clip(RoundedCornerShape(24.dp)).background(CardColor).padding(20.dp)) {
        Text(title, color = TextMuted, fontSize = 15.sp)
        Spacer(Modifier.height(12.dp))
        content()
    }
}

/* ---------- Погода ---------- */

@Composable
internal fun WeatherDetail(state: LauncherState, onCity: () -> Unit, onClose: () -> Unit) {
    val weather = state.weather
    Column(Modifier.fillMaxSize()) {
        DetailHeader(
            title = "Погода",
            subtitle = state.city.name,
            onClose = onClose,
            actions = { TextButton(onClick = onCity) { Text("Сменить город", color = Accent) } },
        )
        if (weather == null) {
            Text("загрузка…", color = TextMuted, fontSize = 18.sp)
            return
        }
        Column(Modifier.verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(20.dp)) {
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(weather.condition.symbol, fontSize = 72.sp)
                Spacer(Modifier.width(20.dp))
                Column {
                    Text(temperature(weather.temperature), color = TextMain, fontSize = 72.sp, fontWeight = FontWeight.Light)
                    Text(
                        "${weather.condition.title} · ветер ${Math.round(weather.wind)} м/с",
                        color = TextMuted,
                        fontSize = 18.sp,
                    )
                }
            }

            DetailSection("Сутки по часам") {
                LazyRow(horizontalArrangement = Arrangement.spacedBy(10.dp)) {
                    items(weather.hourly) { hour ->
                        Column(
                            Modifier.width(76.dp).clip(RoundedCornerShape(16.dp)).background(CardRaised).padding(vertical = 12.dp),
                            horizontalAlignment = Alignment.CenterHorizontally,
                            verticalArrangement = Arrangement.spacedBy(4.dp),
                        ) {
                            Text(hour.time.format(HOUR), color = TextMuted, fontSize = 14.sp)
                            Text(hour.condition.symbol, fontSize = 24.sp)
                            Text(temperature(hour.temperature), color = TextMain, fontSize = 18.sp)
                            Text("${Math.round(hour.wind)} м/с", color = TextMuted, fontSize = 13.sp)
                            Text(
                                hour.precipitation?.takeIf { it > 0 }?.let { "${number(it, 1)} мм" } ?: "",
                                color = Accent,
                                fontSize = 13.sp,
                            )
                        }
                    }
                }
            }

            DetailSection("По дням") {
                val coldest = weather.days.minOfOrNull { it.min } ?: 0.0
                val warmest = weather.days.maxOfOrNull { it.max } ?: 0.0
                weather.days.forEach { day ->
                    Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
                        Text(
                            day.date.format(DAY).replaceFirstChar { it.uppercase() },
                            color = TextMain,
                            fontSize = 18.sp,
                            modifier = Modifier.width(240.dp),
                            maxLines = 1,
                        )
                        Text(day.condition.symbol, fontSize = 24.sp, modifier = Modifier.width(44.dp))
                        Text(day.condition.title, color = TextMuted, fontSize = 15.sp, modifier = Modifier.width(180.dp), maxLines = 1)
                        Text(temperature(day.min), color = TextMuted, fontSize = 17.sp, modifier = Modifier.width(56.dp))
                        TemperatureBar(day.min, day.max, coldest, warmest, Modifier.weight(1f))
                        Text(temperature(day.max), color = TextMain, fontSize = 17.sp, modifier = Modifier.width(64.dp).padding(start = 12.dp))
                        Text("${Math.round(day.maxWind)} м/с", color = TextMuted, fontSize = 15.sp, modifier = Modifier.width(70.dp))
                    }
                }
            }
            Text("Прогноз MET Norway", color = TextMuted, fontSize = 13.sp)
        }
    }
}

/** Полоса дня между самым холодным и самым тёплым днём прогноза. */
@Composable
private fun TemperatureBar(min: Double, max: Double, coldest: Double, warmest: Double, modifier: Modifier) {
    val span = (warmest - coldest).takeIf { it > 0 } ?: 1.0
    Box(modifier.height(8.dp).clip(RoundedCornerShape(4.dp)).background(CardRaised).drawWithContent {
        val start = ((min - coldest) / span).toFloat() * size.width
        val end = ((max - coldest) / span).toFloat() * size.width
        drawRoundRect(Amber, Offset(start, 0f), androidx.compose.ui.geometry.Size((end - start).coerceAtLeast(size.height), size.height),
            CornerRadius(size.height / 2))
    })
}

/* ---------- События ---------- */

@Composable
internal fun AgendaDetail(state: LauncherState, onClose: () -> Unit) {
    val context = LocalContext.current
    val zone = ZoneId.systemDefault()
    val today = LocalDate.now(zone)
    Column(Modifier.fillMaxSize()) {
        DetailHeader(
            title = "Ближайшее",
            subtitle = "события на две недели",
            onClose = onClose,
            actions = { TextButton(onClick = { context.newEvent() }) { Text("+ Событие", color = Accent) } },
        )
        when {
            !state.calendarAllowed -> Text("Нужен доступ к календарю — разрешите его в карточке «Ближайшее».", color = TextMuted, fontSize = 18.sp)
            state.agenda.isEmpty() -> Text("Ближайшие две недели свободны", color = TextMuted, fontSize = 18.sp)
            else -> LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                state.agenda.groupBy { it.date }.forEach { (date, rows) ->
                    item(key = "day-$date") {
                        Text(
                            dayTitle(date, today),
                            color = TextMuted,
                            fontSize = 16.sp,
                            fontWeight = FontWeight.Medium,
                            modifier = Modifier.padding(top = 12.dp, bottom = 4.dp),
                        )
                    }
                    items(rows, key = { "${it.eventId}-${it.begin}" }) { row -> AgendaDetailRow(row, onClick = { context.openEvent(row) }) }
                }
            }
        }
    }
}

@Composable
private fun AgendaDetailRow(row: AgendaRow, onClick: () -> Unit) {
    val eventColor = Color(row.color).copy(alpha = 1f)
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(if (row.soon != null) eventColor.copy(alpha = 0.18f) else CardColor)
            .clickable(onClick = onClick)
            .padding(horizontal = 20.dp, vertical = 16.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Text(row.time, color = TextMain, fontSize = 18.sp, modifier = Modifier.width(150.dp))
        Box(Modifier.width(5.dp).height(44.dp).clip(RoundedCornerShape(3.dp)).background(eventColor))
        Spacer(Modifier.width(18.dp))
        Column(Modifier.weight(1f)) {
            Text(row.title, color = TextMain, fontSize = 20.sp, maxLines = 3, overflow = TextOverflow.Ellipsis)
            val details = listOfNotNull(row.soon, row.location.takeIf { it.isNotBlank() }?.let { "📍 $it" })
            if (details.isNotEmpty()) {
                Text(details.joinToString(" · "), color = if (row.soon != null) eventColor else TextMuted, fontSize = 15.sp)
            }
        }
    }
}

private fun dayTitle(date: LocalDate?, today: LocalDate): String {
    if (date == null) return ""
    val full = date.format(DateTimeFormatter.ofPattern("EEEE, d MMMM", RU))
    return when (date) {
        today -> "Сегодня · $full"
        today.plusDays(1) -> "Завтра · $full"
        else -> full.replaceFirstChar { it.uppercase() }
    }
}

/* ---------- Мосбиржа ---------- */

@OptIn(ExperimentalFoundationApi::class)
@Composable
internal fun QuotesDetail(state: LauncherState, vm: LauncherViewModel, onAdd: () -> Unit, onClose: () -> Unit) {
    val updated = if (state.quotesAt > 0) {
        "обновлено " + Instant.ofEpochMilli(state.quotesAt).atZone(ZoneId.systemDefault()).format(HOUR) + " · задержка 15 мин"
    } else {
        "загрузка…"
    }
    Column(Modifier.fillMaxSize()) {
        DetailHeader(
            title = "Мосбиржа",
            subtitle = if (state.offline) "нет связи · данные могут быть устаревшими" else updated,
            onClose = onClose,
            actions = { TextButton(onClick = onAdd) { Text("Добавить", color = Accent) } },
        )
        BoxWithConstraints(Modifier.fillMaxSize()) {
            // На узком экране — без открытия и оборота, чтобы цифры не налезали друг на друга.
            val wide = maxWidth >= 900.dp
            Column {
                Row(Modifier.fillMaxWidth().padding(horizontal = 20.dp, vertical = 8.dp)) {
                    HeaderCell("Инструмент", 2.4f, Alignment.Start)
                    HeaderCell("Цена", 1.3f)
                    HeaderCell("Изм.", 1f)
                    if (wide) HeaderCell("Открытие", 1.2f)
                    HeaderCell("Мин – макс", 2.3f)
                    if (wide) HeaderCell("Оборот", 1.4f)
                    HeaderCell("Сделка", 0.9f)
                }
                LazyColumn(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                    items(state.quotes, key = { it.secid }) { quote ->
                        var menu by remember { mutableStateOf(false) }
                        Box {
                            Row(
                                Modifier
                                    .fillMaxWidth()
                                    .clip(RoundedCornerShape(18.dp))
                                    .background(CardColor)
                                    .combinedClickable(onClick = {}, onLongClick = { menu = true })
                                    .padding(horizontal = 20.dp, vertical = 14.dp),
                                verticalAlignment = Alignment.CenterVertically,
                            ) {
                                Column(Modifier.weight(2.4f)) {
                                    Text(quote.name, color = TextMain, fontSize = 19.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                                    Text(
                                        listOfNotNull(quote.secid, quote.yield?.let { "доходность ${number(it, 2)}%" }).joinToString(" · "),
                                        color = TextMuted,
                                        fontSize = 14.sp,
                                    )
                                }
                                Cell(price(quote), 1.3f, TextMain, 19)
                                val change = quote.changePercent
                                Cell(
                                    change?.let { (if (it > 0) "+" else if (it < 0) "−" else "") + number(abs(it), 2) + "%" } ?: "—",
                                    1f,
                                    when {
                                        change == null || change == 0.0 -> TextMuted
                                        change > 0 -> Up
                                        else -> Down
                                    },
                                )
                                if (wide) Cell(quote.open?.let { number(it, quote.decimals) } ?: "—", 1.2f)
                                Cell(
                                    if (quote.low != null && quote.high != null) {
                                        "${number(quote.low, quote.decimals)} – ${number(quote.high, quote.decimals)}"
                                    } else {
                                        "—"
                                    },
                                    2.3f,
                                )
                                if (wide) Cell(quote.turnover?.let(::turnover) ?: "—", 1.4f)
                                Cell(quote.lastTradeTime?.take(5) ?: "—", 0.9f, TextMuted)
                            }
                            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                                DropdownMenuItem(
                                    text = { Text("Убрать с экрана") },
                                    onClick = { menu = false; vm.removeInstrument(quote.secid) },
                                )
                            }
                        }
                    }
                }
            }
        }
    }
}

@Composable
private fun RowScope.HeaderCell(text: String, weight: Float, align: Alignment.Horizontal = Alignment.End) {
    Box(Modifier.weight(weight), contentAlignment = if (align == Alignment.Start) Alignment.CenterStart else Alignment.CenterEnd) {
        Text(text, color = TextMuted, fontSize = 14.sp)
    }
}

@Composable
private fun RowScope.Cell(text: String, weight: Float, color: Color = TextMain, size: Int = 17) {
    Box(Modifier.weight(weight), contentAlignment = Alignment.CenterEnd) {
        Text(text, color = color, fontSize = size.sp, maxLines = 1)
    }
}

/** Оборот в рублях: «2,57 млрд ₽», «170,7 млн ₽». */
private fun turnover(value: Double): String = when {
    value >= 1e9 -> number(value / 1e9, 2) + " млрд ₽"
    value >= 1e6 -> number(value / 1e6, 1) + " млн ₽"
    value >= 1e3 -> number(value / 1e3, 0) + " тыс ₽"
    else -> number(value, 0) + " ₽"
}

/* ---------- Новости ---------- */

@Composable
internal fun NewsDetail(state: LauncherState, vm: LauncherViewModel, onClose: () -> Unit) {
    val context = LocalContext.current
    val zone = ZoneId.systemDefault()
    val now = Instant.now()
    val items = state.news.items
    Column(Modifier.fillMaxSize()) {
        DetailHeader(
            title = "Новости",
            subtitle = state.newsError ?: state.newsSources.joinToString(", ") { it.title },
            onClose = onClose,
        )
        NewsTabs(state, vm, Arrangement.spacedBy(10.dp))
        Spacer(Modifier.height(16.dp))
        if (state.newsSource == NewsFeed.DIGEST) {
            DigestList(state, vm)
            return@Column
        }
        LazyVerticalGrid(
            columns = GridCells.Adaptive(minSize = 420.dp),
            horizontalArrangement = Arrangement.spacedBy(14.dp),
            verticalArrangement = Arrangement.spacedBy(14.dp),
            modifier = Modifier.fillMaxHeight(),
        ) {
            itemsIndexed(items, key = { _, item -> item.id }) { index, item ->
                // Долистали до конца — догружаем следующую страницу из базы сервера.
                if (index == items.lastIndex) LaunchedEffect(item.id) { vm.loadMoreNews() }
                Column(
                    Modifier
                        .fillMaxWidth()
                        .clip(RoundedCornerShape(20.dp))
                        .background(CardColor)
                        .clickable { context.openUrl(item.link) }
                        .padding(18.dp),
                    verticalArrangement = Arrangement.spacedBy(6.dp),
                ) {
                    Text(NewsFeed.caption(item, state.newsSources, now, zone), color = Accent, fontSize = 14.sp)
                    Text(item.title, color = TextMain, fontSize = 19.sp, fontWeight = FontWeight.Medium, maxLines = 4, overflow = TextOverflow.Ellipsis)
                    if (item.summary.isNotEmpty()) {
                        Text(item.summary, color = TextMuted, fontSize = 15.sp, maxLines = 6, overflow = TextOverflow.Ellipsis)
                    }
                }
            }
        }
    }
}

/** Сводки: каждая — период и сюжеты, под сюжетом — издания, нажатие открывает исходную статью. */
@Composable
private fun DigestList(state: LauncherState, vm: LauncherViewModel) {
    val context = LocalContext.current
    val zone = ZoneId.systemDefault()
    val items = state.news.items
    if (items.isEmpty()) {
        Text(
            state.newsError ?: "Сводок пока нет: первая появится в начале следующего часа",
            color = TextMuted,
            fontSize = 17.sp,
        )
        return
    }
    LazyColumn(verticalArrangement = Arrangement.spacedBy(14.dp), modifier = Modifier.fillMaxHeight()) {
        itemsIndexed(items, key = { _, item -> item.id }) { index, item ->
            if (index == items.lastIndex) LaunchedEffect(item.id) { vm.loadMoreNews() }
            val digest = item.digest ?: return@itemsIndexed
            Column(
                Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(CardColor).padding(20.dp),
                verticalArrangement = Arrangement.spacedBy(12.dp),
            ) {
                Text("${NewsFeed.period(digest, zone)} · новостей ${digest.newsCount}", color = Accent, fontSize = 14.sp)
                Text(item.title, color = TextMain, fontSize = 22.sp, fontWeight = FontWeight.Medium)
                digest.stories.forEach { story ->
                    Column(verticalArrangement = Arrangement.spacedBy(4.dp)) {
                        Text(story.title, color = TextMain, fontSize = 18.sp, fontWeight = FontWeight.Medium)
                        if (story.text.isNotEmpty()) Text(story.text, color = TextMuted, fontSize = 15.sp)
                        if (story.sources.isNotEmpty()) {
                            Row(
                                Modifier.horizontalScroll(rememberScrollState()),
                                horizontalArrangement = Arrangement.spacedBy(8.dp),
                            ) {
                                story.sources.forEach { source ->
                                    val title = state.newsSources.firstOrNull { it.code == source.source }?.title ?: source.source
                                    Text(
                                        title,
                                        color = Accent,
                                        fontSize = 13.sp,
                                        modifier = Modifier
                                            .clip(RoundedCornerShape(10.dp))
                                            .background(CardRaised)
                                            .clickable { context.openUrl(source.link) }
                                            .padding(horizontal = 10.dp, vertical = 5.dp),
                                    )
                                }
                            }
                        }
                    }
                }
            }
        }
    }
}

private val HOUR = DateTimeFormatter.ofPattern("HH:mm")
private val DAY = DateTimeFormatter.ofPattern("EEEE, d MMM", RU)
