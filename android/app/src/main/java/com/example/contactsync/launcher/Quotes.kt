package com.example.contactsync.launcher

import kotlinx.serialization.json.JsonArray
import kotlinx.serialization.json.JsonElement
import kotlinx.serialization.json.JsonNull
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.JsonPrimitive
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.intOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject

/**
 * Инструмент Мосбиржи, выбранный для главного экрана. Котировки ISS отдаёт по режиму торгов,
 * поэтому вместе с кодом храним основной режим (engine/market/board) — его определяем один раз
 * при добавлении.
 */
data class Instrument(
    /** Код бумаги в ISS: тикер (SBER), ISIN облигации (SU26238RMFS4), индекс (IMOEX), валюта (USD000UTSTOM). */
    val secid: String,
    val engine: String,
    val market: String,
    val board: String,
) {
    /** Запись в настройках: поля через «|». */
    fun encode() = listOf(secid, engine, market, board).joinToString("|")

    companion object {
        fun decode(value: String): Instrument? =
            value.split("|").takeIf { it.size == 4 }?.let { Instrument(it[0], it[1], it[2], it[3]) }
    }
}

/** Котировка для карточки на главном экране. */
data class Quote(
    val secid: String,
    val name: String,
    /** Последняя цена; для облигаций — в процентах от номинала. null — торгов ещё не было. */
    val price: Double?,
    /** Изменение к закрытию прошлого дня, %. */
    val changePercent: Double?,
    /** Доходность к погашению, % — только у облигаций. */
    val yield: Double?,
    val unit: PriceUnit,
    /** Знаков после запятой, как у цены на бирже. */
    val decimals: Int,
)

enum class PriceUnit { RUBLE, PERCENT_OF_FACE, POINTS, OTHER }

/** Найденный при поиске инструмент. */
data class SecuritySearchHit(val secid: String, val shortName: String, val name: String)

/**
 * Разбор ответов ISS. ISS отдаёт таблицы в виде {columns: [...], data: [[...], ...]};
 * набор колонок marketdata зависит от рынка, поэтому цену и изменение ищем по нескольким именам.
 */
object IssParser {

    /** Строки таблицы ISS как словари «колонка → значение». */
    fun table(root: JsonObject, name: String): List<Map<String, JsonElement>> {
        val block = root[name]?.jsonObject ?: return emptyList()
        val columns = block["columns"]?.jsonArray?.map { (it as JsonPrimitive).content } ?: return emptyList()
        return block["data"]?.jsonArray.orEmpty().map { row -> columns.zip((row as JsonArray)).toMap() }
    }

    /** Котировки всех бумаг одного режима торгов: securities + marketdata. */
    fun quotes(root: JsonObject, market: String): List<Quote> {
        val marketData = table(root, "marketdata").associateBy { it.text("SECID") }
        return table(root, "securities").mapNotNull { sec ->
            val secid = sec.text("SECID") ?: return@mapNotNull null
            quote(secid, market, sec, marketData[secid].orEmpty())
        }
    }

    fun quote(secid: String, market: String, sec: Map<String, JsonElement>, md: Map<String, JsonElement>): Quote {
        val unit = when {
            market == "bonds" -> PriceUnit.PERCENT_OF_FACE
            market == "index" -> PriceUnit.POINTS
            sec.text("CURRENCYID").let { it == null || it == "SUR" || it == "RUB" } -> PriceUnit.RUBLE
            else -> PriceUnit.OTHER
        }
        val last = md.number("LAST") ?: md.number("CURRENTVALUE") ?: md.number("LASTVALUE")
        return Quote(
            secid = secid,
            name = sec.text("SHORTNAME") ?: secid,
            // Пока торгов не было (утро, выходные), показываем цену закрытия без изменения.
            price = last ?: sec.number("PREVPRICE"),
            changePercent = if (last != null) md.number("LASTTOPREVPRICE") ?: md.number("LASTCHANGEPRC") else null,
            yield = if (market == "bonds") md.number("YIELD") else null,
            unit = unit,
            decimals = (sec["DECIMALS"] as? JsonPrimitive)?.intOrNull ?: 2,
        )
    }

    /** Основной режим торгов бумаги из /iss/securities/{secid}.json?iss.only=boards. */
    fun primaryBoard(root: JsonObject, secid: String): Instrument? =
        table(root, "boards").firstOrNull { it.number("is_primary") == 1.0 }?.let {
            Instrument(
                secid = secid,
                engine = it.text("engine") ?: return null,
                market = it.text("market") ?: return null,
                board = it.text("boardid") ?: return null,
            )
        }

    /** Результаты поиска /iss/securities.json?q=… — только бумаги, которые сейчас торгуются. */
    fun searchHits(root: JsonObject): List<SecuritySearchHit> =
        table(root, "securities")
            .filter { it.number("is_traded") == 1.0 }
            .mapNotNull {
                SecuritySearchHit(
                    secid = it.text("secid") ?: return@mapNotNull null,
                    shortName = it.text("shortname").orEmpty(),
                    name = it.text("name").orEmpty(),
                )
            }

    private fun Map<String, JsonElement>.text(key: String): String? =
        (this[key] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.content

    private fun Map<String, JsonElement>.number(key: String): Double? =
        (this[key] as? JsonPrimitive)?.takeIf { it !is JsonNull }?.doubleOrNull
}
