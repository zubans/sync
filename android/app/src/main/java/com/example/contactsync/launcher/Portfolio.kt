package com.example.contactsync.launcher

import com.example.contactsync.data.AnalysisDto
import com.example.contactsync.data.InstrumentDetailDto
import com.example.contactsync.data.InstrumentNewsDto
import com.example.contactsync.data.LotDto
import com.example.contactsync.data.NewsItemDto
import java.time.Instant
import java.time.LocalDate
import java.time.OffsetDateTime

/** Совет модели по инструменту. */
enum class Recommendation { BUY, HOLD, SELL, WAIT }

enum class Outlook { UP, DOWN, SIDEWAYS }

/** Предупреждение: ACT — пора действовать, WATCH — присмотреться. */
enum class Urgency { NONE, WATCH, ACT }

/**
 * Анализ инструмента моделью (agy) на сервере: новостные триггеры, индикаторы по дневным свечам, сведения
 * о бумаге и покупки → совет и прогноз. [recommendation] null — анализа ещё не было.
 */
data class Analysis(
    val recommendation: Recommendation?,
    val outlook: Outlook?,
    /** Уверенность модели, 0–100. */
    val confidence: Int?,
    val urgency: Urgency,
    val horizon: String,
    val summary: String,
    /** Что говорят новости. */
    val news: String,
    /** Что говорят графики и индикаторы. */
    val technical: String,
    /** Совет по позиции; пусто — покупок нет. */
    val position: String,
    val risks: List<String>,
    val triggers: List<String>,
    /** Цена на момент анализа. */
    val price: Double?,
    val newsCount: Int,
    val model: String?,
    val analyzedAt: Instant?,
    /** Пересчёт запрошен и ещё не сделан. */
    val pending: Boolean,
    val error: String?,
)

/** Покупка: дата, количество, цена (у облигаций — % от номинала) и потраченная сумма, ₽. */
data class Lot(val id: Long, val boughtOn: LocalDate, val quantity: Double, val price: Double, val amount: Double)

/** Позиция по всем покупкам при текущей цене. */
data class Position(
    val quantity: Double,
    /** Вложено, ₽. */
    val amount: Double,
    /** Средняя цена покупки, взвешенная по количеству. */
    val averagePrice: Double,
    /** Стоит сейчас, ₽; null — цены нет. */
    val value: Double?,
    val profit: Double?,
    val profitPercent: Double?,
)

/** Анализ и покупки инструмента — для строк виджета. */
data class InstrumentInsight(val analysis: Analysis?, val lots: List<Lot>)

/** Всё об инструменте для экрана инструмента; новости — в виде ленты, чтобы открывать их экраном чтения. */
data class InstrumentDetail(val dto: InstrumentDetailDto, val analysis: Analysis?, val lots: List<Lot>, val news: List<NewsItem>)

/** Экран инструмента: пока [detail] нет — загрузка или [error]. */
data class InstrumentPage(val secid: String, val name: String, val detail: InstrumentDetail? = null, val error: String? = null)

object Portfolio {

    fun analysis(dto: AnalysisDto) = Analysis(
        recommendation = when (dto.recommendation) {
            "buy" -> Recommendation.BUY
            "hold" -> Recommendation.HOLD
            "sell" -> Recommendation.SELL
            "wait" -> Recommendation.WAIT
            else -> null
        },
        outlook = when (dto.outlook) {
            "up" -> Outlook.UP
            "down" -> Outlook.DOWN
            "sideways" -> Outlook.SIDEWAYS
            else -> null
        },
        confidence = dto.confidence,
        urgency = when (dto.urgency) {
            "act" -> Urgency.ACT
            "watch" -> Urgency.WATCH
            else -> Urgency.NONE
        },
        horizon = dto.horizon.orEmpty(),
        summary = dto.summary.orEmpty(),
        news = dto.news.orEmpty(),
        technical = dto.technical.orEmpty(),
        position = dto.position.orEmpty(),
        risks = dto.risks,
        triggers = dto.triggers,
        price = dto.price,
        newsCount = dto.newsCount,
        model = dto.model,
        // OffsetDateTime, а не Instant.parse: старые Android не разбирают «+00:00» в Instant.
        analyzedAt = dto.analyzedAt?.let { OffsetDateTime.parse(it).toInstant() },
        pending = dto.pending,
        error = dto.error,
    )

    fun lot(dto: LotDto) = Lot(dto.id, LocalDate.parse(dto.boughtOn), dto.quantity, dto.price, dto.amount)

    fun detail(dto: InstrumentDetailDto) = InstrumentDetail(
        dto = dto,
        analysis = dto.analysis?.let(::analysis),
        lots = dto.lots.map(::lot),
        news = dto.news.map(::news),
    )

    private fun news(dto: InstrumentNewsDto): NewsItem = NewsFeed.item(
        NewsItemDto(id = dto.id, source = dto.source, sourceTitle = dto.sourceTitle, title = dto.title, link = dto.link, time = dto.time, market = dto.market),
    )

    /**
     * Позиция при цене [price]: каждая покупка дорожает вместе с ценой от цены покупки, поэтому у облигаций
     * (цена в процентах) и с комиссией в сумме доходность тоже верная. Так же считает сервер для модели.
     */
    fun position(lots: List<Lot>, price: Double?): Position? {
        if (lots.isEmpty()) return null
        val quantity = lots.sumOf { it.quantity }
        val amount = lots.sumOf { it.amount }
        val value = price?.let { p -> lots.sumOf { if (it.price > 0) it.amount * p / it.price else 0.0 } }
        return Position(
            quantity = quantity,
            amount = amount,
            averagePrice = if (quantity > 0) lots.sumOf { it.quantity * it.price } / quantity else 0.0,
            value = value,
            profit = value?.let { it - amount },
            profitPercent = value?.takeIf { amount > 0 }?.let { (it / amount - 1) * 100 },
        )
    }

    /**
     * Сумма покупки по умолчанию: количество × цена; у облигаций цена — процент от номинала ([faceValue],
     * без него — 1000 ₽). НКД и комиссию пользователь допишет сам.
     */
    fun defaultAmount(quantity: Double, price: Double, unit: PriceUnit, faceValue: Double?): Double =
        if (unit == PriceUnit.PERCENT_OF_FACE) quantity * price / 100 * (faceValue ?: 1000.0) else quantity * price

    /** «Докупить» / «Купить» — смотря, есть ли уже позиция. */
    fun label(recommendation: Recommendation, hasPosition: Boolean): String = when (recommendation) {
        Recommendation.BUY -> if (hasPosition) "Докупить" else "Купить"
        Recommendation.HOLD -> "Держать"
        Recommendation.SELL -> if (hasPosition) "Продать" else "Не покупать"
        Recommendation.WAIT -> "Наблюдать"
    }

    fun outlookText(outlook: Outlook): String = when (outlook) {
        Outlook.UP -> "рост"
        Outlook.DOWN -> "снижение"
        Outlook.SIDEWAYS -> "боковик"
    }

    /** Список после перетаскивания элемента с места [from] на место [to]. */
    fun <T> move(list: List<T>, from: Int, to: Int): List<T> {
        if (from == to || from !in list.indices || to !in list.indices) return list
        return list.toMutableList().apply { add(to, removeAt(from)) }
    }
}
