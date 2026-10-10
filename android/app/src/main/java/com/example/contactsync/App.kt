package com.example.contactsync

import android.app.Application
import com.example.contactsync.apps.ApkBackup
import com.example.contactsync.apps.ApkInstaller
import com.example.contactsync.calendar.CalendarAccount
import com.example.contactsync.data.Api
import com.example.contactsync.data.Session
import com.example.contactsync.kb.KbApi
import com.example.contactsync.kb.KbSession
import com.example.contactsync.kb.MeetingUploads
import com.example.contactsync.sync.SyncEngine
import com.example.contactsync.sync.SyncScheduler
import com.example.contactsync.vault.BiometricUnlock
import com.example.contactsync.vault.VaultRepository
import com.example.contactsync.vault.VaultStore
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob

class App : Application() {

    lateinit var session: Session
        private set
    lateinit var api: Api
        private set
    lateinit var engine: SyncEngine
        private set
    lateinit var vault: VaultRepository
        private set
    lateinit var apkBackup: ApkBackup
        private set
    lateinit var apkInstaller: ApkInstaller
        private set
    lateinit var kbSession: KbSession
        private set
    lateinit var kbApi: KbApi
        private set

    /** Для фоновых операций, которые должны пережить экран (например, сохранение из автозаполнения). */
    val scope = CoroutineScope(SupervisorJob() + Dispatchers.Default)

    override fun onCreate() {
        super.onCreate()
        session = Session(this)
        api = Api(session)
        engine = SyncEngine(this, session, api)
        vault = VaultRepository(VaultStore(this), api, biometric = BiometricUnlock(this))
        apkBackup = ApkBackup(this, session, api)
        apkInstaller = ApkInstaller(this, api)
        kbSession = KbSession(this)
        kbApi = KbApi(kbSession)
        // Записи встреч, не отправленные до перезапуска (нет сети, кончилась сессия), — снова в очередь.
        if (kbSession.isLoggedIn) MeetingUploads.resumeAll(this)
        // Наблюдение за контактами (content URI trigger) переживает не всё — восстанавливаем при старте.
        if (session.isLoggedIn && session.backgroundSync) SyncScheduler.enable(this)
        // Аккаунт календаря мог не создаться при входе в прошлой версии приложения.
        session.email?.takeIf { session.isLoggedIn }?.let { CalendarAccount.ensure(this, it) }
    }

    /** Отправить правки хранилища на сервер, как только будет сеть. */
    fun syncVaultSoon() = SyncScheduler.syncVaultNow(this)

    /** Выход: всё, что привязано к аккаунту, удаляется с устройства. */
    fun clearAccountData() {
        SyncScheduler.disable(this)
        // Вместе с аккаунтом система удалит и календари sync с устройства.
        CalendarAccount.remove(this)
        session.signOut()
        engine.clearLocalState()
        vault.clearLocal()
    }
}
