package com.example.contactsync

import android.app.Application
import com.example.contactsync.data.Api
import com.example.contactsync.data.Session
import com.example.contactsync.sync.SyncEngine
import com.example.contactsync.sync.SyncScheduler

class App : Application() {

    lateinit var session: Session
        private set
    lateinit var api: Api
        private set
    lateinit var engine: SyncEngine
        private set

    override fun onCreate() {
        super.onCreate()
        session = Session(this)
        api = Api(session)
        engine = SyncEngine(this, session, api)
        // Наблюдение за контактами (content URI trigger) переживает не всё — восстанавливаем при старте.
        if (session.isLoggedIn && session.backgroundSync) SyncScheduler.enable(this)
    }
}
