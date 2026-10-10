package com.example.contactsync.launcher

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.provider.CalendarContract
import android.provider.Settings
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.detectVerticalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.heightIn
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.pager.HorizontalPager
import androidx.compose.foundation.pager.PagerState
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.ModalBottomSheet
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.rememberModalBottomSheetState
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.contactsync.R
import com.example.contactsync.kb.KbCard
import com.example.contactsync.kb.KbState
import com.example.contactsync.kb.KbViewModel
import com.example.contactsync.kb.KbWebPage
import com.example.contactsync.kb.openUrl
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.text.NumberFormat
import java.time.LocalDateTime
import java.time.format.DateTimeFormatter
import kotlin.math.abs

/**
 * Главный экран: два экрана свайпом — дашборд и база знаний; док с кнопками навигации — общий.
 * Со второго экрана назад листается кнопкой «← Главная» или «Домой»: свайп там отдан веб-странице KB.
 */
@OptIn(ExperimentalFoundationApi::class)
@Composable
fun LauncherScreen(
    state: LauncherState,
    vm: LauncherViewModel,
    kb: KbState,
    kbVm: KbViewModel,
    kbCookies: () -> List<String>,
    pager: PagerState,
) {
    var drawerOpen by remember { mutableStateOf(false) }
    var cityDialog by remember { mutableStateOf(false) }
    var instrumentDialog by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val toHome: () -> Unit = {
        drawerOpen = false
        scope.launch { pager.animateScrollToPage(0) }
    }
    BackHandler(enabled = pager.currentPage != 0) { toHome() }

    MaterialTheme(colorScheme = LauncherColors) {
        Column(
            Modifier
                .fillMaxSize()
                .background(Background)
                .safeDrawingPadding()
                .padding(horizontal = 32.dp, vertical = 24.dp),
        ) {
            HorizontalPager(
                state = pager,
                userScrollEnabled = pager.currentPage == 0,
                beyondViewportPageCount = 1,
                modifier = Modifier.weight(1f),
            ) { page ->
                if (page == 0) {
                    Dashboard(state, vm, kb, kbVm, onAllApps = { drawerOpen = true }, onCity = { cityDialog = true },
                        onAddInstrument = { instrumentDialog = true })
                } else {
                    KbWebPage(kb, kbVm, kbCookies(), onHome = toHome, modifier = Modifier.fillMaxSize())
                }
            }
            PageDots(pager.currentPage, pager.pageCount)
            Dock(
                state.dock,
                onAllApps = { drawerOpen = true },
                onBack = { if (drawerOpen) drawerOpen = false else toHome() },
                onHome = toHome,
            )
        }

        if (drawerOpen) AppDrawer(state, vm, onDismiss = { drawerOpen = false })
        if (cityDialog) CityDialog(vm, onDismiss = { cityDialog = false })
        if (instrumentDialog) InstrumentDialog(vm, onDismiss = { instrumentDialog = false })
    }
}

@Composable
private fun Dashboard(
    state: LauncherState,
    vm: LauncherViewModel,
    kb: KbState,
    kbVm: KbViewModel,
    onAllApps: () -> Unit,
    onCity: () -> Unit,
    onAddInstrument: () -> Unit,
) {
    val swipeThreshold = with(LocalDensity.current) { 80.dp.toPx() }
    BoxWithConstraints(
        Modifier
            .fillMaxSize()
            .pointerInput(Unit) {
                // Свайп вверх по дашборду открывает все приложения, как в обычных лаунчерах.
                var dragged = 0f
                detectVerticalDragGestures(
                    onDragStart = { dragged = 0f },
                    onVerticalDrag = { _, dy -> dragged += dy },
                    onDragEnd = { if (dragged < -swipeThreshold) onAllApps() },
                )
            },
    ) {
        val wide = maxWidth >= 700.dp
        Column(Modifier.fillMaxSize(), verticalArrangement = Arrangement.spacedBy(20.dp)) {
            Header(state, onCityClick = onCity)
            SearchBar()
            state.weather?.let { WeatherStrip(it) }
            if (wide) {
                Row(Modifier.weight(1f), horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                    Column(Modifier.weight(1.15f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                        AgendaCard(state, vm, Modifier.weight(0.8f).fillMaxWidth())
                        KbCard(kb, kbVm, Modifier.weight(1.2f).fillMaxWidth())
                    }
                    QuotesCard(state, vm, onAdd = onAddInstrument, Modifier.weight(1f).fillMaxHeight())
                }
            } else {
                Column(
                    Modifier.weight(1f).verticalScroll(rememberScrollState()),
                    verticalArrangement = Arrangement.spacedBy(20.dp),
                ) {
                    AgendaCard(state, vm, Modifier.fillMaxWidth().heightIn(min = 240.dp))
                    KbCard(kb, kbVm, Modifier.fillMaxWidth().height(420.dp))
                    QuotesCard(state, vm, onAdd = onAddInstrument, Modifier.fillMaxWidth().height(420.dp))
                }
            }
        }
    }
}

@Composable
private fun PageDots(current: Int, count: Int) {
    Row(Modifier.fillMaxWidth().padding(vertical = 10.dp), horizontalArrangement = Arrangement.Center) {
        repeat(count) {
            Box(
                Modifier
                    .padding(horizontal = 4.dp)
                    .size(8.dp)
                    .clip(CircleShape)
                    .background(if (it == current) TextMain else CardRaised),
            )
        }
    }
}

/* ---------- Поиск Яндекса ---------- */

/** Строка поиска: подсказки при вводе, поиск — в браузере по умолчанию. */
@Composable
private fun SearchBar() {
    val context = LocalContext.current
    val focus = LocalFocusManager.current
    var query by remember { mutableStateOf("") }
    var suggestions by remember { mutableStateOf(emptyList<String>()) }

    LaunchedEffect(query) {
        if (query.isBlank()) {
            suggestions = emptyList()
            return@LaunchedEffect
        }
        delay(250)
        suggestions = try {
            YandexSearch.suggestions(query.trim())
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            emptyList()
        }
    }

    fun search(text: String) {
        if (text.isBlank()) return
        context.openUrl(YandexSearch.searchUrl(text))
        query = ""
        focus.clearFocus()
    }

    Column(Modifier.fillMaxWidth().clip(RoundedCornerShape(20.dp)).background(CardRaised)) {
        Row(Modifier.padding(horizontal = 18.dp, vertical = 14.dp), verticalAlignment = Alignment.CenterVertically) {
            Text("Я", color = YandexRed, fontSize = 24.sp, fontWeight = FontWeight.Bold)
            Spacer(Modifier.width(14.dp))
            BasicTextField(
                value = query,
                onValueChange = { query = it },
                singleLine = true,
                textStyle = TextStyle(color = TextMain, fontSize = 20.sp),
                cursorBrush = SolidColor(Accent),
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                keyboardActions = KeyboardActions(onSearch = { search(query) }),
                modifier = Modifier.weight(1f),
                decorationBox = { inner ->
                    if (query.isEmpty()) Text("Найти в Яндексе", color = TextMuted, fontSize = 20.sp)
                    inner()
                },
            )
            if (query.isNotBlank()) {
                Text("Найти", color = Accent, fontSize = 18.sp, modifier = Modifier.clip(RoundedCornerShape(10.dp))
                    .clickable { search(query) }.padding(horizontal = 10.dp, vertical = 4.dp))
            }
        }
        suggestions.forEach { suggestion ->
            Text(
                suggestion,
                color = TextMain,
                fontSize = 17.sp,
                modifier = Modifier.fillMaxWidth().clickable { search(suggestion) }.padding(horizontal = 56.dp, vertical = 10.dp),
            )
        }
    }
}

/* ---------- Часы и погода ---------- */

@Composable
private fun Header(state: LauncherState, onCityClick: () -> Unit) {
    val now by produceState(LocalDateTime.now()) {
        while (true) {
            value = LocalDateTime.now()
            // Просыпаемся в начале следующей минуты, чтобы часы не отставали.
            delay(60_000L - System.currentTimeMillis() % 60_000L)
        }
    }
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.Bottom) {
        Column(Modifier.weight(1f)) {
            Text(
                now.format(DateTimeFormatter.ofPattern("HH:mm")),
                color = TextMain,
                fontSize = 112.sp,
                fontWeight = FontWeight.Thin,
                lineHeight = 112.sp,
            )
            Text(
                now.format(DateTimeFormatter.ofPattern("EEEE, d MMMM", RU)).replaceFirstChar { it.uppercase() },
                color = TextMuted,
                fontSize = 22.sp,
            )
        }
        Column(
            horizontalAlignment = Alignment.End,
            modifier = Modifier.clip(RoundedCornerShape(16.dp)).clickable(onClick = onCityClick).padding(8.dp),
        ) {
            VpnIndicator(state)
            val weather = state.weather
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(weather?.condition?.symbol ?: "", fontSize = 44.sp)
                Spacer(Modifier.width(12.dp))
                Text(weather?.let { temperature(it.temperature) } ?: "—", color = TextMain, fontSize = 56.sp, fontWeight = FontWeight.Light)
            }
            Text(
                listOfNotNull(
                    state.city.name.substringBefore(","),
                    weather?.condition?.title,
                    weather?.let { "ветер ${Math.round(it.wind)} м/с" },
                ).joinToString(" · "),
                color = TextMuted,
                fontSize = 16.sp,
            )
        }
    }
}

/** Лампочка туннеля openconnect: зелёная — подключён, жёлтая — переподключается, красная — нет. */
@Composable
private fun VpnIndicator(state: LauncherState) {
    var details by remember { mutableStateOf(false) }
    val color = when (state.vpn) {
        VpnState.UP -> Up
        VpnState.DEGRADED -> Amber
        VpnState.DOWN -> Down
    }
    Row(
        Modifier.clip(RoundedCornerShape(10.dp)).clickable { details = !details }.padding(horizontal = 6.dp, vertical = 2.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.size(12.dp).clip(CircleShape).background(color))
        Spacer(Modifier.width(8.dp))
        Text(
            if (details) listOfNotNull(state.vpn.title, state.vpnAddress).joinToString(" · ") else "VPN",
            color = TextMuted,
            fontSize = 14.sp,
        )
    }
}

@Composable
private fun WeatherStrip(weather: Weather) {
    Row(Modifier.fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(10.dp)) {
        weather.hours.forEach {
            WeatherChip(it.time.format(DateTimeFormatter.ofPattern("HH:mm")), it.condition.symbol, temperature(it.temperature))
        }
        weather.days.forEach {
            WeatherChip(
                it.date.format(DateTimeFormatter.ofPattern("EE", RU)),
                it.condition.symbol,
                "${temperature(it.max)} / ${temperature(it.min)}",
            )
        }
    }
}

@Composable
private fun androidx.compose.foundation.layout.RowScope.WeatherChip(label: String, symbol: String, value: String) {
    Column(
        Modifier.weight(1f).clip(RoundedCornerShape(16.dp)).background(CardColor).padding(vertical = 10.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
    ) {
        Text(label, color = TextMuted, fontSize = 14.sp)
        Text(symbol, fontSize = 22.sp)
        Text(value, color = TextMain, fontSize = 15.sp, maxLines = 1)
    }
}

/* ---------- События ---------- */

@Composable
private fun AgendaCard(state: LauncherState, vm: LauncherViewModel, modifier: Modifier) {
    val context = LocalContext.current
    val permission = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        vm.onCalendarPermission()
    }
    DashboardCard(
        title = "Ближайшее",
        action = "Календарь",
        onAction = { context.openCalendar(System.currentTimeMillis()) },
        modifier = modifier,
    ) {
        when {
            !state.calendarAllowed -> {
                Text("Нужен доступ к календарю, чтобы показывать события.", color = TextMuted, fontSize = 16.sp)
                TextButton(onClick = { permission.launch(arrayOf(Manifest.permission.READ_CALENDAR, Manifest.permission.WRITE_CALENDAR)) }) {
                    Text("Разрешить", color = Accent)
                }
            }
            state.agenda.isEmpty() -> Text("Ближайшие две недели свободны", color = TextMuted, fontSize = 16.sp)
            else -> LazyColumn(verticalArrangement = Arrangement.spacedBy(6.dp)) {
                items(state.agenda) { row -> AgendaItem(row, onClick = { context.openCalendar(row.begin) }) }
            }
        }
    }
}

@Composable
private fun AgendaItem(row: AgendaRow, onClick: () -> Unit) {
    val eventColor = Color(row.color).copy(alpha = 1f)
    val highlighted = row.soon != null
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(14.dp))
            .background(if (highlighted) eventColor.copy(alpha = 0.18f) else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Box(Modifier.width(4.dp).height(36.dp).clip(RoundedCornerShape(2.dp)).background(eventColor))
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(row.title, color = TextMain, fontSize = 18.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                listOfNotNull(row.soon, row.time).joinToString(" · "),
                color = if (highlighted) eventColor else TextMuted,
                fontSize = 14.sp,
            )
        }
        Text(row.day, color = TextMuted, fontSize = 14.sp)
    }
}

private fun Context.openCalendar(at: Long) {
    val uri = CalendarContract.CONTENT_URI.buildUpon().appendPath("time").appendPath(at.toString()).build()
    try {
        startActivity(Intent(Intent.ACTION_VIEW, uri).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: ActivityNotFoundException) {
        // Приложения календаря нет — открывать нечего.
    }
}

/* ---------- Мосбиржа ---------- */

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun QuotesCard(state: LauncherState, vm: LauncherViewModel, onAdd: () -> Unit, modifier: Modifier) {
    val updated = if (state.quotesAt > 0) {
        "обновлено " + java.time.Instant.ofEpochMilli(state.quotesAt).atZone(java.time.ZoneId.systemDefault())
            .format(DateTimeFormatter.ofPattern("HH:mm"))
    } else {
        "загрузка…"
    }
    DashboardCard(
title = "Мосбиржа", action = "Добавить", onAction = onAdd, modifier = modifier) {
        Text(
            if (state.offline) "нет связи · данные могут быть устаревшими" else "$updated · задержка 15 мин",
            color = if (state.offline) Down else TextMuted,
            fontSize = 13.sp,
        )
        Spacer(Modifier.height(8.dp))
        LazyColumn {
            items(state.quotes, key = { it.secid }) { quote ->
                var menu by remember { mutableStateOf(false) }
                Box {
                    QuoteRow(quote, Modifier.combinedClickable(onClick = {}, onLongClick = { menu = true }))
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

@Composable
private fun QuoteRow(quote: Quote, modifier: Modifier) {
    Row(modifier.fillMaxWidth().padding(vertical = 10.dp), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(quote.name, color = TextMain, fontSize = 18.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(
                quote.yield?.let { "доходность ${number(it, 2)}%" } ?: quote.secid,
                color = TextMuted,
                fontSize = 13.sp,
            )
        }
        Text(price(quote), color = TextMain, fontSize = 18.sp)
        Spacer(Modifier.width(12.dp))
        val change = quote.changePercent
        val color = when {
            change == null || change == 0.0 -> TextMuted
            change > 0 -> Up
            else -> Down
        }
        Text(
            change?.let { (if (it > 0) "+" else if (it < 0) "−" else "") + number(abs(it), 2) + "%" } ?: "—",
            color = color,
            fontSize = 14.sp,
            modifier = Modifier
                .clip(RoundedCornerShape(8.dp))
                .background(color.copy(alpha = 0.14f))
                .padding(horizontal = 8.dp, vertical = 4.dp)
                .width(64.dp),
            maxLines = 1,
        )
    }
}

private fun price(quote: Quote): String {
    val value = quote.price ?: return "—"
    val text = number(value, quote.decimals)
    return when (quote.unit) {
        PriceUnit.RUBLE -> "$text ₽"
        PriceUnit.PERCENT_OF_FACE -> "$text%"
        PriceUnit.POINTS, PriceUnit.OTHER -> text
    }
}

private fun number(value: Double, decimals: Int): String =
    NumberFormat.getNumberInstance(RU).apply {
        minimumFractionDigits = decimals.coerceAtMost(2)
        maximumFractionDigits = decimals
    }.format(value)

private fun temperature(value: Double): String {
    val rounded = Math.round(value).toInt()
    return when {
        rounded > 0 -> "+$rounded°"
        rounded < 0 -> "−${-rounded}°"
        else -> "0°"
    }
}

/* ---------- Карточка, док, приложения ---------- */

@Composable
internal fun DashboardCard(
    title: String,
    action: String?,
    onAction: () -> Unit,
    modifier: Modifier,
    content: @Composable ColumnScope.() -> Unit,
) {
    Column(modifier.clip(RoundedCornerShape(28.dp)).background(CardColor).padding(24.dp)) {
        Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
            Text(title, color = TextMain, fontSize = 22.sp, fontWeight = FontWeight.Medium, modifier = Modifier.weight(1f))
            if (action != null) TextButton(onClick = onAction) { Text(action, color = Accent) }
        }
        Spacer(Modifier.height(8.dp))
        content()
    }
}

/**
 * Док: приложения по центру, справа — «назад / домой / недавние» своей панели навигации.
 * «Недавние» умеет только служба [NavBarService]; если она выключена, ведём в настройки.
 */
@Composable
private fun Dock(apps: List<LauncherApp>, onAllApps: () -> Unit, onBack: () -> Unit, onHome: () -> Unit) {
    val context = LocalContext.current
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(28.dp))
            .background(CardColor)
            .padding(horizontal = 20.dp, vertical = 14.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Spacer(Modifier.weight(1f))
        Row(horizontalArrangement = Arrangement.spacedBy(20.dp), verticalAlignment = Alignment.CenterVertically) {
            apps.forEach { app ->
                Image(
                    app.icon,
                    contentDescription = app.label,
                    modifier = Modifier.size(56.dp).clip(RoundedCornerShape(16.dp)).clickable { context.launch(app) },
                )
            }
            Box(
                Modifier.size(56.dp).clip(CircleShape).background(CardRaised).clickable(onClick = onAllApps),
                contentAlignment = Alignment.Center,
            ) {
                Text("⋯", color = TextMain, fontSize = 28.sp)
            }
        }
        Spacer(Modifier.weight(1f))
        NavButton(R.drawable.nav_back, "Назад", onBack)
        NavButton(R.drawable.nav_home, "Домой", onHome)
        NavButton(R.drawable.nav_recents, "Недавние") {
            if (!NavBarService.perform(android.accessibilityservice.AccessibilityService.GLOBAL_ACTION_RECENTS)) {
                Toast.makeText(context, "Включите «Панель навигации» в специальных возможностях", Toast.LENGTH_LONG).show()
                context.startActivity(Intent(Settings.ACTION_ACCESSIBILITY_SETTINGS).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
            }
        }
    }
}

@Composable
private fun NavButton(icon: Int, description: String, onClick: () -> Unit) {
    Box(
        Modifier.size(width = 64.dp, height = 56.dp).clip(RoundedCornerShape(16.dp)).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Image(painterResource(icon), contentDescription = description, modifier = Modifier.size(26.dp))
    }
}

@OptIn(ExperimentalMaterial3Api::class, ExperimentalFoundationApi::class)
@Composable
private fun AppDrawer(state: LauncherState, vm: LauncherViewModel, onDismiss: () -> Unit) {
    val context = LocalContext.current
    val sheet = rememberModalBottomSheetState(skipPartiallyExpanded = true)
    var query by remember { mutableStateOf("") }
    val apps = state.apps.filter { query.isBlank() || it.label.contains(query.trim(), ignoreCase = true) }

    ModalBottomSheet(onDismissRequest = onDismiss, sheetState = sheet, containerColor = CardColor) {
        Column(Modifier.fillMaxHeight(0.92f).padding(horizontal = 24.dp)) {
            OutlinedTextField(
                value = query,
                onValueChange = { query = it },
                placeholder = { Text("Поиск приложений") },
                singleLine = true,
                modifier = Modifier.fillMaxWidth(),
                shape = RoundedCornerShape(16.dp),
            )
            Text(
                "Долгое нажатие — добавить в док или убрать из него",
                color = TextMuted,
                fontSize = 13.sp,
                modifier = Modifier.padding(vertical = 12.dp),
            )
            LazyVerticalGrid(columns = GridCells.Adaptive(112.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
                items(apps, key = { it.component.flattenToString() }) { app ->
                    var menu by remember { mutableStateOf(false) }
                    Box {
                        Column(
                            Modifier
                                .clip(RoundedCornerShape(16.dp))
                                .combinedClickable(
                                    onClick = { onDismiss(); context.launch(app) },
                                    onLongClick = { menu = true },
                                )
                                .padding(8.dp)
                                .fillMaxWidth(),
                            horizontalAlignment = Alignment.CenterHorizontally,
                        ) {
                            Image(app.icon, contentDescription = null, modifier = Modifier.size(60.dp))
                            Spacer(Modifier.height(6.dp))
                            Text(app.label, color = TextMain, fontSize = 14.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                        DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                            DropdownMenuItem(
                                text = { Text(if (vm.isInDock(app)) "Убрать из дока" else "Добавить в док") },
                                onClick = { menu = false; vm.toggleDock(app) },
                            )
                        }
                    }
                }
            }
        }
    }
}

private fun Context.launch(app: LauncherApp) {
    val intent = Intent(Intent.ACTION_MAIN)
        .addCategory(Intent.CATEGORY_LAUNCHER)
        .setComponent(app.component)
        .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED)
    try {
        startActivity(intent)
    } catch (_: ActivityNotFoundException) {
        // Приложение удалили, а список ещё не обновился — обновится при возврате на экран.
    }
}

/* ---------- Диалоги поиска ---------- */

@Composable
private fun <T> SearchDialog(
    title: String,
    placeholder: String,
    search: suspend (String) -> List<T>,
    label: (T) -> Pair<String, String>,
    onPick: suspend (T) -> String?,
    onDismiss: () -> Unit,
) {
    val scope = rememberCoroutineScope()
    var query by remember { mutableStateOf("") }
    var results by remember { mutableStateOf<List<T>>(emptyList()) }
    var error by remember { mutableStateOf<String?>(null) }

    // Ищем, когда пользователь перестал печатать, а не на каждую букву.
    LaunchedEffect(query) {
        if (query.trim().length < 2) {
            results = emptyList()
            return@LaunchedEffect
        }
        delay(400)
        results = try {
            error = null
            search(query.trim())
        } catch (e: Exception) {
            if (e is kotlinx.coroutines.CancellationException) throw e
            error = "Не удалось выполнить поиск — проверьте интернет"
            emptyList()
        }
    }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text(title) },
        text = {
            Column {
                OutlinedTextField(
                    value = query,
                    onValueChange = { query = it },
                    placeholder = { Text(placeholder) },
                    singleLine = true,
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = {}),
                    modifier = Modifier.fillMaxWidth(),
                )
                error?.let { Text(it, color = Down, fontSize = 14.sp, modifier = Modifier.padding(top = 8.dp)) }
                LazyColumn(Modifier.heightIn(max = 360.dp).padding(top = 8.dp)) {
                    items(results) { item ->
                        val (main, secondary) = label(item)
                        Column(
                            Modifier
                                .fillMaxWidth()
                                .clip(RoundedCornerShape(12.dp))
                                .clickable {
                                    scope.launch {
                                        error = try {
                                            onPick(item)
                                        } catch (e: Exception) {
                                            if (e is kotlinx.coroutines.CancellationException) throw e
                                            "Не удалось добавить — проверьте интернет"
                                        }
                                        if (error == null) onDismiss()
                                    }
                                }
                                .padding(horizontal = 8.dp, vertical = 10.dp),
                        ) {
                            Text(main, fontSize = 16.sp)
                            if (secondary.isNotBlank()) Text(secondary, color = TextMuted, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                        }
                    }
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Закрыть") } },
    )
}

@Composable
private fun CityDialog(vm: LauncherViewModel, onDismiss: () -> Unit) = SearchDialog(
    title = "Город для погоды",
    placeholder = "Санкт-Петербург",
    search = vm::searchCities,
    label = { it.name to "" },
    onPick = { vm.setCity(it); null },
    onDismiss = onDismiss,
)

@Composable
private fun InstrumentDialog(vm: LauncherViewModel, onDismiss: () -> Unit) = SearchDialog(
    title = "Добавить инструмент",
    placeholder = "Тикер, ISIN или название: SBER, ОФЗ 26238",
    search = vm::searchSecurities,
    label = { "${it.shortName} · ${it.secid}" to it.name },
    onPick = { if (vm.addInstrument(it.secid)) null else "По этой бумаге сейчас нет торгов" },
    onDismiss = onDismiss,
)
