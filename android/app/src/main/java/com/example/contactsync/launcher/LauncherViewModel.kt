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
data class LauncherApp(val label: String, val component: ComponentName, val icon: ImageBitmap)

data class LauncherState(
    val city: City,
    val weather: Weather? = null,
    val quotes: List<Quote> = emptyList(),
    /** Когда котировки последний раз пришли с биржи, мс; 0 — ещё ни разу. */
    val quotesAt: Long = 0,
    val agenda: List<AgendaRow> = emptyList(),
    val calendarAllowed: Boolean = false,
    val apps: List<LauncherApp> = emptyList(),
    val dock: List<LauncherApp> = emptyList(),
    val vpn: VpnState = VpnState.DOWN,
    /** Адрес планшета в туннеле, для подробностей у лампочки. */
    val vpnAddress: String? = null,
    /** Последняя ошибка сети — показываем мелко, данные на экране остаются прежними. */
    val offline: Boolean = false,
)

/**
 * Главный экран обновляет данные, только пока он на экране: котировки раз в минуту,
 * погоду раз в полчаса, ленту событий раз в минуту (подписи «через N мин» меняются со временем).
 */
class LauncherViewModel(application: Application) : AndroidViewModel(application) {

    private val api = LauncherApi()
    private val settings = LauncherSettings(application)
    private val calendar = CalendarSource(application)

    private val _state = MutableStateFlow(LauncherState(city = settings.city))
    val state: StateFlow<LauncherState> = _state.asStateFlow()

    private var refreshJob: Job? = null
    private var vpnJob: Job? = null
    private var weatherAt = 0L
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
                delay(TICK_MS)
            }
        }

        // Туннель проверяем чаще: лампочка должна погаснуть вскоре после обрыва.
        vpnJob?.cancel()
        vpnJob = viewModelScope.launch {
            while (isActive) {
                val kb = Uri.parse((getApplication<Application>() as App).kbSession.baseUrl)
                val probe = VpnLamp.probe(kb.host.orEmpty(), kb.port.takeIf { it > 0 } ?: 80)
                _state.update { it.copy(vpn = VpnLamp.state(probe), vpnAddress = probe.address) }
                delay(VPN_TICK_MS)
            }
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

    fun toggleDock(app: LauncherApp) {
        val pkg = app.component.packageName
        settings.dock = if (pkg in settings.dock) settings.dock - pkg else settings.dock + pkg
        _state.update { it.copy(dock = dockApps(it.apps)) }
    }

    fun isInDock(app: LauncherApp) = app.component.packageName in settings.dock

    private suspend fun refreshAgenda() {
        // Запись нужна календарю sync (SyncAdapter пишет события), поэтому просим оба разрешения.
        val allowed = listOf(Manifest.permission.READ_CALENDAR, Manifest.permission.WRITE_CALENDAR).all {
            ContextCompat.checkSelfPermission(getApplication(), it) == PackageManager.PERMISSION_GRANTED
        }
        val rows = if (allowed) {
            withContext(Dispatchers.IO) { Agenda.rows(calendar.upcoming(), Instant.now(), ZoneId.systemDefault()) }
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
                        // Само приложение sync в списке подписано понятнее, чем «Contact Sync».
                        label = if (info.activityInfo.packageName == self) "Синхронизация" else info.loadLabel(pm).toString(),
                        component = ComponentName(info.activityInfo.packageName, info.activityInfo.name),
                        icon = info.loadIcon(pm).toBitmap(iconSize, iconSize).asImageBitmap(),
                    )
                }
                .sortedBy { it.label.lowercase() }
        }
        _state.update { it.copy(apps = apps, dock = dockApps(apps)) }
    }

    private fun dockApps(apps: List<LauncherApp>): List<LauncherApp> {
        val byPackage = apps.associateBy { it.component.packageName }
        return settings.dock.mapNotNull { byPackage[it] }
    }

    private companion object {
        const val TICK_MS = 60_000L
        const val WEATHER_TTL_MS = 30 * 60_000L
        const val VPN_TICK_MS = 10_000L
        const val CALENDAR_SYNC_MS = 5 * 60_000L
    }
}
