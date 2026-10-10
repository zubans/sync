package com.example.contactsync.launcher

import kotlinx.serialization.json.Json
import kotlinx.serialization.json.jsonObject
import org.junit.Assert.assertEquals
import org.junit.Test
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId

class WeatherParserTest {
    private val zone = ZoneId.of("Europe/Moscow")

    /** Почасовой прогноз от 10.10 06:00 UTC на 60 часов: температура = номер часа, днём ясно, ночью снег. */
    private val root = Json.parseToJsonElement(
        (0 until 60).joinToString(prefix = """{"properties": {"timeseries": [""", postfix = "]}}") { n ->
            val time = Instant.parse("2026-10-10T06:00:00Z").plusSeconds(n * 3600L)
            val hourUtc = (6 + n) % 24
            val symbol = if (hourUtc in 6..15) "clearsky_day" else "lightsnow_night"
            """{"time": "$time", "data": {"instant": {"details": {"air_temperature": $n, "wind_speed": 4.6}},
                "next_1_hours": {"summary": {"symbol_code": "$symbol"}}}}"""
        },
    ).jsonObject

    @Test
    fun `current weather is the latest point not after now`() {
        val weather = WeatherParser.parse(root, Instant.parse("2026-10-10T08:20:00Z"), zone)
        assertEquals(2.0, weather.temperature, 0.0)
        assertEquals(4.6, weather.wind, 0.0)
        assertEquals(WeatherCondition.CLEAR, weather.condition)
    }

    @Test
    fun `hours go every three hours in local time`() {
        val weather = WeatherParser.parse(root, Instant.parse("2026-10-10T08:20:00Z"), zone)
        assertEquals(listOf(12, 15, 18, 21, 0), weather.hours.map { it.time.hour })
    }

    @Test
    fun `days skip today and take min max over local day with noon condition`() {
        val weather = WeatherParser.parse(root, Instant.parse("2026-10-10T08:20:00Z"), zone)
        val tomorrow = weather.days.first()
        assertEquals(LocalDate.of(2026, 10, 11), tomorrow.date)
        // Местные сутки 11.10 — это 10.10 21:00 UTC … 11.10 20:00 UTC, то есть точки 15…38.
        assertEquals(15.0, tomorrow.min, 0.0)
        assertEquals(38.0, tomorrow.max, 0.0)
        assertEquals(WeatherCondition.CLEAR, tomorrow.condition)
    }

    @Test
    fun `met no symbols map to conditions`() {
        assertEquals(WeatherCondition.DRIZZLE, WeatherCondition.fromMetNo("lightrainshowers_day"))
        assertEquals(WeatherCondition.RAIN, WeatherCondition.fromMetNo("heavyrain"))
        assertEquals(WeatherCondition.SNOW, WeatherCondition.fromMetNo("sleetshowers_night"))
        assertEquals(WeatherCondition.THUNDERSTORM, WeatherCondition.fromMetNo("rainandthunder"))
        assertEquals(WeatherCondition.PARTLY_CLOUDY, WeatherCondition.fromMetNo("fair_day"))
    }

    @Test
    fun `city name includes region when it differs`() {
        val cities = Json.parseToJsonElement(
            """{"results": [{"name": "Пушкин", "admin1": "Санкт-Петербург", "latitude": 59.7, "longitude": 30.4},
                            {"name": "Москва", "admin1": "Москва", "latitude": 55.75, "longitude": 37.62}]}""",
        ).jsonObject
        assertEquals(listOf("Пушкин, Санкт-Петербург", "Москва"), WeatherParser.cities(cities).map { it.name })
    }
}
