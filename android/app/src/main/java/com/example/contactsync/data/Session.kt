package com.example.contactsync.data

import android.content.Context
import com.example.contactsync.BuildConfig
import java.util.UUID

/**
 * Настройки и сессия пользователя. Хранится в приватных SharedPreferences приложения.
 */
class Session(context: Context) {

    private val prefs = context.getSharedPreferences("session", Context.MODE_PRIVATE)

    /** Идентификатор установки: сервер по нему различает устройства пользователя. */
    val installId: String = prefs.getString(KEY_INSTALL_ID, null)
        ?: UUID.randomUUID().toString().also { prefs.edit().putString(KEY_INSTALL_ID, it).apply() }

    var serverUrl: String
        get() = prefs.getString(KEY_SERVER_URL, null) ?: BuildConfig.DEFAULT_SERVER_URL
        set(value) = prefs.edit().putString(KEY_SERVER_URL, value.trim()).apply()

    val token: String? get() = prefs.getString(KEY_TOKEN, null)
    val email: String? get() = prefs.getString(KEY_EMAIL, null)
    val familyName: String? get() = prefs.getString(KEY_FAMILY, null)
    val isLoggedIn: Boolean get() = token != null

    var backgroundSync: Boolean
        get() = prefs.getBoolean(KEY_BACKGROUND, true)
        set(value) = prefs.edit().putBoolean(KEY_BACKGROUND, value).apply()

    var lastSyncAt: Long
        get() = prefs.getLong(KEY_LAST_SYNC_AT, 0)
        set(value) = prefs.edit().putLong(KEY_LAST_SYNC_AT, value).apply()

    var lastSyncSummary: String?
        get() = prefs.getString(KEY_LAST_SUMMARY, null)
        set(value) = prefs.edit().putString(KEY_LAST_SUMMARY, value).apply()

    fun signIn(token: String, user: UserDto) {
        prefs.edit().putString(KEY_TOKEN, token).apply()
        updateUser(user)
    }

    fun updateUser(user: UserDto) {
        prefs.edit()
            .putString(KEY_EMAIL, user.email)
            .putString(KEY_FAMILY, user.family?.name)
            .apply()
    }

    fun updateFamily(family: FamilyDto?) {
        prefs.edit().putString(KEY_FAMILY, family?.name).apply()
    }

    fun signOut() {
        prefs.edit()
            .remove(KEY_TOKEN)
            .remove(KEY_EMAIL)
            .remove(KEY_FAMILY)
            .remove(KEY_LAST_SYNC_AT)
            .remove(KEY_LAST_SUMMARY)
            .apply()
    }

    private companion object {
        const val KEY_INSTALL_ID = "install_id"
        const val KEY_SERVER_URL = "server_url"
        const val KEY_TOKEN = "token"
        const val KEY_EMAIL = "email"
        const val KEY_FAMILY = "family"
        const val KEY_BACKGROUND = "background_sync"
        const val KEY_LAST_SYNC_AT = "last_sync_at"
        const val KEY_LAST_SUMMARY = "last_sync_summary"
    }
}
