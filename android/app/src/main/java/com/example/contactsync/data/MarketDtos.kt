package com.example.contactsync.data

import kotlinx.serialization.Serializable

/** Список инструментов виджета Мосбиржи целиком, в порядке на экране (`PUT /api/market/watchlist`). */
@Serializable
data class WatchlistRequest(val instruments: List<WatchlistItemDto>)

@Serializable
data class WatchlistItemDto(val secid: String, val engine: String, val market: String, val board: String, val name: String)

/** Краткий анализ и покупки каждого инструмента (`GET /api/market/overview`). */
@Serializable
data class MarketOverviewResponse(val instruments: List<InstrumentOverviewDto>)

@Serializable
data class InstrumentOverviewDto(val secid: String, val analysis: AnalysisDto? = null, val lots: List<LotDto> = emptyList())

/**
 * Анализ инструмента моделью на сервере: совет, прогноз и объяснения. [pending] — пересчёт запрошен;
 * [analyzedAt] null — анализа ещё не было; [error] — последняя попытка не удалась, прежний вывод остаётся.
 */
@Serializable
data class AnalysisDto(
    /** buy, hold, sell, wait. */
    val recommendation: String? = null,
    /** up, down, sideways. */
    val outlook: String? = null,
    val confidence: Int? = null,
    /** none, watch, act. */
    val urgency: String? = null,
    val horizon: String? = null,
    val summary: String? = null,
    val news: String? = null,
    val technical: String? = null,
    val position: String? = null,
    val risks: List<String> = emptyList(),
    val triggers: List<String> = emptyList(),
    val price: Double? = null,
    val newsCount: Int = 0,
    val model: String? = null,
    val analyzedAt: String? = null,
    val pending: Boolean = false,
    val error: String? = null,
)

/** Покупка: дата (`2026-05-01`), количество, цена (у облигаций — % от номинала), сумма, ₽. */
@Serializable
data class LotDto(val id: Long, val secid: String, val boughtOn: String, val quantity: Double, val price: Double, val amount: Double)

@Serializable
data class LotRequest(val boughtOn: String, val quantity: Double, val price: Double, val amount: Double? = null)

/** Всё об инструменте для экрана на планшете (`GET /api/market/instruments/{secid}`). */
@Serializable
data class InstrumentDetailDto(
    val secid: String,
    val name: String,
    val market: String,
    /** Сведения о бумаге из ISS: поле, подпись, значение. */
    val description: List<DescriptionFieldDto> = emptyList(),
    val dividends: List<DividendDto> = emptyList(),
    val indicators: IndicatorsDto? = null,
    /** Закрытия торговых дней за год — для графика. */
    val chart: List<ChartPointDto> = emptyList(),
    /** Новости инструмента: с последствиями (прогноз и факт) и упоминания, свежие первыми. */
    val news: List<InstrumentNewsDto> = emptyList(),
    val lots: List<LotDto> = emptyList(),
    val analysis: AnalysisDto? = null,
    /** ISS не ответила — биржевых данных нет. */
    val error: String? = null,
)

@Serializable
data class DescriptionFieldDto(val name: String, val title: String, val value: String)

@Serializable
data class DividendDto(val date: String, val value: Double, val currency: String = "RUB")

@Serializable
data class ChartPointDto(val date: String, val close: Double)

@Serializable
data class InstrumentNewsDto(
    val id: Long,
    val source: String,
    val sourceTitle: String? = null,
    val title: String,
    val link: String,
    val time: String,
    val market: List<MarketImpactDto> = emptyList(),
)

/** Технические индикаторы по дневным свечам; null — данных не хватило. */
@Serializable
data class IndicatorsDto(
    val date: String,
    val close: Double,
    val returns: ReturnsDto,
    val sma20: Double? = null,
    val sma50: Double? = null,
    val sma200: Double? = null,
    val vsSma20: Double? = null,
    val vsSma50: Double? = null,
    val vsSma200: Double? = null,
    /** up, down, sideways. */
    val trend: String? = null,
    val smaCross: SmaCrossDto? = null,
    val rsi14: Double? = null,
    val macd: MacdDto? = null,
    val bollinger: BollingerDto? = null,
    val volatility: Double? = null,
    val high52: Double,
    val low52: Double,
    val fromHigh52: Double? = null,
    val fromLow52: Double? = null,
    val maxDrawdownYear: Double? = null,
    val support: Double,
    val resistance: Double,
    val volumeRatio: Double? = null,
)

@Serializable
data class ReturnsDto(
    val week: Double? = null,
    val month: Double? = null,
    val quarter: Double? = null,
    val halfYear: Double? = null,
    val year: Double? = null,
)

/** golden — 50-дневная пересекла 200-дневную снизу вверх, death — сверху вниз. */
@Serializable
data class SmaCrossDto(val type: String, val barsAgo: Int)

/** cross: bullish, bearish или null. */
@Serializable
data class MacdDto(val line: Double, val signal: Double, val histogram: Double, val cross: String? = null)

@Serializable
data class BollingerDto(val upper: Double, val middle: Double, val lower: Double, val percentB: Double? = null)
