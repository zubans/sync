package com.example.contactsync.launcher

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.core.tween
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.gestures.awaitEachGesture
import androidx.compose.foundation.gestures.awaitFirstDown
import androidx.compose.foundation.gestures.detectTapGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.ColumnScope
import androidx.compose.foundation.layout.ExperimentalLayoutApi
import androidx.compose.foundation.layout.FlowRow
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.layout.widthIn
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.rememberCoroutineScope
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.PathEffect
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.contactsync.data.IndicatorsDto
import com.example.contactsync.data.LotRequest
import kotlinx.coroutines.launch
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter
import java.time.format.DateTimeParseException
import kotlin.math.abs

/* Цвета графика: проверены валидатором палитры на поверхности карточки (тёмная тема) — различимы и при дальтонизме. */
private val ChartPrice = Color(0xFF6592DA)
private val ChartSma = Color(0xFFC97F14)
private val ChartBuy = Color(0xFF3FA877)

private val DAY = DateTimeFormatter.ofPattern("dd.MM.yyyy")
private val SHORT_DAY = DateTimeFormatter.ofPattern("dd.MM.yy")
private val STAMP = DateTimeFormatter.ofPattern("dd.MM HH:mm")

/**
 * Инструмент на весь экран — нажатие на строку Мосбиржи в карточке или на подробном экране. Всё с сервера
 * sync: совет модели (agy) с разбором новостей и графиков, позиция и доходность по покупкам, график за год,
 * индикаторы, сведения о бумаге и новости с последствиями. Новость открывается экраном чтения поверх.
 */
@Composable
internal fun InstrumentScreen(state: LauncherState, vm: LauncherViewModel) {
    var shown by remember { mutableStateOf<InstrumentPage?>(null) }
    state.instrument?.let { shown = it }
    AnimatedVisibility(
        visible = state.instrument != null,
        enter = fadeIn(tween(300)) + slideInVertically(tween(300)) { it / 12 },
        exit = fadeOut(tween(250)) + slideOutVertically(tween(250)) { it / 12 },
    ) {
        val page = shown ?: return@AnimatedVisibility
        BackHandler { vm.closeInstrument() }
        val quote = state.quotes.firstOrNull { it.secid == page.secid }
        Box(
            Modifier
                .fillMaxSize()
                .background(Background)
                // Нажатия не должны проваливаться в дашборд под экраном.
                .pointerInput(Unit) { detectTapGestures { } }
                .safeDrawingPadding()
                .padding(horizontal = 32.dp, vertical = 24.dp),
        ) {
            Column(Modifier.fillMaxSize()) {
                InstrumentHeader(page, quote, onClose = vm::closeInstrument)
                val detail = page.detail
                when {
                    detail != null -> InstrumentBody(detail, quote, vm)
                    page.error != null -> Text(page.error, color = Down, fontSize = 17.sp)
                    else -> Text("Загрузка…", color = TextMuted, fontSize = 17.sp)
                }
            }
        }
    }
}

@Composable
private fun InstrumentHeader(page: InstrumentPage, quote: Quote?, onClose: () -> Unit) {
    Row(Modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
        Column(Modifier.weight(1f)) {
            Text(page.name, color = TextMain, fontSize = 34.sp, fontWeight = FontWeight.Medium, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(page.secid, color = TextMuted, fontSize = 15.sp)
                if (quote != null) {
                    Text("  ·  " + price(quote), color = TextMain, fontSize = 15.sp)
                    quote.changePercent?.let {
                        Text("  " + signed(it, 2) + "%", color = changeColor(it), fontSize = 15.sp)
                    }
                    quote.yield?.let { Text("  ·  доходность ${number(it, 2)}%", color = TextMuted, fontSize = 15.sp) }
                }
            }
        }
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
private fun InstrumentBody(detail: InstrumentDetail, quote: Quote?, vm: LauncherViewModel) {
    val price = quote?.price ?: detail.dto.indicators?.close
    BoxWithConstraints(Modifier.fillMaxSize()) {
        if (maxWidth >= 900.dp) {
            Row(Modifier.fillMaxSize(), horizontalArrangement = Arrangement.spacedBy(20.dp)) {
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                    AnalysisSection(detail, vm)
                    PositionSection(detail, quote, price, vm)
                    NewsSection(detail, vm)
                }
                Column(Modifier.weight(1f).verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                    ChartSection(detail, price)
                    IndicatorsSection(detail)
                    AboutSection(detail)
                }
            }
        } else {
            Column(Modifier.fillMaxSize().verticalScroll(rememberScrollState()), verticalArrangement = Arrangement.spacedBy(20.dp)) {
                AnalysisSection(detail, vm)
                PositionSection(detail, quote, price, vm)
                ChartSection(detail, price)
                IndicatorsSection(detail)
                NewsSection(detail, vm)
                AboutSection(detail)
            }
        }
    }
}

@Composable
private fun Section(title: String, modifier: Modifier = Modifier, action: (@Composable () -> Unit)? = null, content: @Composable ColumnScope.() -> Unit) {
    Column(modifier.fillMaxWidth().clip(RoundedCornerShape(24.dp)).background(CardColor).padding(20.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text(title, color = TextMuted, fontSize = 15.sp, modifier = Modifier.weight(1f))
            action?.invoke()
        }
        Spacer(Modifier.height(12.dp))
        content()
    }
}

/* ---------- Совет модели ---------- */

@Composable
private fun AnalysisSection(detail: InstrumentDetail, vm: LauncherViewModel) {
    val analysis = detail.analysis
    val secid = detail.dto.secid
    Section(
        "Анализ и совет",
        action = {
            TextButton(onClick = { vm.requestAnalysis(secid) }, enabled = analysis?.pending != true) {
                Text(if (analysis?.pending == true) "Пересчитывается…" else "Пересчитать", color = if (analysis?.pending == true) TextMuted else Accent)
            }
        },
    ) {
        val recommendation = analysis?.recommendation
        if (analysis == null || recommendation == null) {
            Text(
                "Анализ готовится: сервер собирает новости и индикаторы и спрашивает модель в фоне — обычно до 5 минут.",
                color = TextMuted,
                fontSize = 16.sp,
            )
            analysis?.error?.let { Text("Последняя попытка не удалась: $it", color = Down, fontSize = 14.sp) }
            return@Section
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            RecommendationBadge(recommendation, analysis.urgency, detail.lots.isNotEmpty(), large = true)
            Spacer(Modifier.width(14.dp))
            Column {
                val outlook = analysis.outlook?.let { "Прогноз: " + Portfolio.outlookText(it) } ?: "Прогноз неясен"
                Text(
                    listOfNotNull(outlook, analysis.horizon.ifBlank { null }, analysis.confidence?.let { "уверенность $it%" }).joinToString(" · "),
                    color = analysis.outlook?.let(::outlookColor) ?: TextMuted,
                    fontSize = 16.sp,
                )
                when (analysis.urgency) {
                    Urgency.ACT -> Text("⚠ Пора действовать", color = Amber, fontSize = 14.sp)
                    Urgency.WATCH -> Text("Присмотреться", color = Amber, fontSize = 14.sp)
                    Urgency.NONE -> {}
                }
            }
        }
        Spacer(Modifier.height(14.dp))
        Text(analysis.summary, color = TextMain, fontSize = 18.sp, lineHeight = 26.sp)
        AnalysisPart("Новостной фон", analysis.news)
        AnalysisPart("Графики и индикаторы", analysis.technical)
        AnalysisPart("Ваша позиция", analysis.position)
        AnalysisList("Риски", analysis.risks)
        AnalysisList("Пересмотреть, если", analysis.triggers)
        Spacer(Modifier.height(14.dp))
        val zone = ZoneId.systemDefault()
        Text(
            listOfNotNull(
                analysis.analyzedAt?.let { "Анализ " + it.atZone(zone).format(STAMP) },
                "новостей ${analysis.newsCount}",
                analysis.price?.let { "цена тогда " + number(it, 2) },
                analysis.model,
            ).joinToString(" · "),
            color = TextMuted,
            fontSize = 13.sp,
        )
        analysis.error?.let { Text("Последний пересчёт не удался: $it", color = Down, fontSize = 13.sp) }
        Text("Вывод модели по открытым данным, не инвестиционная рекомендация.", color = TextMuted, fontSize = 12.sp)
    }
}

@Composable
private fun AnalysisPart(title: String, text: String) {
    if (text.isBlank()) return
    Spacer(Modifier.height(14.dp))
    Text(title, color = TextMuted, fontSize = 14.sp)
    Text(text, color = TextMain, fontSize = 16.sp, lineHeight = 23.sp)
}

@Composable
private fun AnalysisList(title: String, items: List<String>) {
    if (items.isEmpty()) return
    Spacer(Modifier.height(14.dp))
    Text(title, color = TextMuted, fontSize = 14.sp)
    items.forEach { Text("•  $it", color = TextMain, fontSize = 16.sp, lineHeight = 23.sp) }
}

/** Совет цветной плашкой: докупить — зелёным, продать — красным, держать — синим, наблюдать — серым; ⚠ — пора действовать. */
@Composable
internal fun RecommendationBadge(recommendation: Recommendation, urgency: Urgency, hasPosition: Boolean, large: Boolean = false) {
    val color = recommendationColor(recommendation)
    Text(
        (if (urgency == Urgency.ACT) "⚠ " else "") + Portfolio.label(recommendation, hasPosition),
        color = color,
        fontSize = if (large) 22.sp else 12.sp,
        fontWeight = if (large) FontWeight.Medium else FontWeight.Normal,
        maxLines = 1,
        modifier = Modifier
            .clip(RoundedCornerShape(if (large) 14.dp else 8.dp))
            .background(color.copy(alpha = 0.14f))
            .padding(horizontal = if (large) 16.dp else 8.dp, vertical = if (large) 8.dp else 3.dp),
    )
}

internal fun recommendationColor(recommendation: Recommendation): Color = when (recommendation) {
    Recommendation.BUY -> Up
    Recommendation.SELL -> Down
    Recommendation.HOLD -> Accent
    Recommendation.WAIT -> TextMuted
}

private fun outlookColor(outlook: Outlook): Color = when (outlook) {
    Outlook.UP -> Up
    Outlook.DOWN -> Down
    Outlook.SIDEWAYS -> TextMuted
}

/* ---------- Позиция ---------- */

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun PositionSection(detail: InstrumentDetail, quote: Quote?, price: Double?, vm: LauncherViewModel) {
    var adding by remember { mutableStateOf(false) }
    val secid = detail.dto.secid
    val unit = quote?.unit ?: PriceUnit.RUBLE
    Section("Позиция", action = { TextButton(onClick = { adding = true }) { Text("+ Покупка", color = Accent) } }) {
        val position = Portfolio.position(detail.lots, price)
        if (position == null) {
            Text("Покупок нет. Добавьте, когда и сколько купили, — появится доходность, а совет учтёт позицию.", color = TextMuted, fontSize = 16.sp)
        } else {
            val profit = position.profit
            Row(verticalAlignment = Alignment.Bottom) {
                Text(position.value?.let(::rubles) ?: "—", color = TextMain, fontSize = 28.sp)
                Spacer(Modifier.width(12.dp))
                if (profit != null && position.profitPercent != null) {
                    Text(
                        (if (profit >= 0) "+" else "−") + rubles(abs(profit)) + "  " + signed(position.profitPercent, 2) + "%",
                        color = changeColor(profit),
                        fontSize = 18.sp,
                    )
                }
            }
            Text(
                "${number(position.quantity, 2)} шт. · вложено ${rubles(position.amount)} · средняя цена ${unitPrice(position.averagePrice, unit)}",
                color = TextMuted,
                fontSize = 14.sp,
            )
            Spacer(Modifier.height(10.dp))
            detail.lots.forEach { lot ->
                var menu by remember { mutableStateOf(false) }
                Box {
                    Row(
                        Modifier
                            .fillMaxWidth()
                            .combinedClickable(onClick = {}, onLongClick = { menu = true })
                            .padding(vertical = 8.dp),
                        verticalAlignment = Alignment.CenterVertically,
                    ) {
                        Text(lot.boughtOn.format(DAY), color = TextMuted, fontSize = 15.sp, modifier = Modifier.weight(1f))
                        Text("${number(lot.quantity, 2)} × ${unitPrice(lot.price, unit)}", color = TextMain, fontSize = 15.sp, modifier = Modifier.weight(1.4f))
                        Text(rubles(lot.amount), color = TextMain, fontSize = 15.sp, modifier = Modifier.weight(1.1f))
                        val lotChange = price?.takeIf { lot.price > 0 }?.let { (it / lot.price - 1) * 100 }
                        Text(lotChange?.let { signed(it, 2) + "%" } ?: "—", color = lotChange?.let(::changeColor) ?: TextMuted, fontSize = 15.sp)
                    }
                    DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                        DropdownMenuItem(text = { Text("Удалить покупку") }, onClick = { menu = false; vm.deleteLot(secid, lot.id) })
                    }
                }
            }
            Text("Долгое нажатие на покупку — удалить", color = TextMuted, fontSize = 12.sp)
        }
    }
    if (adding) {
        val face = detail.dto.description.firstOrNull { it.name == "FACEVALUE" }?.value?.toDoubleOrNull()
        LotDialog(secid, unit, price, face, vm, onDismiss = { adding = false })
    }
}

/** Новая покупка: дата, количество, цена (по умолчанию — текущая) и сумма — считается сама, пока её не правили. */
@Composable
private fun LotDialog(secid: String, unit: PriceUnit, price: Double?, faceValue: Double?, vm: LauncherViewModel, onDismiss: () -> Unit) {
    var date by remember { mutableStateOf(LocalDate.now().format(DAY)) }
    var quantity by remember { mutableStateOf("") }
    var priceText by remember { mutableStateOf(price?.let { plain(it) }.orEmpty()) }
    var amountText by remember { mutableStateOf("") }
    var amountEdited by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var saving by remember { mutableStateOf(false) }
    val scope = rememberCoroutineScope()
    val q = quantity.toAmount()
    val p = priceText.toAmount()
    val computed = if (q != null && p != null) Portfolio.defaultAmount(q, p, unit, faceValue) else null
    val shownAmount = if (amountEdited) amountText else computed?.let { plain(it) }.orEmpty()

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Покупка $secid") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(date, { date = it }, label = { Text("Дата, ДД.ММ.ГГГГ") }, singleLine = true)
                OutlinedTextField(
                    quantity, { quantity = it }, label = { Text("Количество, шт.") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                )
                OutlinedTextField(
                    priceText, { priceText = it },
                    label = { Text(if (unit == PriceUnit.PERCENT_OF_FACE) "Цена, % от номинала" else "Цена") },
                    singleLine = true, keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                )
                OutlinedTextField(
                    shownAmount, { amountText = it; amountEdited = true },
                    label = { Text("Сумма, ₽ (с НКД и комиссией)") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Decimal),
                )
                error?.let { Text(it, color = Down, fontSize = 14.sp) }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !saving,
                onClick = {
                    val day = try {
                        LocalDate.parse(date.trim(), DAY)
                    } catch (_: DateTimeParseException) {
                        null
                    }
                    val amount = shownAmount.toAmount()
                    error = when {
                        day == null -> "Дата — в виде 14.10.2026"
                        day.isAfter(LocalDate.now()) -> "Дата покупки — в будущем"
                        q == null || q <= 0 -> "Укажите количество"
                        p == null || p <= 0 -> "Укажите цену"
                        amount == null || amount <= 0 -> "Укажите сумму"
                        else -> null
                    }
                    if (error == null && day != null && q != null && p != null) {
                        saving = true
                        scope.launch {
                            if (vm.addLot(secid, LotRequest(day.toString(), q, p, amount))) {
                                onDismiss()
                            } else {
                                error = "Сервер не принял покупку"
                                saving = false
                            }
                        }
                    }
                },
            ) { Text("Сохранить") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/** Число из поля ввода: запятая или точка, пробелы между разрядами. */
private fun String.toAmount(): Double? = replace(" ", "").replace(" ", "").replace(',', '.').toDoubleOrNull()

/** Число для поля ввода — без разрядов, с запятой: «312,45». */
private fun plain(value: Double): String =
    String.format(RU, "%.2f", value).trimEnd('0').trimEnd(',')

private fun rubles(value: Double): String = number(value, 2) + " ₽"

private fun unitPrice(value: Double, unit: PriceUnit): String = when (unit) {
    PriceUnit.RUBLE -> number(value, 2) + " ₽"
    PriceUnit.PERCENT_OF_FACE -> number(value, 2) + "%"
    else -> number(value, 2)
}

internal fun signed(value: Double, decimals: Int): String =
    (if (value > 0) "+" else if (value < 0) "−" else "") + number(abs(value), decimals)

internal fun changeColor(value: Double): Color = when {
    value > 0 -> Up
    value < 0 -> Down
    else -> TextMuted
}

/* ---------- График ---------- */

/** Периоды графика в торговых днях. */
private val PERIODS = listOf("1М" to 21, "3М" to 63, "6М" to 126, "1Г" to 260)

/**
 * Закрытия торговых дней линией, 50-дневная средняя и средняя цена покупки пунктиром. Палец на графике —
 * перекрестие с датой и ценой. Ось одна: все линии — цена.
 */
@Composable
private fun ChartSection(detail: InstrumentDetail, price: Double?) {
    var period by remember { mutableStateOf(PERIODS[1].second) }
    val all = detail.dto.chart
    Section(
        "График",
        action = {
            Row {
                PERIODS.forEach { (label, bars) ->
                    Text(
                        label,
                        color = if (period == bars) TextMain else TextMuted,
                        fontSize = 14.sp,
                        modifier = Modifier
                            .clip(RoundedCornerShape(8.dp))
                            .background(if (period == bars) CardRaised else Color.Transparent)
                            .clickable { period = bars }
                            .padding(horizontal = 10.dp, vertical = 4.dp),
                    )
                }
            }
        },
    ) {
        if (all.size < 2) {
            Text(detail.dto.error ?: "Нет данных о ценах", color = TextMuted, fontSize = 16.sp)
            return@Section
        }
        val closes = all.map { it.close }
        // Средняя по всей истории, а показывается её конец — у начала периода она уже посчитана.
        val sma = closes.indices.map { i -> if (i >= 49) closes.subList(i - 49, i + 1).average() else null }
        val from = (all.size - period).coerceAtLeast(0)
        val points = all.subList(from, all.size)
        val smaShown = sma.subList(from, sma.size)
        val buy = Portfolio.position(detail.lots, price)?.averagePrice
        var touch by remember(period) { mutableStateOf<Int?>(null) }

        val values = points.map { it.close } + smaShown.filterNotNull() + listOfNotNull(buy)
        val min = values.min()
        val max = values.max()
        val span = (max - min).takeIf { it > 0 } ?: 1.0

        Row(horizontalArrangement = Arrangement.spacedBy(14.dp), verticalAlignment = Alignment.CenterVertically) {
            Legend(ChartPrice, "Цена закрытия", dashed = false)
            if (smaShown.any { it != null }) Legend(ChartSma, "Средняя за 50 дней", dashed = false)
            if (buy != null) Legend(ChartBuy, "Средняя цена покупки", dashed = true)
        }
        Spacer(Modifier.height(8.dp))
        Box(Modifier.fillMaxWidth().height(220.dp)) {
            Canvas(
                Modifier
                    .fillMaxSize()
                    // Палец на графике — перекрестие: пока держат и водят, показываем дату и цену под ним.
                    .pointerInput(points) {
                        val pick = { x: Float -> ((x / size.width) * (points.size - 1)).toInt().coerceIn(0, points.size - 1) }
                        awaitEachGesture {
                            touch = pick(awaitFirstDown().position.x)
                            do {
                                val event = awaitPointerEvent()
                                event.changes.firstOrNull { it.pressed }?.let { touch = pick(it.position.x) }
                            } while (event.changes.any { it.pressed })
                            touch = null
                        }
                    },
            ) {
                val x = { i: Int -> if (points.size == 1) 0f else i * size.width / (points.size - 1) }
                val y = { v: Double -> (size.height - (v - min) / span * size.height).toFloat() }
                // Сетка — три тонкие линии, не спорят с данными.
                for (k in 0..2) {
                    val gy = size.height * k / 2
                    drawLine(TextMuted.copy(alpha = 0.15f), Offset(0f, gy), Offset(size.width, gy), 1.dp.toPx())
                }
                fun line(series: List<Double?>, color: Color, width: Float) {
                    val path = Path()
                    var started = false
                    series.forEachIndexed { i, v ->
                        if (v == null) return@forEachIndexed
                        if (!started) path.moveTo(x(i), y(v)) else path.lineTo(x(i), y(v))
                        started = true
                    }
                    drawPath(path, color, style = Stroke(width, cap = StrokeCap.Round))
                }
                line(smaShown, ChartSma, 1.5.dp.toPx())
                line(points.map { it.close }, ChartPrice, 2.dp.toPx())
                buy?.let {
                    drawLine(ChartBuy, Offset(0f, y(it)), Offset(size.width, y(it)), 1.5.dp.toPx(),
                        pathEffect = PathEffect.dashPathEffect(floatArrayOf(8.dp.toPx(), 6.dp.toPx())))
                }
                touch?.let { i ->
                    drawLine(TextMuted.copy(alpha = 0.6f), Offset(x(i), 0f), Offset(x(i), size.height), 1.dp.toPx())
                    drawCircle(Background, 6.dp.toPx(), Offset(x(i), y(points[i].close)))
                    drawCircle(ChartPrice, 4.dp.toPx(), Offset(x(i), y(points[i].close)))
                }
            }
            Text(number(max, 2), color = TextMuted, fontSize = 12.sp, modifier = Modifier.align(Alignment.TopEnd))
            Text(number(min, 2), color = TextMuted, fontSize = 12.sp, modifier = Modifier.align(Alignment.BottomEnd))
        }
        Spacer(Modifier.height(6.dp))
        Row {
            val shown = touch?.let { points[it] }
            if (shown != null) {
                Text(
                    LocalDate.parse(shown.date).format(DAY) + " · " + number(shown.close, 2) +
                        (smaShown[touch!!]?.let { " · средняя " + number(it, 2) } ?: ""),
                    color = TextMain,
                    fontSize = 14.sp,
                )
            } else {
                Text(LocalDate.parse(points.first().date).format(SHORT_DAY), color = TextMuted, fontSize = 12.sp, modifier = Modifier.weight(1f))
                val change = (points.last().close / points.first().close - 1) * 100
                Text("за период " + signed(change, 2) + "%", color = changeColor(change), fontSize = 13.sp, modifier = Modifier.weight(1f))
                Text(LocalDate.parse(points.last().date).format(SHORT_DAY), color = TextMuted, fontSize = 12.sp)
            }
        }
    }
}

@Composable
private fun Legend(color: Color, label: String, dashed: Boolean) {
    Row(verticalAlignment = Alignment.CenterVertically) {
        Canvas(Modifier.width(18.dp).height(8.dp)) {
            drawLine(
                color, Offset(0f, size.height / 2), Offset(size.width, size.height / 2), 2.dp.toPx(),
                pathEffect = if (dashed) PathEffect.dashPathEffect(floatArrayOf(4.dp.toPx(), 3.dp.toPx())) else null,
            )
        }
        Spacer(Modifier.width(6.dp))
        Text(label, color = TextMuted, fontSize = 12.sp)
    }
}

/* ---------- Индикаторы ---------- */

@OptIn(ExperimentalLayoutApi::class)
@Composable
private fun IndicatorsSection(detail: InstrumentDetail) {
    val ind = detail.dto.indicators ?: return
    Section("Индикаторы · по дневным свечам на ${LocalDate.parse(ind.date).format(DAY)}") {
        val r = ind.returns
        FlowRow(horizontalArrangement = Arrangement.spacedBy(10.dp), verticalArrangement = Arrangement.spacedBy(10.dp)) {
            listOf("Неделя" to r.week, "Месяц" to r.month, "Квартал" to r.quarter, "Полгода" to r.halfYear, "Год" to r.year).forEach { (label, value) ->
                Metric(label, value?.let { signed(it, 2) + "%" } ?: "—", value?.let(::changeColor) ?: TextMuted)
            }
        }
        Spacer(Modifier.height(14.dp))
        indicatorRows(ind).forEach { (label, value, note) ->
            Row(Modifier.fillMaxWidth().padding(vertical = 5.dp)) {
                Text(label, color = TextMuted, fontSize = 15.sp, modifier = Modifier.weight(1f))
                Column(Modifier.weight(1.4f)) {
                    Text(value, color = TextMain, fontSize = 15.sp)
                    if (note != null) Text(note, color = TextMuted, fontSize = 13.sp)
                }
            }
        }
    }
}

@Composable
private fun Metric(label: String, value: String, color: Color) {
    Column(Modifier.clip(RoundedCornerShape(12.dp)).background(CardRaised).padding(horizontal = 14.dp, vertical = 8.dp)) {
        Text(label, color = TextMuted, fontSize = 12.sp)
        Text(value, color = color, fontSize = 17.sp)
    }
}

/** Строки индикаторов: название, значение и короткое толкование. */
private fun indicatorRows(ind: IndicatorsDto): List<Triple<String, String, String?>> {
    val rows = mutableListOf<Triple<String, String, String?>>()
    ind.trend?.let {
        rows += Triple("Тренд", mapOf("up" to "восходящий", "down" to "нисходящий", "sideways" to "боковой")[it] ?: it, "по ценам и средним 50 и 200 дней")
    }
    ind.smaCross?.let {
        rows += Triple(
            if (it.type == "golden") "Золотой крест" else "Крест смерти",
            if (it.barsAgo == 0) "сегодня" else "${it.barsAgo} дн. назад",
            if (it.type == "golden") "средняя 50 дней пересекла 200-дневную снизу вверх" else "средняя 50 дней пересекла 200-дневную сверху вниз",
        )
    }
    listOf(Triple("SMA 20", ind.sma20, ind.vsSma20), Triple("SMA 50", ind.sma50, ind.vsSma50), Triple("SMA 200", ind.sma200, ind.vsSma200)).forEach { (label, sma, vs) ->
        if (sma != null) rows += Triple(label, number(sma, 2), vs?.let { "цена " + (if (it >= 0) "выше" else "ниже") + " на " + number(abs(it), 2) + "%" })
    }
    ind.rsi14?.let {
        rows += Triple("RSI 14", number(it, 1), when {
            it >= 70 -> "перекупленность — возможен откат"
            it <= 30 -> "перепроданность — возможен отскок"
            else -> "нейтрально"
        })
    }
    ind.macd?.let {
        rows += Triple("MACD", "гистограмма " + signed(it.histogram, 3), when (it.cross) {
            "bullish" -> "линия пересекла сигнальную вверх — сигнал к росту"
            "bearish" -> "линия пересекла сигнальную вниз — сигнал к снижению"
            else -> if (it.histogram >= 0) "импульс вверх" else "импульс вниз"
        })
    }
    ind.bollinger?.let {
        rows += Triple("Боллинджер", number(it.lower, 2) + " – " + number(it.upper, 2), it.percentB?.let { b ->
            when {
                b >= 1 -> "цена выше верхней полосы"
                b <= 0 -> "цена ниже нижней полосы"
                else -> "цена на ${Math.round(b * 100)}% ширины полосы"
            }
        })
    }
    ind.volatility?.let { rows += Triple("Волатильность", number(it, 1) + "% годовых", "по дневным изменениям за месяц") }
    rows += Triple("52 недели", number(ind.low52, 2) + " – " + number(ind.high52, 2), ind.fromHigh52?.let { "от максимума " + signed(it, 2) + "%" })
    ind.maxDrawdownYear?.let { rows += Triple("Просадка за год", signed(it, 2) + "%", "наибольшее падение от пика") }
    rows += Triple("Поддержка / сопротивление", number(ind.support, 2) + " / " + number(ind.resistance, 2), "минимум и максимум 20 дней")
    ind.volumeRatio?.let {
        rows += Triple("Оборот", "×" + number(it, 2), if (it >= 1.5) "интерес к бумаге вырос" else if (it <= 0.6) "интерес к бумаге упал" else "неделя к среднему за 50 дней")
    }
    return rows
}

/* ---------- Сведения ---------- */

/** Служебные поля ISS, которые человеку ничего не говорят. */
private val HIDDEN_FIELDS = setOf("SECID", "LATNAME", "REGNUMBER", "TYPE", "GROUP", "GROUPNAME", "ISQUALIFIEDINVESTORS", "MORNINGSESSION", "EVENINGSESSION", "WEEKENDSESSION")

@Composable
private fun AboutSection(detail: InstrumentDetail) {
    val fields = detail.dto.description.filterNot { it.name in HIDDEN_FIELDS }
    if (fields.isEmpty() && detail.dto.dividends.isEmpty()) return
    Section("О бумаге") {
        fields.forEach { field ->
            Row(Modifier.fillMaxWidth().padding(vertical = 4.dp)) {
                Text(field.title, color = TextMuted, fontSize = 14.sp, modifier = Modifier.weight(1f))
                Text(field.value, color = TextMain, fontSize = 14.sp, modifier = Modifier.weight(1.3f))
            }
        }
        if (detail.dto.dividends.isNotEmpty()) {
            Spacer(Modifier.height(12.dp))
            Text("Дивиденды · дата закрытия реестра", color = TextMuted, fontSize = 14.sp)
            detail.dto.dividends.asReversed().forEach {
                Row(Modifier.fillMaxWidth().padding(vertical = 3.dp)) {
                    Text(LocalDate.parse(it.date).format(DAY), color = TextMuted, fontSize = 14.sp, modifier = Modifier.weight(1f))
                    Text(number(it.value, 2) + if (it.currency == "RUB" || it.currency == "SUR") " ₽" else " " + it.currency, color = TextMain, fontSize = 14.sp, modifier = Modifier.weight(1.3f))
                }
            }
        }
    }
}

/* ---------- Новости ---------- */

@Composable
private fun NewsSection(detail: InstrumentDetail, vm: LauncherViewModel) {
    val secid = detail.dto.secid
    Section("Новости · последствия и упоминания за 2 недели") {
        if (detail.news.isEmpty()) {
            Text("Новостей об инструменте нет", color = TextMuted, fontSize = 16.sp)
            return@Section
        }
        val zone = ZoneId.systemDefault()
        detail.news.forEach { item ->
            Column(Modifier.fillMaxWidth().clickable { vm.read(item) }.padding(vertical = 8.dp).widthIn(max = 900.dp)) {
                Text(
                    listOfNotNull(item.time.atZone(zone).format(STAMP), item.sourceTitle).joinToString(" · "),
                    color = TextMuted,
                    fontSize = 13.sp,
                )
                Text(item.title, color = TextMain, fontSize = 16.sp, lineHeight = 22.sp)
                val impact = item.market.firstOrNull { it.secid == secid } ?: item.market.firstOrNull()
                if (impact != null) {
                    val color = impact.hourChange?.let(::changeColor) ?: forecastColor(impact.forecast)
                    Text(
                        NewsFeed.arrow(impact.forecast) + " " + NewsFeed.forecastText(impact.forecast) + " · " + NewsFeed.marketFact(impact),
                        color = color,
                        fontSize = 14.sp,
                    )
                    Text(impact.reason, color = TextMuted, fontSize = 14.sp, lineHeight = 20.sp)
                } else {
                    Text("упоминание", color = TextMuted, fontSize = 13.sp)
                }
            }
        }
    }
}

/** Время анализа для строк виджета: «12:05» сегодня, иначе «13.10». */
internal fun analysisTime(at: Instant): String {
    val local = at.atZone(ZoneId.systemDefault())
    return if (local.toLocalDate() == LocalDate.now()) local.format(DateTimeFormatter.ofPattern("HH:mm")) else local.format(DateTimeFormatter.ofPattern("dd.MM"))
}
