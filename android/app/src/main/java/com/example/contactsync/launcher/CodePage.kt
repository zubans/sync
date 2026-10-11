package com.example.contactsync.launcher

import android.annotation.SuppressLint
import android.net.Uri
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import com.example.contactsync.BuildConfig
import com.example.contactsync.kb.openUrl
import com.example.contactsync.kb.showWhen

/** Фон редактора VS Code (тема Dark Modern) — чтобы до загрузки не мигало другим цветом. */
private const val CODE_BACKGROUND = "#1F1F1F"

/**
 * Третий экран: VS Code в браузере (code-server на VPS, адрес — `BuildConfig.CODE_SERVER_URL`).
 * Страница грузится при первом показе экрана, а не при запуске лаунчера: code-server тяжёлый,
 * а на третий экран заходят не каждый раз. Вход по паролю code-server хранится в cookie WebView.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun CodePage(active: Boolean, onHome: () -> Unit, modifier: Modifier, visible: Boolean = active) {
    val context = LocalContext.current
    val baseUrl = BuildConfig.CODE_SERVER_URL
    var started by remember { mutableStateOf(false) }
    var loading by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }
    var fileCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }
    val filePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        fileCallback?.onReceiveValue(uris.toTypedArray())
        fileCallback = null
    }
    val webView = remember {
        WebView(context).apply {
            // С WRAP_CONTENT WebView считает 100vh нулём, а VS Code целиком свёрстан от высоты окна.
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            setBackgroundColor(android.graphics.Color.parseColor(CODE_BACKGROUND))
            settings.apply {
                javaScriptEnabled = true
                // Состояние редактора (открытые вкладки, раскладка) VS Code хранит в IndexedDB и localStorage.
                domStorageEnabled = true
                databaseEnabled = true
                useWideViewPort = true
                loadWithOverviewMode = true
            }
            CookieManager.getInstance().setAcceptCookie(true)
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    val sameSite = request.url.host == Uri.parse(baseUrl).host
                    if (!sameSite) context.openUrl(request.url.toString())
                    return !sameSite
                }

                override fun onPageFinished(view: WebView, url: String?) {
                    loading = false
                    CookieManager.getInstance().flush()
                }

                override fun onReceivedError(view: WebView, request: WebResourceRequest, err: WebResourceError) {
                    if (request.isForMainFrame) {
                        loading = false
                        error = err.description?.toString() ?: "ошибка сети"
                    }
                }
            }
            webChromeClient = object : WebChromeClient() {
                // «Upload…» в проводнике VS Code.
                override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                    fileCallback?.onReceiveValue(null)
                    fileCallback = callback
                    filePicker.launch(params.acceptTypes.firstOrNull { it.isNotBlank() } ?: "*/*")
                    return true
                }
            }
        }
    }
    DisposableEffect(webView) { onDispose { webView.destroy() } }

    val load = {
        error = null
        loading = true
        webView.loadUrl(baseUrl)
    }
    LaunchedEffect(active) {
        if (active && !started) {
            started = true
            load()
        }
    }

    val swipeHome = Modifier.pointerInput(Unit) {
        // Горизонтальные жесты внутри редактора забирает VS Code, поэтому домой — свайпом вправо
        // по верхней панели или по полосе у левого края, как на экране базы знаний.
        var dragged = 0f
        detectHorizontalDragGestures(
            onDragStart = { dragged = 0f },
            onHorizontalDrag = { _, dx -> dragged += dx },
            onDragEnd = { if (dragged > 80.dp.toPx()) onHome() },
        )
    }

    Column(modifier) {
        Row(
            Modifier.fillMaxWidth().then(swipeHome).padding(bottom = 12.dp),
            verticalAlignment = Alignment.CenterVertically,
            horizontalArrangement = Arrangement.spacedBy(8.dp),
        ) {
            TextButton(onClick = onHome) { Text("← Главная", color = Accent) }
            Text("VS Code", color = TextMain, fontSize = 22.sp)
            Text(Uri.parse(baseUrl).authority.orEmpty(), color = TextMuted, fontSize = 14.sp, modifier = Modifier.weight(1f))
            if (loading) CircularProgressIndicator(color = Accent, strokeWidth = 2.dp, modifier = Modifier.width(20.dp).height(20.dp))
            CodeChip("↻") { if (started) webView.reload() else load() }
        }
        Box(Modifier.fillMaxWidth().weight(1f)) {
            AndroidView(
                factory = { webView },
                modifier = Modifier.fillMaxSize().clip(RoundedCornerShape(20.dp)),
                update = { it.showWhen(visible) },
            )
            error?.let { message ->
                Column(
                    Modifier.fillMaxSize().clip(RoundedCornerShape(20.dp)).background(CardColor),
                    horizontalAlignment = Alignment.CenterHorizontally,
                    verticalArrangement = Arrangement.Center,
                ) {
                    Text("Нет связи с code-server", color = TextMain, fontSize = 20.sp)
                    Text("$baseUrl · $message", color = TextMuted, fontSize = 14.sp)
                    Spacer(Modifier.height(16.dp))
                    CodeChip("Повторить") { load() }
                }
            }
            Box(Modifier.align(Alignment.CenterStart).width(20.dp).fillMaxHeight().then(swipeHome))
        }
    }
}

@Composable
private fun CodeChip(text: String, onClick: () -> Unit) {
    Text(
        text,
        color = TextMain,
        fontSize = 16.sp,
        modifier = Modifier
            .clip(RoundedCornerShape(12.dp))
            .background(CardRaised)
            .clickable(onClick = onClick)
            .padding(horizontal = 14.dp, vertical = 10.dp),
    )
}
