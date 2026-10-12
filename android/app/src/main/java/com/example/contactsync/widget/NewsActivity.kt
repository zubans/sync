package com.example.contactsync.widget

import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.unit.dp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.contactsync.launcher.Background
import com.example.contactsync.launcher.LauncherColors
import com.example.contactsync.launcher.LauncherViewModel
import com.example.contactsync.launcher.NewsDetail
import com.example.contactsync.launcher.NewsReader

/**
 * Новости на весь экран — для устройств, где главный экран свой, а не sync: открывается из виджета
 * «Новости» на рабочем столе. Тот же экран, что раскрытый виджет новостей лаунчера: вкладки, сводка,
 * сюжеты; нажатие на новость — она на весь экран.
 */
class NewsActivity : ComponentActivity() {

    private val vm: LauncherViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(
            statusBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
            navigationBarStyle = SystemBarStyle.dark(Color.TRANSPARENT),
        )
        setContent {
            val state by vm.state.collectAsStateWithLifecycle()
            MaterialTheme(colorScheme = LauncherColors) {
                Box(Modifier.fillMaxSize().background(Background)) {
                    Box(Modifier.fillMaxSize().safeDrawingPadding().padding(horizontal = 16.dp, vertical = 12.dp)) {
                        NewsDetail(state, vm, onClose = ::finish)
                    }
                    NewsReader(state, vm)
                }
            }
        }
        if (savedInstanceState == null) open(intent)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        open(intent)
    }

    override fun onResume() {
        super.onResume()
        vm.onResumeNews()
    }

    override fun onPause() {
        super.onPause()
        vm.onPause()
    }

    private fun open(intent: Intent) {
        intent.getStringExtra(EXTRA_SOURCE)?.let { vm.selectNewsSource(it.ifEmpty { null }) }
        val id = intent.getLongExtra(EXTRA_NEWS_ID, 0)
        if (id > 0) vm.read(id)
    }

    companion object {
        private const val EXTRA_NEWS_ID = "news_id"
        private const val EXTRA_SOURCE = "source"

        /** [newsId] — сразу открыть эту новость; [source] — вкладка («» — «Все»). */
        fun intent(context: Context, newsId: Long = 0, source: String? = null): Intent =
            Intent(context, NewsActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_SINGLE_TOP)
                .putExtra(EXTRA_NEWS_ID, newsId)
                .apply { if (source != null) putExtra(EXTRA_SOURCE, source) }
                // Разные data — разные PendingIntent у строк виджета, иначе все строки открыли бы одну новость.
                .setData(android.net.Uri.parse("contactsync-widget://news/$newsId/${source.orEmpty()}"))
    }
}
