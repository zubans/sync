package com.example.contactsync.calendar

import android.accounts.AbstractAccountAuthenticator
import android.accounts.Account
import android.accounts.AccountAuthenticatorResponse
import android.accounts.AccountManager
import android.app.Service
import android.content.ContentResolver
import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.os.IBinder
import android.provider.CalendarContract

/**
 * Аккаунт sync в системе: к нему привязаны календари sync в CalendarContract, и через него
 * Android сам запускает синхронизацию — раз в час и после правок в приложении «Календарь».
 * Выход из sync удаляет аккаунт, а с ним система удаляет и его календари.
 */
object CalendarAccount {
    const val TYPE = "com.example.contactsync"
    private const val PERIOD_SECONDS = 60 * 60L

    fun ensure(context: Context, email: String): Account {
        val manager = AccountManager.get(context)
        val account = Account(email, TYPE)
        manager.getAccountsByType(TYPE).filter { it.name != email }.forEach { manager.removeAccountExplicitly(it) }
        if (manager.getAccountsByType(TYPE).none { it.name == email }) manager.addAccountExplicitly(account, null, null)
        ContentResolver.setIsSyncable(account, CalendarContract.AUTHORITY, 1)
        ContentResolver.setSyncAutomatically(account, CalendarContract.AUTHORITY, true)
        ContentResolver.addPeriodicSync(account, CalendarContract.AUTHORITY, Bundle.EMPTY, PERIOD_SECONDS)
        return account
    }

    fun remove(context: Context) {
        val manager = AccountManager.get(context)
        manager.getAccountsByType(TYPE).forEach { manager.removeAccountExplicitly(it) }
    }

    /** Синхронизировать сейчас, не дожидаясь расписания. */
    fun requestSync(context: Context) {
        val account = AccountManager.get(context).getAccountsByType(TYPE).firstOrNull() ?: return
        ContentResolver.requestSync(
            account,
            CalendarContract.AUTHORITY,
            Bundle().apply {
                putBoolean(ContentResolver.SYNC_EXTRAS_MANUAL, true)
                putBoolean(ContentResolver.SYNC_EXTRAS_EXPEDITED, true)
            },
        )
    }
}

/** Аутентификатор-заглушка: аккаунт создаёт само приложение при входе, пароль система не хранит. */
class AccountAuthenticatorService : Service() {
    private lateinit var authenticator: Authenticator

    override fun onCreate() {
        authenticator = Authenticator(this)
    }

    override fun onBind(intent: Intent?): IBinder = authenticator.iBinder

    private class Authenticator(context: Context) : AbstractAccountAuthenticator(context) {
        override fun editProperties(response: AccountAuthenticatorResponse?, accountType: String?): Bundle? = null
        override fun addAccount(r: AccountAuthenticatorResponse?, t: String?, a: String?, f: Array<out String>?, o: Bundle?): Bundle? = null
        override fun confirmCredentials(r: AccountAuthenticatorResponse?, a: Account?, o: Bundle?): Bundle? = null
        override fun getAuthToken(r: AccountAuthenticatorResponse?, a: Account?, t: String?, o: Bundle?): Bundle? = null
        override fun getAuthTokenLabel(authTokenType: String?): String? = null
        override fun updateCredentials(r: AccountAuthenticatorResponse?, a: Account?, t: String?, o: Bundle?): Bundle? = null
        override fun hasFeatures(r: AccountAuthenticatorResponse?, a: Account?, f: Array<out String>?): Bundle? = null
    }
}
