package com.example.contactsync.launcher

import com.example.contactsync.data.AnalysisDto
import com.example.contactsync.data.LotDto
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test
import java.time.Instant
import java.time.LocalDate

class PortfolioTest {

    @Test
    fun `position return follows price from purchase price of each lot`() {
        // Облигации: цена — процент от номинала, в сумме — НКД.
        val lots = listOf(
            Lot(1, LocalDate.parse("2026-03-01"), 10.0, 60.0, 6100.0),
            Lot(2, LocalDate.parse("2026-06-01"), 10.0, 50.0, 5000.0),
        )

        val p = Portfolio.position(lots, 66.0)!!

        // 6100 × 66/60 + 5000 × 66/50 = 6710 + 6600 — так же считает сервер.
        assertEquals(13310.0, p.value!!, 1e-9)
        assertEquals(11100.0, p.amount, 1e-9)
        assertEquals(2210.0, p.profit!!, 1e-9)
        assertEquals(19.909, p.profitPercent!!, 1e-3)
        assertEquals(55.0, p.averagePrice, 1e-9)
    }

    @Test
    fun `position without price or lots`() {
        val p = Portfolio.position(listOf(Lot(1, LocalDate.parse("2026-03-01"), 1.0, 100.0, 100.0)), null)!!
        assertNull(p.value)
        assertNull(p.profitPercent)
        assertNull(Portfolio.position(emptyList(), 100.0))
    }

    @Test
    fun `default amount of bonds uses face value`() {
        assertEquals(9850.0, Portfolio.defaultAmount(10.0, 98.5, PriceUnit.PERCENT_OF_FACE, null), 1e-9)
        assertEquals(492.5, Portfolio.defaultAmount(1.0, 98.5, PriceUnit.PERCENT_OF_FACE, 500.0), 1e-9)
        assertEquals(28050.0, Portfolio.defaultAmount(100.0, 280.5, PriceUnit.RUBLE, null), 1e-9)
    }

    @Test
    fun `move puts dragged item to new place`() {
        assertEquals(listOf("B", "C", "A"), Portfolio.move(listOf("A", "B", "C"), 0, 2))
        assertEquals(listOf("C", "A", "B"), Portfolio.move(listOf("A", "B", "C"), 2, 0))
        assertEquals(listOf("A", "B"), Portfolio.move(listOf("A", "B"), 0, 5))
    }

    @Test
    fun `analysis from server`() {
        val a = Portfolio.analysis(
            AnalysisDto(recommendation = "buy", outlook = "down", confidence = 55, urgency = "act", analyzedAt = "2026-10-14T09:00:00+00:00", pending = true),
        )
        assertEquals(Recommendation.BUY, a.recommendation)
        assertEquals(Outlook.DOWN, a.outlook)
        assertEquals(Urgency.ACT, a.urgency)
        assertEquals(Instant.parse("2026-10-14T09:00:00Z"), a.analyzedAt)
        assertEquals(true, a.pending)

        // Анализа ещё не было — только запрос.
        val empty = Portfolio.analysis(AnalysisDto(pending = true))
        assertNull(empty.recommendation)
        assertEquals(Urgency.NONE, empty.urgency)
        assertEquals(LocalDate.parse("2026-05-01"), Portfolio.lot(LotDto(1, "SBER", "2026-05-01", 100.0, 280.0, 28000.0)).boughtOn)
    }

    @Test
    fun `labels depend on position`() {
        assertEquals("Купить", Portfolio.label(Recommendation.BUY, hasPosition = false))
        assertEquals("Докупить", Portfolio.label(Recommendation.BUY, hasPosition = true))
        assertEquals("Продать", Portfolio.label(Recommendation.SELL, hasPosition = true))
        assertEquals("Наблюдать", Portfolio.label(Recommendation.WAIT, hasPosition = false))
    }
}
