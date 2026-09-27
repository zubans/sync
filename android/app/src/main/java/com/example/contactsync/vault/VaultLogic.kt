package com.example.contactsync.vault

import java.net.URI

/** Что сделать с хранилищем после того, как пользователь вошёл куда-то с логином и паролем. */
sealed interface SavePlan {
    data class Create(val entry: VaultEntry) : SavePlan
    data class Update(val id: String, val before: VaultEntry, val after: VaultEntry) : SavePlan
    data class Unchanged(val id: String) : SavePlan
}

/**
 * Правила изменения записей — без Android и сети, покрыты unit-тестами.
 *
 * Главное правило: пароль никогда не теряется. Любой вытесненный пароль — при смене пароля,
 * при слиянии конфликтующих правок с разных устройств — попадает в историю записи.
 */
object VaultLogic {

    const val HISTORY_LIMIT = 10

    /** Смена пароля: старый уходит в историю. */
    fun changePassword(entry: VaultEntry, newPassword: String, now: Long): VaultEntry {
        if (newPassword == entry.password) return entry
        return entry.copy(
            password = newPassword,
            passwordChangedAt = now,
            modifiedAt = now,
            history = normalizeHistory(
                listOfNotNull(entry.password.takeIf { it.isNotEmpty() }?.let { PasswordHistoryEntry(it, entry.passwordChangedAt) }) +
                    entry.history,
                current = newPassword,
            ),
        )
    }

    /**
     * Слияние двух версий одной записи, изменённых на разных устройствах.
     *
     * - поля записи (название, логин, заметки) — из версии с более поздним modifiedAt;
     * - пароль — из версии, где его меняли позже (passwordChangedAt), проигравший — в историю;
     * - адреса и история — объединение.
     * Результат не зависит от того, какая версия локальная: оба устройства сойдутся к одному.
     */
    fun merge(a: VaultEntry, b: VaultEntry): VaultEntry {
        val (newer, older) = if (isNewer(a, b)) a to b else b to a
        val (pwdWinner, pwdLoser) = if (
            a.passwordChangedAt > b.passwordChangedAt ||
            (a.passwordChangedAt == b.passwordChangedAt && a.password >= b.password)
        ) a to b else b to a

        val displaced = listOfNotNull(
            pwdLoser.password.takeIf { it.isNotEmpty() && it != pwdWinner.password }
                ?.let { PasswordHistoryEntry(it, pwdLoser.passwordChangedAt) },
        )
        return newer.copy(
            password = pwdWinner.password,
            passwordChangedAt = pwdWinner.passwordChangedAt,
            uris = (newer.uris + older.uris).distinct(),
            history = normalizeHistory(displaced + a.history + b.history, current = pwdWinner.password),
            createdAt = minOf(a.createdAt, b.createdAt),
            modifiedAt = maxOf(a.modifiedAt, b.modifiedAt),
        )
    }

    /**
     * Разрешение конфликта, где одна из сторон могла удалить запись (null — удалена).
     * Правка побеждает удаление: удалённая на одном устройстве запись, которую на другом
     * в это же время меняли, остаётся — лучше лишняя запись, чем потерянный пароль.
     */
    fun resolve(local: VaultEntry?, remote: VaultEntry?): VaultEntry? = when {
        local != null && remote != null -> merge(local, remote)
        else -> local ?: remote
    }

    /**
     * Сохранение логина из автозаполнения.
     *
     * - есть запись для этого приложения/сайта с тем же логином → обновить пароль (или ничего, если он тот же);
     * - логина нет (форма смены пароля) и запись для адреса одна → обновить её;
     * - иначе создать новую запись.
     */
    fun planSave(
        records: List<VaultRecord>,
        uri: String,
        title: String,
        username: String,
        password: String,
        now: Long,
    ): SavePlan {
        val candidates = records.filter { matches(it.entry, uri) }
        val target = if (username.isNotBlank()) {
            candidates.firstOrNull { it.entry.username.equals(username.trim(), ignoreCase = true) }
        } else {
            candidates.singleOrNull() ?: candidates.firstOrNull { it.entry.password == password }
        }

        if (target == null) {
            return SavePlan.Create(VaultEntry.new(title, username.trim(), password, listOf(uri), now))
        }
        val withUri = if (uri in target.entry.uris) target.entry else target.entry.copy(uris = target.entry.uris + uri, modifiedAt = now)
        val updated = changePassword(withUri, password, now)
        return if (updated == target.entry) SavePlan.Unchanged(target.id) else SavePlan.Update(target.id, target.entry, updated)
    }

    /** Подходит ли запись для приложения или сайта [uri]. */
    fun matches(entry: VaultEntry, uri: String): Boolean {
        val target = Target.parse(uri) ?: return false
        return entry.uris.mapNotNull(Target::parse).any { it.matches(target) }
    }

    private fun isNewer(a: VaultEntry, b: VaultEntry): Boolean =
        a.modifiedAt > b.modifiedAt || (a.modifiedAt == b.modifiedAt && a.hashCode() >= b.hashCode())

    private fun normalizeHistory(entries: List<PasswordHistoryEntry>, current: String): List<PasswordHistoryEntry> =
        entries
            .filter { it.password.isNotEmpty() && it.password != current }
            .sortedByDescending { it.changedAt }
            .distinctBy { it.password }
            .take(HISTORY_LIMIT)

    /** Приложение (по пакету) или сайт (по домену). */
    private sealed interface Target {
        fun matches(other: Target): Boolean

        data class App(val packageName: String) : Target {
            override fun matches(other: Target) = other is App && other.packageName == packageName
        }

        data class Web(val host: String) : Target {
            override fun matches(other: Target) = other is Web && baseDomain(other.host) == baseDomain(host)
        }

        companion object {
            fun parse(uri: String): Target? {
                val value = uri.trim()
                if (value.startsWith(APP_SCHEME)) return App(value.removePrefix(APP_SCHEME)).takeIf { it.packageName.isNotEmpty() }
                val withScheme = if ("://" in value) value else "https://$value"
                val host = runCatching { URI(withScheme).host }.getOrNull()?.lowercase()?.removePrefix("www.")
                return host?.takeIf { it.isNotEmpty() }?.let(::Web)
            }
        }
    }

    const val APP_SCHEME = "androidapp://"

    /** Домены второго уровня, под которыми регистрируют сайты (упрощённый Public Suffix List). */
    private val MULTI_PART_SUFFIXES = setOf(
        "co.uk", "org.uk", "com.au", "net.au", "co.jp", "com.br", "com.tr", "co.in",
        "com.ru", "net.ru", "org.ru", "msk.ru", "spb.ru", "com.ua", "co.il", "co.kr",
    )

    /** accounts.google.com и google.com — один сайт; bank.co.uk не путается с co.uk. */
    fun baseDomain(host: String): String {
        val labels = host.lowercase().trimEnd('.').split('.')
        if (labels.size <= 2) return labels.joinToString(".")
        val lastTwo = labels.takeLast(2).joinToString(".")
        return if (lastTwo in MULTI_PART_SUFFIXES) labels.takeLast(3).joinToString(".") else lastTwo
    }
}
