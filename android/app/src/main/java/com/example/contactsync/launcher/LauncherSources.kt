package com.example.contactsync.launcher

import android.content.ContentUris
import android.content.Context
import android.provider.CalendarContract
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonObject
import okhttp3.HttpUrl
import okhttp3.HttpUrl.Companion.toHttpUrl
import okhttp3.OkHttpClient
import okhttp3.Request
import java.io.IOException
import java.time.Instant
import java.time.ZoneId
import java.util.Locale
import java.util.concurrent.TimeUnit

/**
 * Открытые API без ключей: MOEX ISS (котировки с задержкой 15 минут), MET Norway (прогноз),
 * геокодер Open-Meteo (поиск города).
 * Сервер sync в этих запросах не участвует.
 */
class LauncherApi(
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(20, TimeUnit.SECONDS)
        .build(),
) {
    private val json = Json { ignoreUnknownKeys = true }

    /** Котировки: один запрос на режим торгов, сколько бы бумаг в нём ни было. */
    suspend fun quotes(instruments: List<Instrument>): List<Quote> {
        val byBoard = instruments.groupBy { Triple(it.engine, it.market, it.board) }
        val quotes = byBoard.flatMap { (board, items) ->
            val (engine, market, boardId) = board
            val url = iss("engines/$engine/markets/$market/boards/$boardId/securities.json")
                .addQueryParameter("securities", items.joinToString(",") { it.secid })
                .addQueryParameter("iss.only", "securities,marketdata")
                .build()
            IssParser.quotes(get(url), market)
        }.associateBy { it.secid }
        // Порядок — как выбрал пользователь, а не как ответила биржа.
        return instruments.mapNotNull { quotes[it.secid] }
    }

    suspend fun searchSecurities(query: String): List<SecuritySearchHit> =
        IssParser.searchHits(
            get(
                iss("securities.json")
                    .addQueryParameter("q", query)
                    .addQueryParameter("limit", "20")
                    .addQueryParameter("securities.columns", "secid,shortname,name,is_traded")
                    .build(),
            ),
        )

    /** Основной режим торгов бумаги; null — у бумаги его нет (например, не торгуется). */
    suspend fun resolveInstrument(secid: String): Instrument? =
        IssParser.primaryBoard(
            get(
                iss("securities/$secid.json")
                    .addQueryParameter("iss.only", "boards")
                    .addQueryParameter("boards.columns", "secid,boardid,market,engine,is_primary")
                    .build(),
            ),
            secid,
        )

    /**
     * Прогноз MET Norway: бесплатно, без ключа, но требует User-Agent с названием приложения
     * и не больше 4 знаков в координатах. Прогноз Open-Meteo из нашей сети не отвечает (соединение
     * обрывается), его геокодер при этом работает — им ищем города.
     */
    suspend fun weather(city: City): Weather {
        val url = "https://api.met.no/weatherapi/locationforecast/2.0/compact".toHttpUrl().newBuilder()
            .addQueryParameter("lat", "%.4f".format(Locale.ROOT, city.latitude))
            .addQueryParameter("lon", "%.4f".format(Locale.ROOT, city.longitude))
            .build()
        return WeatherParser.parse(get(url), Instant.now(), ZoneId.systemDefault())
    }

    suspend fun searchCities(query: String): List<City> =
        WeatherParser.cities(
            get(
                "https://geocoding-api.open-meteo.com/v1/search".toHttpUrl().newBuilder()
                    .addQueryParameter("name", query)
                    .addQueryParameter("count", "8")
                    .addQueryParameter("language", "ru")
                    .build(),
            ),
        )

    private fun iss(path: String): HttpUrl.Builder =
        "https://iss.moex.com/iss/$path".toHttpUrl().newBuilder().addQueryParameter("iss.meta", "off")

    private suspend fun get(url: HttpUrl): JsonObject = withContext(Dispatchers.IO) {
        client.newCall(Request.Builder().url(url).header("User-Agent", USER_AGENT).build()).execute().use { response ->
            if (!response.isSuccessful) throw IOException("HTTP ${response.code}")
            json.parseToJsonElement(response.body!!.string()).jsonObject
        }
    }
}

private const val USER_AGENT = "ContactSync-Launcher/1.0"

/** Ближайшие вхождения событий из всех видимых календарей устройства. */
class CalendarSource(private val context: Context) {

    fun upcoming(days: Int = 14): List<AgendaEvent> {
        // С запасом в сутки назад: идущие события и события на весь день хранятся от полуночи UTC.
        val now = System.currentTimeMillis()
        val uri = CalendarContract.Instances.CONTENT_URI.buildUpon().also {
            ContentUris.appendId(it, now - DAY_MS)
            ContentUris.appendId(it, now + days * DAY_MS)
        }.build()
        val projection = arrayOf(
            CalendarContract.Instances.TITLE,
            CalendarContract.Instances.BEGIN,
            CalendarContract.Instances.END,
            CalendarContract.Instances.ALL_DAY,
            CalendarContract.Instances.DISPLAY_COLOR,
        )
        val events = mutableListOf<AgendaEvent>()
        context.contentResolver.query(
            uri, projection, "${CalendarContract.Instances.VISIBLE} = 1", null,
            "${CalendarContract.Instances.BEGIN} ASC",
        )?.use { c ->
            while (c.moveToNext()) {
                events += AgendaEvent(
                    title = c.getString(0).orEmpty(),
                    begin = c.getLong(1),
                    end = c.getLong(2),
                    allDay = c.getInt(3) == 1,
                    color = c.getInt(4),
                )
            }
        }
        return events
    }

    private companion object {
        const val DAY_MS = 24 * 60 * 60 * 1000L
    }
}

/** Настройки главного экрана — локально на устройстве. */
class LauncherSettings(context: Context) {
    private val prefs = context.getSharedPreferences("launcher", Context.MODE_PRIVATE)

    var city: City
        get() = prefs.getString(KEY_CITY, null)?.split("|")?.takeIf { it.size == 3 }
            ?.let { City(it[0], it[1].toDouble(), it[2].toDouble()) } ?: City.MOSCOW
        set(value) = prefs.edit().putString(KEY_CITY, "${value.name}|${value.latitude}|${value.longitude}").apply()

    var instruments: List<Instrument>
        get() = prefs.getString(KEY_INSTRUMENTS, null)
            ?.let { s -> s.split(";").mapNotNull(Instrument::decode) } ?: DEFAULT_INSTRUMENTS
        set(value) = prefs.edit().putString(KEY_INSTRUMENTS, value.joinToString(";") { it.encode() }).apply()

    /** Пакеты приложений в доке, по порядку. */
    var dock: List<String>
        get() = prefs.getString(KEY_DOCK, null)?.split(";")?.filter { it.isNotBlank() } ?: DEFAULT_DOCK
        set(value) = prefs.edit().putString(KEY_DOCK, value.joinToString(";")).apply()

    private companion object {
        const val KEY_CITY = "city"
        const val KEY_INSTRUMENTS = "instruments"
        const val KEY_DOCK = "dock"

        /** Док до первой настройки; приложения, которых нет на устройстве, просто не показываются. */
        val DEFAULT_DOCK = listOf(
            "com.example.contactsync",
            "com.android.calendar",
            "com.android.camera2",
            "com.android.gallery3d",
            "com.android.settings",
        )

        val DEFAULT_INSTRUMENTS = listOf(
            Instrument("IMOEX", "stock", "index", "SNDX"),
            Instrument("SBER", "stock", "shares", "TQBR"),
            Instrument("LKOH", "stock", "shares", "TQBR"),
            Instrument("SU26238RMFS4", "stock", "bonds", "TQOB"),
            Instrument("USD000UTSTOM", "currency", "selt", "CETS"),
        )
    }
}
