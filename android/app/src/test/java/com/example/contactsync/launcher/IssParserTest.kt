package com.example.contactsync.launcher

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class IssParserTest {

    private fun json(text: String): JsonObject = Json.parseToJsonElement(text).jsonObject

    @Test
    fun `share quote takes last price and change to previous close`() {
        val root = json(
            """
            {"securities": {"columns": ["SECID", "SHORTNAME", "PREVPRICE", "DECIMALS", "CURRENCYID"],
                            "data": [["SBER", "Сбербанк", 285.02, 2, "SUR"]]},
             "marketdata": {"columns": ["SECID", "LAST", "LASTTOPREVPRICE"],
                            "data": [["SBER", 286.11, 0.38]]}}
            """,
        )
        val quote = IssParser.quotes(root, "shares").single()
        assertEquals("Сбербанк", quote.name)
        assertEquals(286.11, quote.price!!, 0.0)
        assertEquals(0.38, quote.changePercent!!, 0.0)
        assertEquals(PriceUnit.RUBLE, quote.unit)
        assertNull(quote.yield)
    }

    @Test
    fun `before first trade shows previous close without change`() {
        val root = json(
            """
            {"securities": {"columns": ["SECID", "SHORTNAME", "PREVPRICE"], "data": [["LKOH", "ЛУКОЙЛ", 6107.5]]},
             "marketdata": {"columns": ["SECID", "LAST", "LASTTOPREVPRICE"], "data": [["LKOH", null, null]]}}
            """,
        )
        val quote = IssParser.quotes(root, "shares").single()
        assertEquals(6107.5, quote.price!!, 0.0)
        assertNull(quote.changePercent)
    }

    @Test
    fun `bond is priced in percent of face value with yield`() {
        val root = json(
            """
            {"securities": {"columns": ["SECID", "SHORTNAME", "PREVPRICE"], "data": [["SU26238RMFS4", "ОФЗ 26238", 50.724]]},
             "marketdata": {"columns": ["SECID", "LAST", "LASTTOPREVPRICE", "YIELD"], "data": [["SU26238RMFS4", 50.825, 0.2, 16.45]]}}
            """,
        )
        val quote = IssParser.quotes(root, "bonds").single()
        assertEquals(PriceUnit.PERCENT_OF_FACE, quote.unit)
        assertEquals(16.45, quote.yield!!, 0.0)
    }

    @Test
    fun `index uses current value and its own change column`() {
        val root = json(
            """
            {"securities": {"columns": ["SECID", "SHORTNAME"], "data": [["IMOEX", "Индекс МосБиржи"]]},
             "marketdata": {"columns": ["SECID", "CURRENTVALUE", "LASTCHANGEPRC"], "data": [["IMOEX", 2401.78, 3.47]]}}
            """,
        )
        val quote = IssParser.quotes(root, "index").single()
        assertEquals(PriceUnit.POINTS, quote.unit)
        assertEquals(2401.78, quote.price!!, 0.0)
        assertEquals(3.47, quote.changePercent!!, 0.0)
    }

    @Test
    fun `primary board defines where to ask for quotes`() {
        val root = json(
            """
            {"boards": {"columns": ["secid", "boardid", "market", "engine", "is_primary"],
                        "data": [["SBER", "SMAL", "shares", "stock", 0], ["SBER", "TQBR", "shares", "stock", 1]]}}
            """,
        )
        assertEquals(Instrument("SBER", "stock", "shares", "TQBR"), IssParser.primaryBoard(root, "SBER"))
    }

    @Test
    fun `search keeps only traded securities`() {
        val root = json(
            """
            {"securities": {"columns": ["secid", "shortname", "name", "is_traded"],
                            "data": [["SBER", "Сбербанк", "Сбербанк России ПАО ао", 1], ["OLD1", "Старая", "Погашена", 0]]}}
            """,
        )
        assertEquals(listOf("SBER"), IssParser.searchHits(root).map { it.secid })
    }

    @Test
    fun `instrument survives settings round trip`() {
        val instrument = Instrument("USD000UTSTOM", "currency", "selt", "CETS")
        assertEquals(instrument, Instrument.decode(instrument.encode()))
    }
}
