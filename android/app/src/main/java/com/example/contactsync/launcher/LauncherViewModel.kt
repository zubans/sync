package com.example.contactsync.launcher

import android.Manifest
import android.app.Application
import android.content.ComponentName
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.asImageBitmap
import androidx.core.content.ContextCompat
import androidx.core.graphics.drawable.toBitmap
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import com.example.contactsync.App
import com.example.contactsync.calendar.CalendarAccount
import com.example.contactsync.data.UnauthorizedException
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.ZoneId

/** Приложение в списке «Все приложения» и в доке. */
data class LauncherApp(val label: String, val component: ComponentName, val icon: ImageBitmap) {
    /** Ключ в доке: компонент, а не пакет — у одного пакета бывает несколько иконок. */
    val dockKey: String get() = component.flattenToShortString()
}

data class LauncherState(
    val city: City,
    val weather: Weather? = null,
    val quotes: List<Quote> = emptyList(),
    /** Когда котировки последний раз пришли с биржи, мс; 0 — ещё ни разу. */
    val quotesAt: Long = 0,
    /** Все события на 14 дней: карточка показывает первые, подробный экран — все. */
    val agenda: List<AgendaRow> = emptyList(),
    /** Новости выбранной вкладки с сервера sync; если сервер не ответил, остаются прежние. */
    val news: NewsPage = NewsPage(emptyList(), null),
    /** Издания — вкладки виджета, список с сервера. */
    val newsSources: List<NewsSource> = emptyList(),
    /** Код выбранного издания; null — все вместе. */
    val newsSource: String? = null,
    /** Формат сводки: сюжеты или одна статья. */
    val digestFormat: DigestFormat = DigestFormat.STORIES,
    /** Сводка, открытая для чтения на весь экран; null — не открыта. */
    val reading: NewsItem? = null,
    /** Почему новостей нет: не вошли в sync или сервер не ответил; null — всё в порядке. */
    val newsError: String? = null,
    val calendarAllowed: Boolean = false,
    val apps: List<LauncherApp> = emptyList(),
    val dock: List<LauncherApp> = emptyList(),
    val vpn: VpnState = VpnState.DOWN,
    /** Адрес планшета в туннеле, для подробностей у лампочки. */
    val vpnAddress: String? = null,
    /** Туннель уже проверяли: до первой проверки [vpn] — просто значение по умолчанию. */
    val vpnChecked: Boolean = false,
    /** Служба под root, через которую туннель включается и выключается, запущена. */
    val vpnControl: Boolean = false,
    /** Выбор пользователя: true — включить, false — выключить, null — не переключал. */
    val vpnWanted: Boolean? = null,
    /** Последняя ошибка сети — показываем мелко, данные на экране остаются прежними. */
    val offline: Boolean = false,
)

/**
 * Главный экран обновляет данные, только пока он на экране: котировки раз в минуту,
 * погоду раз в полчаса, новости раз в 5 минут (сервер собирает их с той же частотой),
 * ленту событий раз в минуту (подписи «через N мин» меняются со временем).
 */
class LauncherViewModel(application: Application) : AndroidViewModel(application) {

    private val api = LauncherApi()
    /** API сервера sync — с него приходят новости. */
    private val syncApi = (application as App).api
    private val settings = LauncherSettings(application)
    private val calendar = CalendarSource(application)
    private val vpnControl = VpnControl(application.filesDir)

    private val _state = MutableStateFlow(
        LauncherState(city = settings.city, newsSource = settings.newsSource, digestFormat = settings.digestFormat),
    )
    val state: StateFlow<LauncherState> = _state.asStateFlow()

    private var refreshJob: Job? = null
    private var vpnJob: Job? = null
    private var weatherAt = 0L
    private var newsAt = 0L
    private var newsJob: Job? = null
    private var moreNewsJob: Job? = null
    /** Последние загруженные новости каждой вкладки: при переключении показываются сразу, до ответа сервера. */
    private val newsCache = HashMap<String?, NewsPage>()
    private var calendarSyncAt = 0L

    fun onResume() {
        loadApps()
        requestCalendarSync()
        refreshJob?.cancel()
        refreshJob = viewModelScope.launch {
            while (isActive) {
                refreshAgenda()
                if (System.currentTimeMillis() - weatherAt > WEATHER_TTL_MS) refreshWeather()
                refreshQuotes()
                // Отдельной корутиной: медленный ответ сервера не должен задерживать котировки.
                if (System.currentTimeMillis() - newsAt > NEWS_TTL_MS && newsJob?.isActive != true) {
                    newsJob = launch { refreshNews() }
                }
                delay(TICK_MS)
            }
        }

        startVpnWatch()
    }

    /**
     * Туннель проверяем чаще остального: лампочка должна погаснуть вскоре после обрыва.
     * Пока переключение не дошло до туннеля — каждые 2 с.
     */
    private fun startVpnWatch() {
        vpnJob?.cancel()
        vpnJob = viewModelScope.launch {
            while (isActive) {
                val kb = Uri.parse((getApplication<Application>() as App).kbSession.baseUrl)
                val probe = VpnLamp.probe(kb.host.orEmpty(), kb.port.takeIf { it > 0 } ?: 80)
                val (wanted, control) = withContext(Dispatchers.IO) {
                    vpnControl.wanted() to vpnControl.available(System.currentTimeMillis() / 1000)
                }
                val vpn = VpnLamp.state(probe)
                _state.update {
                    it.copy(vpn = vpn, vpnAddress = probe.address, vpnChecked = true, vpnWanted = wanted, vpnControl = control)
                }
                delay(if (control && VpnLamp.switching(wanted, vpn)) VPN_SWITCH_TICK_MS else VPN_TICK_MS)
            }
        }
    }

    /** Включить или выключить туннель — через службу под root, см. [VpnControl]. */
    fun setVpn(on: Boolean) {
        viewModelScope.launch {
            withContext(Dispatchers.IO) { vpnControl.request(on) }
            _state.update { it.copy(vpnWanted = on) }
            startVpnWatch()
        }
    }

    fun onPause() {
        refreshJob?.cancel()
        vpnJob?.cancel()
    }

    fun onCalendarPermission() {
        calendarSyncAt = 0
        requestCalendarSync()
        viewModelScope.launch { refreshAgenda() }
    }

    /**
     * Свежие события с сервера sync: просим систему синхронизировать календарь при возврате
     * на главный экран, но не чаще раза в 5 минут (по расписанию — раз в час).
     */
    private fun requestCalendarSync() {
        if (System.currentTimeMillis() - calendarSyncAt < CALENDAR_SYNC_MS) return
        calendarSyncAt = System.currentTimeMillis()
        CalendarAccount.requestSync(getApplication())
    }

    fun setCity(city: City) {
        settings.city = city
        weatherAt = 0
        _state.update { it.copy(city = city, weather = null) }
        viewModelScope.launch { refreshWeather() }
    }

    suspend fun searchCities(query: String): List<City> = api.searchCities(query)

    suspend fun searchSecurities(query: String): List<SecuritySearchHit> = api.searchSecurities(query)

    /** Добавить бумагу; false — у неё нет режима торгов, котировок не будет. */
    suspend fun addInstrument(secid: String): Boolean {
        if (settings.instruments.any { it.secid == secid }) return true
        val instrument = api.resolveInstrument(secid) ?: return false
        settings.instruments = settings.instruments + instrument
        refreshQuotes()
        return true
    }

    fun removeInstrument(secid: String) {
        settings.instruments = settings.instruments.filterNot { it.secid == secid }
        _state.update { s -> s.copy(quotes = s.quotes.filterNot { it.secid == secid }) }
    }

    fun selectNewsSource(code: String?) {
        settings.newsSource = code
        _state.update { it.copy(newsSource = code, news = newsCache[code] ?: NewsPage(emptyList(), null)) }
        moreNewsJob?.cancel()
        newsJob?.cancel()
        newsJob = viewModelScope.launch { refreshNews() }
    }

    fun setDigestFormat(format: DigestFormat) {
        settings.digestFormat = format
        _state.update { it.copy(digestFormat = format) }
    }

    /**
     * Новость на весь экран — нажатие на неё в карточке или в списке. Полный текст берётся с сервера:
     * он хранит статьи, скачанные со страниц изданий (`app:news:articles`).
     */
    fun read(item: NewsItem?) {
        _state.update { it.copy(reading = item) }
        if (item == null || item.digest != null) return
        viewModelScope.launch {
            val full = try {
                NewsFeed.item(syncApi.newsItem(item.id)).copy(loaded = true)
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                item.copy(loaded = true)
            }
            _state.update { s -> if (s.reading?.id == item.id) s.copy(reading = full) else s }
        }
    }

    /** Следующая страница вкладки — когда подробный экран долистали до конца. */
    fun loadMoreNews() {
        val code = _state.value.newsSource
        val cursor = _state.value.news.nextCursor ?: return
        if (moreNewsJob?.isActive == true) return
        moreNewsJob = viewModelScope.launch {
            try {
                val next = newsPage(code, cursor)
                _state.update { s ->
                    if (s.newsSource != code) return@update s
                    val page = NewsFeed.append(s.news, next)
                    newsCache[code] = page
                    s.copy(news = page)
                }
            } catch (e: CancellationException) {
                throw e
            } catch (e: Exception) {
                // Не догрузили — попробуем, когда снова долистают до конца.
            }
        }
    }

    fun toggleDock(app: LauncherApp) {
        val key = app.dockKey
        settings.dock = if (key in settings.dock) settings.dock - key else settings.dock + key
        _state.update { it.copy(dock = dockApps(it.apps)) }
    }

    fun isInDock(app: LauncherApp) = app.dockKey in settings.dock

    private suspend fun refreshAgenda() {
        // Запись нужна календарю sync (SyncAdapter пишет события), поэтому просим оба разрешения.
        val allowed = listOf(Manifest.permission.READ_CALENDAR, Manifest.permission.WRITE_CALENDAR).all {
            ContextCompat.checkSelfPermission(getApplication(), it) == PackageManager.PERMISSION_GRANTED
        }
        val rows = if (allowed) {
            withContext(Dispatchers.IO) { Agenda.rows(calendar.upcoming(), Instant.now(), ZoneId.systemDefault(), limit = Int.MAX_VALUE) }
        } else {
            emptyList()
        }
        _state.update { it.copy(agenda = rows, calendarAllowed = allowed) }
    }

    private suspend fun refreshWeather() = network {
        val weather = api.weather(settings.city)
        weatherAt = System.currentTimeMillis()
        _state.update { it.copy(weather = weather) }
    }

    private suspend fun refreshQuotes() = network {
        val quotes = api.quotes(settings.instruments)
        _state.update { it.copy(quotes = quotes, quotesAt = System.currentTimeMillis()) }
    }

    /**
     * Новости собирает сервер sync, виджет берёт их оттуда: издания, их состояние и первую страницу
     * выбранной вкладки. Пометку «нет связи» общий флаг не ставит — ошибка показывается в самом виджете,
     * а прежние новости остаются.
     */
    private suspend fun refreshNews() {
        val code = _state.value.newsSource
        try {
            val sources = syncApi.newsSources().map(NewsFeed::source)
            val page = newsPage(code, before = null)
            newsCache[code] = page
            // Время — после ответа: прерванное уходом с экрана обновление повторится при возврате.
            newsAt = System.currentTimeMillis()
            _state.update { s ->
                if (s.newsSource != code) s.copy(newsSources = sources) else s.copy(newsSources = sources, news = page, newsError = null)
            }
        } catch (e: CancellationException) {
            throw e
        } catch (e: UnauthorizedException) {
            _state.update { it.copy(newsError = "Войдите в sync, чтобы видеть новости") }
        } catch (e: Exception) {
            _state.update { it.copy(newsError = "Сервер новостей не ответил") }
        }
    }

    private suspend fun newsPage(code: String?, before: String?): NewsPage {
        val response = syncApi.news(code, NEWS_PAGE, before)
        return NewsPage(response.items.map(NewsFeed::item), response.nextCursor)
    }

    private suspend fun network(block: suspend () -> Unit) {
        try {
            block()
            _state.update { it.copy(offline = false) }
        } catch (e: CancellationException) {
            throw e
        } catch (e: Exception) {
            _state.update { it.copy(offline = true) }
        }
    }

    private fun loadApps() = viewModelScope.launch {
        val apps = withContext(Dispatchers.IO) {
            val pm = getApplication<Application>().packageManager
            val self = getApplication<Application>().packageName
            val iconSize = (48 * getApplication<Application>().resources.displayMetrics.density).toInt()
            pm.queryIntentActivities(Intent(Intent.ACTION_MAIN).addCategory(Intent.CATEGORY_LAUNCHER), 0)
                .map { info ->
                    LauncherApp(
                        // Главный экран приложения sync подписан понятнее, чем «Contact Sync»;
                        // у остальных его иконок («База знаний», «Файлы») — свои подписи.
                        label = if (info.activityInfo.name == "$self.ui.MainActivity") "Синхронизация" else info.loadLabel(pm).toString(),
                        component = ComponentName(info.activityInfo.packageName, info.activityInfo.name),
                        icon = info.loadIcon(pm).toBitmap(iconSize, iconSize).asImageBitmap(),
                    )
                }
                .sortedBy { it.label.lowercase() }
        }
        migrateDock()
        _state.update { it.copy(apps = apps, dock = dockApps(apps)) }
    }

    private fun dockApps(apps: List<LauncherApp>): List<LauncherApp> {
        val byKey = apps.associateBy { it.dockKey }
        return settings.dock.mapNotNull { byKey[it] }
    }

    /**
     * Раньше док хранил пакеты, но у одного пакета бывает несколько иконок (у sync — «Синхронизация»,
     * «База знаний», «Файлы»), и док показывал случайную из них. Теперь хранится компонент;
     * старая запись-пакет заменяется главной иконкой пакета. Пакеты, которых нет на устройстве,
     * остаются как есть — вдруг приложение поставят позже.
     */
    private fun migrateDock() {
        val pm = getApplication<Application>().packageManager
        val migrated = settings.dock.map { entry ->
            if ('/' in entry) entry else pm.getLaunchIntentForPackage(entry)?.component?.flattenToShortString() ?: entry
        }.distinct()
        if (migrated != settings.dock) settings.dock = migrated
    }

    private companion object {
        const val TICK_MS = 60_000L
        const val WEATHER_TTL_MS = 30 * 60_000L
        const val NEWS_TTL_MS = 5 * 60_000L
        /** Столько новостей приходит за раз; на подробном экране следующие догружаются при прокрутке. */
        const val NEWS_PAGE = 50
        const val VPN_TICK_MS = 10_000L
        const val VPN_SWITCH_TICK_MS = 2_000L
        const val CALENDAR_SYNC_MS = 5 * 60_000L
    }
}
