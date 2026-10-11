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
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
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
    /** Заголовки по изданиям; издание, которое не ответило, сохраняет прошлые заголовки. */
    val news: Map<NewsSource, List<NewsItem>> = emptyMap(),
    /** Издания, которые при последнем обновлении не ответили. */
    val newsFailed: Set<NewsSource> = emptySet(),
    /** Выбранное издание; null — все вместе. */
    val newsSource: NewsSource? = null,
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
 * погоду раз в полчаса, новости раз в 10 минут, ленту событий раз в минуту
 * (подписи «через N мин» меняются со временем).
 */
class LauncherViewModel(application: Application) : AndroidViewModel(application) {

    private val api = LauncherApi()
    private val settings = LauncherSettings(application)
    private val calendar = CalendarSource(application)
    private val vpnControl = VpnControl(application.filesDir)

    private val _state = MutableStateFlow(LauncherState(city = settings.city, newsSource = settings.newsSource))
    val state: StateFlow<LauncherState> = _state.asStateFlow()

    private var refreshJob: Job? = null
    private var vpnJob: Job? = null
    private var weatherAt = 0L
    private var newsAt = 0L
    private var newsJob: Job? = null
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
                // Отдельной корутиной: медленное издание не должно задерживать котировки.
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

    fun selectNewsSource(source: NewsSource?) {
        settings.newsSource = source
        _state.update { it.copy(newsSource = source) }
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
     * Издания опрашиваются параллельно и независимо: западные сайты из нашей сети бывают
     * недоступны, и это не должно оставлять без новостей РГ и РБК. Пометку «нет связи» общий
     * флаг не ставит — недоступные издания отмечаются в самом виджете.
     */
    private suspend fun refreshNews() {
        val results = coroutineScope {
            NewsSource.entries.map { source ->
                async {
                    source to try {
                        api.news(source)
                    } catch (e: CancellationException) {
                        throw e
                    } catch (e: Exception) {
                        null
                    }
                }
            }.awaitAll()
        }
        // Время — после ответа: прерванное уходом с экрана обновление повторится при возврате.
        newsAt = System.currentTimeMillis()
        _state.update { s ->
            s.copy(
                news = s.news + results.mapNotNull { (source, items) -> items?.let { source to it } },
                newsFailed = results.filter { it.second == null }.map { it.first }.toSet(),
            )
        }
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
        const val NEWS_TTL_MS = 10 * 60_000L
        const val VPN_TICK_MS = 10_000L
        const val VPN_SWITCH_TICK_MS = 2_000L
        const val CALENDAR_SYNC_MS = 5 * 60_000L
    }
}
