package com.example.argontunnel.data

import android.content.Context
import android.content.pm.ApplicationInfo
import com.example.argontunnel.BuildConfig
import java.net.NetworkInterface
import java.util.Collections
import java.util.UUID

/** Приложение устройства, доступное для выборочной маршрутизации через TUN-интерфейс. */
data class RoutableApp(
    /** Имя пакета приложения (например, `com.example.contactsync`). */
    val packageName: String,
    /** Отображаемое название приложения. */
    val label: String,
    /** Приоритетное приложение экосистемы (Contact Sync, КБ, CEP) — выводится в начале списка. */
    val isPriorityApp: Boolean,
)

enum class RoutingMode {
    /** Заворачивать только выбранные приложения (по умолчанию Contact Sync). */
    SELECTED_APPS,
    /** Заворачивать весь трафик устройства (кроме самого Argon Tunnel). */
    ALL_APPS,
}

/**
 * Настройки клиента Argon Tunnel.
 * Сетевые параметры и список приложений хранятся в приватных SharedPreferences,
 * а мастер-пароль и тестовая нагрузка живут только в оперативной памяти процесса
 * и никогда не пишутся на диск и не передаются через Intent (согласно код-стайлу проекта).
 */
class TunnelSettings(private val context: Context) {

    private val prefs = context.getSharedPreferences("argon_tunnel_settings", Context.MODE_PRIVATE)

    /** Постоянный идентификатор клиента для сохранения назначенного IP в сессии сервера. */
    val clientId: String = prefs.getString(KEY_CLIENT_ID, null)
        ?: UUID.randomUUID().toString().also { prefs.edit().putString(KEY_CLIENT_ID, it).apply() }

    var serverHost: String
        get() = prefs.getString(KEY_SERVER_HOST, null) ?: BuildConfig.DEFAULT_SERVER_HOST
        set(value) = prefs.edit().putString(KEY_SERVER_HOST, value.trim()).apply()

    var serverPort: Int
        get() = prefs.getInt(KEY_SERVER_PORT, BuildConfig.DEFAULT_SERVER_PORT)
        set(value) = prefs.edit().putInt(KEY_SERVER_PORT, value.coerceIn(1, 65535)).apply()

    /** Имя исходящего сетевого интерфейса на клиенте (например, wlan0, rmnet0; пусто = системный по умолчанию). */
    var bindInterface: String
        get() = prefs.getString(KEY_BIND_INTERFACE, "") ?: ""
        set(value) = prefs.edit().putString(KEY_BIND_INTERFACE, value.trim()).apply()

    /** Имя виртуального TUN-интерфейса сессии. */
    var tunInterfaceName: String
        get() = prefs.getString(KEY_TUN_NAME, "argon0") ?: "argon0"
        set(value) = prefs.edit().putString(KEY_TUN_NAME, value.trim().ifEmpty { "argon0" }).apply()

    /** Мастер-пароль для Argon2id; хранится исключительно в оперативной памяти процесса. */
    var masterPassword: String
        get() = inMemoryMasterPassword
        set(value) {
            inMemoryMasterPassword = value
        }

    /** Материал для тестовой одноразовой отправки в зашифрованном пакете; хранится только в памяти. */
    var pendingTestMaterial: String
        get() = inMemoryTestMaterial
        set(value) {
            inMemoryTestMaterial = value
        }

    var routingMode: RoutingMode
        get() = runCatching {
            RoutingMode.valueOf(prefs.getString(KEY_ROUTING_MODE, RoutingMode.SELECTED_APPS.name)!!)
        }.getOrDefault(RoutingMode.SELECTED_APPS)
        set(value) = prefs.edit().putString(KEY_ROUTING_MODE, value.name).apply()

    /**
     * Выбранные для маршрутизации пакеты приложений.
     * По умолчанию всегда включён Contact Sync (`com.example.contactsync`).
     */
    var selectedPackages: Set<String>
        get() = prefs.getStringSet(KEY_SELECTED_PACKAGES, null)
            ?: setOf(BuildConfig.DEFAULT_SYNC_PACKAGE)
        set(value) = prefs.edit().putStringSet(KEY_SELECTED_PACKAGES, value).apply()

    /** Список доступных сетевых интерфейсов устройства (wlan0, eth0, rmnet_data0 и т.д.). */
    fun availableNetworkInterfaces(): List<String> = runCatching {
        Collections.list(NetworkInterface.getNetworkInterfaces())
            .filter { it.isUp && !it.isLoopback && !it.name.startsWith("tun") && !it.name.startsWith("argon") }
            .map { iface ->
                val ip = Collections.list(iface.inetAddresses)
                    .firstOrNull { !it.isLoopbackAddress && it.hostAddress?.contains(':') == false }
                    ?.hostAddress
                if (ip != null) "${iface.name} ($ip)" else iface.name
            }
            .sorted()
    }.getOrDefault(emptyList())

    /** Список установленных приложений для выбора маршрутизации (Contact Sync, КБ, CEP идут первыми). */
    fun installedRoutableApps(): List<RoutableApp> {
        val pm = context.packageManager
        val priorityTokens = listOf("contactsync", "kb", "cep", "кб")
        val apps = pm.getInstalledApplications(0)
            .filter { it.packageName != context.packageName }
            .filter { info ->
                val isUserApp = (info.flags and ApplicationInfo.FLAG_SYSTEM) == 0
                val isSyncApp = info.packageName == BuildConfig.DEFAULT_SYNC_PACKAGE
                isUserApp || isSyncApp
            }
            .map { info ->
                val label = runCatching { pm.getApplicationLabel(info).toString() }.getOrDefault(info.packageName)
                val lowerPkg = info.packageName.lowercase()
                val lowerLabel = label.lowercase()
                val isPriority = info.packageName == BuildConfig.DEFAULT_SYNC_PACKAGE ||
                    priorityTokens.any { lowerPkg.contains(it) || lowerLabel.contains(it) }
                RoutableApp(
                    packageName = info.packageName,
                    label = label,
                    isPriorityApp = isPriority,
                )
            }
            .sortedWith(
                compareByDescending<RoutableApp> { it.packageName == BuildConfig.DEFAULT_SYNC_PACKAGE }
                    .thenByDescending { it.isPriorityApp }
                    .thenBy { it.label.lowercase() },
            )

        // Если Contact Sync ещё не установлен на устройстве, всё равно показываем его в списке по умолчанию
        return if (apps.any { it.packageName == BuildConfig.DEFAULT_SYNC_PACKAGE }) {
            apps
        } else {
            listOf(
                RoutableApp(
                    packageName = BuildConfig.DEFAULT_SYNC_PACKAGE,
                    label = "Contact Sync (по умолчанию)",
                    isPriorityApp = true,
                ),
            ) + apps
        }
    }

    private companion object {
        const val KEY_CLIENT_ID = "client_id"
        const val KEY_SERVER_HOST = "server_host"
        const val KEY_SERVER_PORT = "server_port"
        const val KEY_BIND_INTERFACE = "bind_interface"
        const val KEY_TUN_NAME = "tun_name"
        const val KEY_ROUTING_MODE = "routing_mode"
        const val KEY_SELECTED_PACKAGES = "selected_packages"

        @Volatile
        private var inMemoryMasterPassword: String = ""

        @Volatile
        private var inMemoryTestMaterial: String = ""
    }
}
