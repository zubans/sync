package com.example.contactsync.launcher

import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.doubleOrNull
import kotlinx.serialization.json.jsonArray
import kotlinx.serialization.json.jsonObject
import kotlinx.serialization.json.jsonPrimitive
import java.time.Instant
import java.time.LocalDate
import java.time.LocalDateTime
import java.time.ZoneId

/** Город для погоды: координаты нужны прогнозу, имя — для подписи. */
data class City(val name: String, val latitude: Double, val longitude: Double) {
    companion object {
        val MOSCOW = City("Москва", 55.7558, 37.6173)
    }
}

data class Weather(
    val temperature: Double,
    /** Ветер, м/с. */
    val wind: Double,
    val condition: WeatherCondition,
    /** Ближайшие часы с шагом 3 часа, начиная со следующего часа. */
    val hours: List<HourForecast>,
    /** Следующие дни, без сегодняшнего. */
    val days: List<DayForecast>,
)

data class HourForecast(val time: LocalDateTime, val temperature: Double, val condition: WeatherCondition)

data class DayForecast(val date: LocalDate, val min: Double, val max: Double, val condition: WeatherCondition)

enum class WeatherCondition(val title: String, val symbol: String) {
    CLEAR("ясно", "☀️"),
    PARTLY_CLOUDY("переменная облачность", "⛅"),
    CLOUDY("облачно", "☁️"),
    FOG("туман", "🌫️"),
    DRIZZLE("небольшой дождь", "🌦️"),
    RAIN("дождь", "🌧️"),
    SNOW("снег", "🌨️"),
    THUNDERSTORM("гроза", "⛈️");

    companion object {
        /** Код погоды MET Norway: «lightrainshowers_day», «cloudy», «heavysnow»… */
        fun fromMetNo(symbol: String): WeatherCondition {
            val code = symbol.substringBefore("_")
            return when {
                "thunder" in code -> THUNDERSTORM
                "snow" in code || "sleet" in code -> SNOW
                code.startsWith("light") && "rain" in code -> DRIZZLE
                "rain" in code -> RAIN
                code == "fog" -> FOG
                code == "clearsky" -> CLEAR
                code == "fair" || code == "partlycloudy" -> PARTLY_CLOUDY
                else -> CLOUDY
            }
        }
    }
}

/**
 * Разбор прогноза MET Norway (locationforecast/2.0/compact). Прогноз идёт рядом точек во времени UTC:
 * первые двое суток — почасово, дальше — через 6 часов. Дни собираем сами: минимум и максимум
 * за местные сутки, погода — по точке, ближайшей к полудню.
 */
object WeatherParser {

    fun parse(root: JsonObject, now: Instant, zone: ZoneId, hourCount: Int = 5, dayCount: Int = 4): Weather {
        val points = root.getValue("properties").jsonObject.getValue("timeseries").jsonArray.map { it.jsonObject }
            .map { entry ->
                val data = entry.getValue("data").jsonObject
                val details = data.getValue("instant").jsonObject.getValue("details").jsonObject
                // Погода «на ближайший час», а где её нет (дальний прогноз) — на 6 или 12 часов.
                val symbol = listOf("next_1_hours", "next_6_hours", "next_12_hours").firstNotNullOfOrNull {
                    data[it]?.jsonObject?.get("summary")?.jsonObject?.get("symbol_code")?.jsonPrimitive?.content
                }
                Point(
                    time = Instant.parse(entry.getValue("time").jsonPrimitive.content).atZone(zone).toLocalDateTime(),
                    temperature = details.number("air_temperature"),
                    wind = details.number("wind_speed"),
                    condition = symbol?.let(WeatherCondition::fromMetNo),
                )
            }
        val local = now.atZone(zone).toLocalDateTime()
        val current = points.lastOrNull { !it.time.isAfter(local) } ?: points.first()

        val hours = points
            .filter { it.time.isAfter(local) && it.condition != null }
            .filterIndexed { n, _ -> n % 3 == 0 }
            .take(hourCount)
            .map { HourForecast(it.time, it.temperature, it.condition!!) }

        val days = points.groupBy { it.time.toLocalDate() }
            .filterKeys { it.isAfter(local.toLocalDate()) }
            .toSortedMap()
            .values
            .take(dayCount)
            .mapNotNull { day ->
                val noon = day.filter { it.condition != null }.minByOrNull { Math.abs(it.time.hour - 12) } ?: return@mapNotNull null
                DayForecast(
                    date = noon.time.toLocalDate(),
                    min = day.minOf { it.temperature },
                    max = day.maxOf { it.temperature },
                    condition = noon.condition!!,
                )
            }

        return Weather(
            temperature = current.temperature,
            wind = current.wind,
            condition = current.condition ?: WeatherCondition.CLOUDY,
            hours = hours,
            days = days,
        )
    }

    /** Результаты геокодера Open-Meteo: «Город, регион». */
    fun cities(root: JsonObject): List<City> =
        root["results"]?.jsonArray.orEmpty().map { it.jsonObject }.map {
            val name = it.getValue("name").jsonPrimitive.content
            val region = it["admin1"]?.jsonPrimitive?.content
            City(
                name = if (region != null && region != name) "$name, $region" else name,
                latitude = it.number("latitude"),
                longitude = it.number("longitude"),
            )
        }

    private class Point(val time: LocalDateTime, val temperature: Double, val wind: Double, val condition: WeatherCondition?)

    private fun JsonObject.number(key: String): Double = this[key]?.jsonPrimitive?.doubleOrNull ?: 0.0
}
