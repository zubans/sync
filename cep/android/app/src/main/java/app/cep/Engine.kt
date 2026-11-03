package app.cep

import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

enum class Phase(val title: String) {
    STOPPED("Отключено"),
    CONNECTING("Подключение…"),
    CONNECTED("Подключено"),
    STOPPING("Отключение…"),
    FAILED("Ошибка"),
}

data class EngineState(
    val phase: Phase = Phase.STOPPED,
    val chainName: String = "",
    val hops: List<Profile> = emptyList(),
    val error: String? = null,
) {
    val isActive: Boolean get() = phase == Phase.CONNECTING || phase == Phase.CONNECTED || phase == Phase.STOPPING
}

/** Состояние движка и журнал: пишет CepVpnService, читает интерфейс. */
object Engine {
    private const val MAX_LOG = 2000

    private val _state = MutableStateFlow(EngineState())
    val state: StateFlow<EngineState> = _state.asStateFlow()

    private val _log = MutableStateFlow<List<String>>(emptyList())
    val log: StateFlow<List<String>> = _log.asStateFlow()

    private val time = SimpleDateFormat("HH:mm:ss", Locale.ROOT)

    fun set(block: (EngineState) -> EngineState) = _state.update(block)

    fun log(message: String) {
        val stamp = time.format(Date())
        val lines = message.trimEnd().lines().map { "[$stamp] $it" }
        _log.update { (it + lines).takeLast(MAX_LOG) }
    }

    fun clearLog() {
        _log.value = emptyList()
    }
}
