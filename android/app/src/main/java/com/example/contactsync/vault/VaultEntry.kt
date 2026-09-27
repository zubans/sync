package com.example.contactsync.vault

import kotlinx.serialization.Serializable

/** Прежний пароль записи. */
@Serializable
data class PasswordHistoryEntry(val password: String, val changedAt: Long)

/**
 * Содержимое записи хранилища (до шифрования).
 *
 * uris — где применять запись: `androidapp://<пакет>` для приложений, `https://host` для сайтов.
 */
@Serializable
data class VaultEntry(
    val title: String,
    val username: String = "",
    val password: String = "",
    val uris: List<String> = emptyList(),
    val notes: String = "",
    /** Прежние пароли, свежие первыми. */
    val history: List<PasswordHistoryEntry> = emptyList(),
    val createdAt: Long,
    /** Время последней правки любого поля — по нему решается, чья версия новее. */
    val modifiedAt: Long,
    /** Время, когда был задан текущий пароль. */
    val passwordChangedAt: Long,
) {
    companion object {
        fun new(title: String, username: String, password: String, uris: List<String>, now: Long) = VaultEntry(
            title = title,
            username = username,
            password = password,
            uris = uris,
            createdAt = now,
            modifiedAt = now,
            passwordChangedAt = now,
        )
    }
}

/** Запись с идентификатором — то, с чем работает UI и автозаполнение. */
data class VaultRecord(val id: String, val entry: VaultEntry)
