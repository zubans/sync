package com.example.contactsync.cep

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class CepPhase(val title: String) {
    STOPPED("Отключено"),
    CONNECTING("Подключение…"),
    CONNECTED("Подключено"),
    RECONNECTING("Переподключение…"),
    STOPPING("Отключение…"),
    FAILED("Ошибка"),
}

data class CepEngineState(
    val phase: CepPhase = CepPhase.STOPPED,
    val chainName: String = "",
    /** Узлы поднятой цепочки по порядку. */
    val hops: List<CepProfile> = emptyList(),
    val error: String? = null,
    /** Номер попытки переподключения и когда она начнётся (мс, System.currentTimeMillis). */
    val reconnectAttempt: Int? = null,
    val nextAttemptAt: Long? = null,
    /** Сколько раз цепочку уже поднимали заново после разрыва. */
    val reconnects: Int = 0,
    /** Пока цепочка переподключается, трафик мимо неё заблокирован. */
    val killSwitch: Boolean = false,
) {
    val isActive: Boolean
        get() = phase == CepPhase.CONNECTING || phase == CepPhase.CONNECTED ||
            phase == CepPhase.RECONNECTING || phase == CepPhase.STOPPING
}

/** Состояние движка и журнал: пишет CepVpnService, читает экран CEP. Журнал — только в памяти. */
object CepEngine {
    private const val MAX_LOG = 2000

    private val _state = MutableStateFlow(CepEngineState())
    val state: StateFlow<CepEngineState> = _state.asStateFlow()

    private val _log = MutableStateFlow<List<String>>(emptyList())
    val log: StateFlow<List<String>> = _log.asStateFlow()

    private val time = SimpleDateFormat("HH:mm:ss", Locale.ROOT)

    fun set(block: (CepEngineState) -> CepEngineState) = _state.update(block)

    fun log(message: String) {
        val stamp = synchronized(time) { time.format(Date()) }
        val lines = message.trimEnd().lines().map { "[$stamp] $it" }
        _log.update { (it + lines).takeLast(MAX_LOG) }
    }

    fun clearLog() {
        _log.value = emptyList()
    }
}
