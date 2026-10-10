package com.example.contactsync.launcher

import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.addCallback
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.pager.rememberPagerState
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.contactsync.App
import com.example.contactsync.kb.KbViewModel
import kotlinx.coroutines.flow.MutableSharedFlow

/**
 * Главный экран планшета (HOME): часы, погода, Мосбиржа, события, база знаний и приложения.
 * Назначается в «Настройки → Приложения → Приложения по умолчанию → Главный экран».
 */
class LauncherActivity : ComponentActivity() {

    private val vm: LauncherViewModel by viewModels()
    private val kbVm: KbViewModel by viewModels()

    /** «Домой», нажатое на самом главном экране, — вернуться на первый экран. */
    private val homePressed = MutableSharedFlow<Unit>(extraBufferCapacity = 1)

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
        )
        // «Назад» на главном экране уводить некуда; экраны и шторка обрабатывают его сами.
        onBackPressedDispatcher.addCallback(this) {}
        setContent {
            val state by vm.state.collectAsStateWithLifecycle()
            val kb by kbVm.state.collectAsStateWithLifecycle()
            val pager = rememberPagerState(pageCount = { 2 })
            LaunchedEffect(Unit) { homePressed.collect { pager.animateScrollToPage(0) } }
            LauncherScreen(state, vm, kb, kbVm, kbCookies = { (application as App).kbSession.cookieHeaders() }, pager = pager)
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        if (intent.hasCategory(Intent.CATEGORY_HOME)) homePressed.tryEmit(Unit)
    }

    override fun onResume() {
        super.onResume()
        NavBarService.launcherVisible.value = true
        vm.onResume()
        kbVm.onResume()
    }

    override fun onPause() {
        super.onPause()
        NavBarService.launcherVisible.value = false
        vm.onPause()
        kbVm.onPause()
    }
}
