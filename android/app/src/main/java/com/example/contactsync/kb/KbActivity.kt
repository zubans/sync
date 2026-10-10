package com.example.contactsync.kb

import android.graphics.Color
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.viewModels
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.contactsync.launcher.Accent
import com.example.contactsync.launcher.Background
import com.example.contactsync.launcher.CardColor
import com.example.contactsync.launcher.CardRaised
import com.example.contactsync.launcher.LauncherColors
import com.example.contactsync.launcher.TextMain
import com.example.contactsync.launcher.TextMuted

/** Отдельное приложение «База знаний»: проекты, задачи со стадиями и запись встречи. */
class KbActivity : ComponentActivity() {

    private val vm: KbViewModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(SystemBarStyle.dark(Color.TRANSPARENT), SystemBarStyle.dark(Color.TRANSPARENT))
        setContent {
            MaterialTheme(colorScheme = LauncherColors) {
                val state by vm.state.collectAsStateWithLifecycle()
                KbScreen(state, vm)
            }
        }
    }

    override fun onResume() {
        super.onResume()
        vm.onResume()
    }

    override fun onPause() {
        super.onPause()
        vm.onPause()
    }
}

@Composable
private fun KbScreen(state: KbState, vm: KbViewModel) {
    val context = LocalContext.current
    var login by remember { mutableStateOf(false) }

    Column(
        Modifier.fillMaxSize().background(Background).safeDrawingPadding().padding(24.dp),
        verticalArrangement = Arrangement.spacedBy(16.dp),
    ) {
        Row {
            Column(Modifier.weight(1f)) {
                Text("База знаний", color = TextMain, fontSize = 30.sp)
                Text(state.user?.let { "$it · ${state.baseUrl}" } ?: "Вы не вошли", color = TextMuted, fontSize = 14.sp)
            }
            if (state.user == null) {
                TextButton(onClick = { login = true }) { Text("Войти", color = Accent) }
            } else {
                TextButton(onClick = vm::logout) { Text("Выйти", color = TextMuted) }
            }
        }
        if (state.user != null) {
            LazyRow(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                items(state.projects, key = { it.slug }) { project ->
                    val selected = project.slug == state.project?.slug
                    Text(
                        project.name,
                        color = if (selected) Background else TextMain,
                        fontSize = 15.sp,
                        modifier = Modifier
                            .clip(RoundedCornerShape(12.dp))
                            .background(if (selected) Accent else CardRaised)
                            .clickable { vm.selectProject(project) }
                            .padding(horizontal = 14.dp, vertical = 8.dp),
                    )
                }
            }
            RecordButton(state, vm)
            state.meetings.forEach { Text("${it.title}: ${it.status}", color = TextMuted, fontSize = 14.sp) }
            state.error?.let { Text(it, color = com.example.contactsync.launcher.Down, fontSize = 14.sp) }
            LazyColumn(
                Modifier.fillMaxWidth().weight(1f).clip(RoundedCornerShape(24.dp)).background(CardColor).padding(16.dp),
            ) {
                items(KbStages.forWidget(state.tasks), key = { it.id }) { task ->
                    TaskRow(task) { context.openUrl(KbStages.taskUrl(state.baseUrl, task)) }
                }
            }
        }
    }
    if (login) KbLoginDialog(state, vm, onDismiss = { login = false })
}
