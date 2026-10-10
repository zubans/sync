package com.example.contactsync.kb

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.background
import androidx.compose.foundation.gestures.detectHorizontalDragGestures
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.DisposableEffect
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.produceState
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.input.pointer.pointerInput
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.compose.ui.viewinterop.AndroidView
import androidx.core.content.ContextCompat
import com.example.contactsync.launcher.Accent
import com.example.contactsync.launcher.Amber
import com.example.contactsync.launcher.CardRaised
import com.example.contactsync.launcher.DashboardCard
import com.example.contactsync.launcher.Down
import com.example.contactsync.launcher.TextMain
import com.example.contactsync.launcher.TextMuted
import com.example.contactsync.launcher.Up
import kotlinx.coroutines.delay



/* ---------- Виджет на первом экране ---------- */

@Composable
fun KbCard(state: KbState, vm: KbViewModel, modifier: Modifier) {
    val context = LocalContext.current
    var login by remember { mutableStateOf(false) }
    var projectMenu by remember { mutableStateOf(false) }

    Box(modifier) {
        DashboardCard(
            title = "База знаний",
            action = if (state.user == null) "Войти" else (state.project?.name ?: "Проект") + " ▾",
            onAction = { if (state.user == null) login = true else projectMenu = true },
            modifier = Modifier.fillMaxSize(),
        ) {
            if (state.user == null) {
                Text("Войдите, чтобы видеть задачи проекта и записывать встречи.", color = TextMuted, fontSize = 16.sp)
                return@DashboardCard
            }
            RecordButton(state, vm)
            UploadStatus(state)
            state.error?.let { Text(it, color = Down, fontSize = 13.sp) }
            Spacer(Modifier.padding(top = 4.dp))
            val tasks = KbStages.forWidget(state.tasks)
            if (tasks.isEmpty() && !state.loading) Text("Открытых задач нет", color = TextMuted, fontSize = 15.sp)
            LazyColumn {
                items(tasks, key = { it.id }) { task ->
                    TaskRow(task, onClick = { context.openUrl(KbStages.taskUrl(state.baseUrl, task)) })
                }
            }
        }
        Box(Modifier.align(Alignment.TopEnd).padding(top = 56.dp, end = 24.dp)) {
            ProjectMenu(state, expanded = projectMenu, onDismiss = { projectMenu = false }, vm = vm)
        }
    }
    if (login) KbLoginDialog(state, vm, onDismiss = { login = false })
}

@Composable
fun ProjectMenu(state: KbState, expanded: Boolean, onDismiss: () -> Unit, vm: KbViewModel) {
    DropdownMenu(expanded = expanded, onDismissRequest = onDismiss) {
        state.projects.forEach { project ->
            DropdownMenuItem(
                text = { Text(project.name + if (project.slug == state.project?.slug) "  ✓" else "") },
                onClick = { onDismiss(); vm.selectProject(project) },
            )
        }
        DropdownMenuItem(text = { Text("Выйти из KB", color = TextMuted) }, onClick = { onDismiss(); vm.logout() })
    }
}

/** Запись встречи в выбранный проект. Микрофон спрашиваем при первом нажатии. */
@Composable
fun RecordButton(state: KbState, vm: KbViewModel, modifier: Modifier = Modifier.fillMaxWidth()) {
    val context = LocalContext.current
    val permissions = rememberLauncherForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) { result ->
        if (result[Manifest.permission.RECORD_AUDIO] == true) vm.startRecording()
    }
    val recording = state.recording
    val label = if (recording != null) {
        val elapsed by produceState(0L, recording.startedAt) {
            while (true) {
                value = System.currentTimeMillis() - recording.startedAt
                delay(1000)
            }
        }
        "■  ${duration(elapsed)} · остановить и отправить"
    } else {
        "●  Записать встречу" + (state.project?.let { " в ${it.name}" } ?: "")
    }
    Text(
        label,
        color = if (recording != null) Color(0xFF2A0B0A) else Down,
        fontSize = 16.sp,
        maxLines = 1,
        overflow = TextOverflow.Ellipsis,
        modifier = modifier
            .clip(RoundedCornerShape(14.dp))
            .background(if (recording != null) Down else Down.copy(alpha = 0.14f))
            .clickable(enabled = state.project != null) {
                if (recording != null) {
                    vm.stopRecording()
                } else if (ContextCompat.checkSelfPermission(context, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
                    vm.startRecording()
                } else {
                    permissions.launch(
                        if (Build.VERSION.SDK_INT >= 33) {
                            arrayOf(Manifest.permission.RECORD_AUDIO, Manifest.permission.POST_NOTIFICATIONS)
                        } else {
                            arrayOf(Manifest.permission.RECORD_AUDIO)
                        },
                    )
                }
            }
            .padding(horizontal = 16.dp, vertical = 12.dp),
    )
}

@Composable
private fun UploadStatus(state: KbState) {
    val lines = buildList {
        if (state.pendingUploads > 0) add("Ждёт отправки в KB: ${state.pendingUploads}")
        state.meetings.forEach { add("${it.title}: ${it.status}") }
    }
    lines.forEach { Text(it, color = TextMuted, fontSize = 13.sp, modifier = Modifier.padding(top = 6.dp)) }
}

@Composable
fun TaskRow(task: KbTask, onClick: () -> Unit) {
    val stage = KbStages.stage(task)
    val color = when (stage.tone) {
        StageTone.ACTIVE -> Accent
        StageTone.REVIEW -> Amber
        StageTone.FAILED -> Down
        StageTone.DONE -> Up
        StageTone.WAITING -> TextMuted
    }
    Column(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .clickable(onClick = onClick)
            .padding(vertical = 8.dp, horizontal = 4.dp),
    ) {
        Text(
            "${task.code}  ${task.title}",
            color = TextMain,
            fontSize = 16.sp,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
        Text(
            stage.label,
            color = color,
            fontSize = 13.sp,
            modifier = Modifier
                .padding(top = 4.dp)
                .clip(RoundedCornerShape(6.dp))
                .background(color.copy(alpha = 0.14f))
                .padding(horizontal = 8.dp, vertical = 2.dp),
        )
    }
}

/* ---------- Вход ---------- */

@Composable
fun KbLoginDialog(state: KbState, vm: KbViewModel, onDismiss: () -> Unit) {
    var url by remember { mutableStateOf(state.baseUrl) }
    var username by remember { mutableStateOf("") }
    var password by remember { mutableStateOf("") }
    var busy by remember { mutableStateOf(false) }
    var error by remember { mutableStateOf<String?>(null) }

    AlertDialog(
        onDismissRequest = onDismiss,
        title = { Text("Вход в базу знаний") },
        text = {
            Column(verticalArrangement = Arrangement.spacedBy(8.dp)) {
                OutlinedTextField(url, { url = it }, label = { Text("Адрес KB") }, singleLine = true,
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Uri))
                OutlinedTextField(username, { username = it; error = null }, label = { Text("Логин") }, singleLine = true)
                OutlinedTextField(
                    password, { password = it; error = null }, label = { Text("Пароль") }, singleLine = true,
                    visualTransformation = PasswordVisualTransformation(),
                    keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Password),
                )
                error?.let { Text(it, color = Down, fontSize = 14.sp) }
            }
        },
        confirmButton = {
            TextButton(
                enabled = !busy && username.isNotBlank() && password.isNotEmpty(),
                onClick = {
                    busy = true
                    vm.login(url, username, password) { result ->
                        busy = false
                        if (result == null) onDismiss() else error = result
                    }
                },
            ) { Text(if (busy) "Вход…" else "Войти") }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена") } },
    )
}

/* ---------- Второй экран: полный веб-интерфейс KB ---------- */

/**
 * Веб-интерфейс KB в WebView. Сессия та же, что у виджета: cookie передаются WebView,
 * поэтому второй раз входить не нужно. Ссылки на другие сайты открываются в браузере.
 */
@SuppressLint("SetJavaScriptEnabled")
@Composable
fun KbWebPage(state: KbState, vm: KbViewModel, cookies: List<String>, onHome: () -> Unit, modifier: Modifier) {
    val context = LocalContext.current
    var projectMenu by remember { mutableStateOf(false) }
    var login by remember { mutableStateOf(false) }
    var canGoBack by remember { mutableStateOf(false) }
    var fileCallback by remember { mutableStateOf<ValueCallback<Array<Uri>>?>(null) }
    val filePicker = rememberLauncherForActivityResult(ActivityResultContracts.GetMultipleContents()) { uris ->
        fileCallback?.onReceiveValue(uris.toTypedArray())
        fileCallback = null
    }
    val webView = remember {
        WebView(context).apply {
            // AndroidView по умолчанию даёт WRAP_CONTENT, а с ним WebView считает 100vh нулём —
            // и всё, что в KB рассчитано от высоты экрана (сайдбар), схлопывается.
            layoutParams = ViewGroup.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT)
            settings.javaScriptEnabled = true
            settings.domStorageEnabled = true
            setBackgroundColor(android.graphics.Color.parseColor("#0E1014"))
            webViewClient = object : WebViewClient() {
                override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
                    val sameSite = request.url.host == Uri.parse(state.baseUrl).host
                    if (!sameSite) context.openUrl(request.url.toString())
                    return !sameSite
                }

                override fun doUpdateVisitedHistory(view: WebView, url: String?, isReload: Boolean) {
                    canGoBack = view.canGoBack()
                }

                override fun onPageFinished(view: WebView, url: String?) {
                    // После входа на самой странице KB сессия появляется только в WebView.
                    vm.adoptWebSession(CookieManager.getInstance().getCookie(state.baseUrl))
                }
            }
            webChromeClient = object : WebChromeClient() {
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

    // Вошли или сменили проект — открываем проект в KB с актуальной сессией.
    LaunchedEffect(state.user, state.project?.slug, state.baseUrl) {
        val manager = CookieManager.getInstance()
        cookies.forEach { manager.setCookie(state.baseUrl, it) }
        manager.flush()
        val url = state.baseUrl + (state.project?.let { "/projects/${it.slug}" } ?: "/")
        webView.loadUrl(url)
    }
    BackHandler(enabled = canGoBack) { webView.goBack() }

    val swipeHome = Modifier.pointerInput(Unit) {
        // Горизонтальный свайп внутри страницы забирает сам сайт, поэтому назад на первый экран —
        // свайпом вправо по верхней панели или по полосе у левого края.
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
            Text("База знаний", color = TextMain, fontSize = 22.sp, modifier = Modifier.weight(1f))
            if (state.user == null) {
                TextButton(onClick = { login = true }) { Text("Войти", color = Accent) }
            } else {
                Box {
                    Chip((state.project?.name ?: "Проект") + " ▾") { projectMenu = true }
                    ProjectMenu(state, projectMenu, { projectMenu = false }, vm)
                }
                RecordButton(state, vm, Modifier.width(300.dp))
            }
            Chip("↻") { webView.reload() }
        }
        Box(Modifier.fillMaxWidth().weight(1f)) {
            AndroidView(
                factory = { webView },
                modifier = Modifier.fillMaxSize().clip(RoundedCornerShape(20.dp)),
            )
            Box(Modifier.align(Alignment.CenterStart).width(20.dp).fillMaxHeight().then(swipeHome))
        }
    }
    if (login) KbLoginDialog(state, vm, onDismiss = { login = false })
}

@Composable
private fun Chip(text: String, onClick: () -> Unit) {
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

fun Context.openUrl(url: String) {
    try {
        startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url)).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    } catch (_: ActivityNotFoundException) {
        // Браузера нет — открыть нечем.
    }
}

private fun duration(millis: Long): String {
    val seconds = millis / 1000
    val h = seconds / 3600
    val m = seconds % 3600 / 60
    val s = seconds % 60
    return if (h > 0) "%d:%02d:%02d".format(h, m, s) else "%02d:%02d".format(m, s)
}
