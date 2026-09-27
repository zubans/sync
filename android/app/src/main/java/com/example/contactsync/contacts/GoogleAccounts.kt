package com.example.contactsync.contacts

import android.accounts.AccountManager
import android.content.Context
import android.util.Patterns

/**
 * Google-аккаунты устройства.
 *
 * С Android 8 AccountManager отдаёт приложению только «видимые» ему аккаунты, и Google-аккаунтов
 * там обычно нет. Поэтому дополнительно берём аккаунты, к которым привязаны контакты телефона
 * (ContactsProvider, нужен READ_CONTACTS) — это покрывает все аккаунты с включённой синхронизацией контактов.
 */
object GoogleAccounts {

    fun collect(context: Context, contacts: PhoneContacts): List<String> {
        val fromAccountManager = runCatching {
            AccountManager.get(context).getAccountsByType(PhoneContacts.GOOGLE_ACCOUNT_TYPE).map { it.name }
        }.getOrDefault(emptyList())
        val fromContacts = runCatching { contacts.googleAccountNames() }.getOrDefault(emptySet())

        return (fromAccountManager + fromContacts)
            .map { it.trim().lowercase() }
            .filter { Patterns.EMAIL_ADDRESS.matcher(it).matches() }
            .distinct()
            .sorted()
    }
}
