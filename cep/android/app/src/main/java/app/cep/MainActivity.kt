package app.cep

import android.Manifest
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.foundation.isSystemInDarkTheme
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Link
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.material3.darkColorScheme
import androidx.compose.material3.lightColorScheme
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Modifier
import androidx.lifecycle.compose.collectAsStateWithLifecycle

sealed interface Screen {
    data class Tab(val index: Int) : Screen
    data class EditChain(val id: String) : Screen
    data class EditProfile(val id: String) : Screen
}

class MainActivity : ComponentActivity() {
    private val model: AppModel by viewModels()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MaterialTheme(colorScheme = if (isSystemInDarkTheme()) darkColorScheme() else lightColorScheme()) {
                App(model)
            }
        }
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun App(model: AppModel) {
    var screen by remember { mutableStateOf<Screen>(Screen.Tab(0)) }
    var lastTab by remember { mutableStateOf(0) }
    val alert by model.alert.collectAsStateWithLifecycle()

    // Разрешение на VPN спрашивает система; после «ОК» подключаем выбранную цепочку.
    var pendingChain by remember { mutableStateOf<Chain?>(null) }
    val vpnPermission = rememberLauncherForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        val chain = pendingChain
        pendingChain = null
        if (it.resultCode == android.app.Activity.RESULT_OK && chain != null) {
            model.connect(chain)
            screen = Screen.Tab(0)
        } else if (chain != null) {
            model.alert.value = "Без разрешения на VPN цепочку не поднять"
        }
    }
    val notifications = rememberLauncherForActivityResult(ActivityResultContracts.RequestPermission()) {}
    val context = androidx.compose.ui.platform.LocalContext.current
    val connect: (Chain) -> Unit = { chain ->
        if (Build.VERSION.SDK_INT >= 33) notifications.launch(Manifest.permission.POST_NOTIFICATIONS)
        val intent = VpnService.prepare(context)
        if (intent != null) {
            pendingChain = chain
            vpnPermission.launch(intent)
        } else {
            model.connect(chain)
            screen = Screen.Tab(0)
        }
    }

    val back = { screen = Screen.Tab(lastTab) }
    BackHandler(enabled = screen !is Screen.Tab) { back() }

    Scaffold(
        topBar = {
            val title = when (val s = screen) {
                is Screen.Tab -> listOf("Подключение", "Цепочки", "Профили")[s.index]
                is Screen.EditChain -> "Цепочка"
                is Screen.EditProfile -> "Профиль"
            }
            TopAppBar(
                title = { Text(title) },
                navigationIcon = {
                    if (screen !is Screen.Tab) {
                        IconButton(onClick = back) { Icon(Icons.AutoMirrored.Filled.ArrowBack, "Назад") }
                    }
                },
            )
        },
        bottomBar = {
            val s = screen
            if (s is Screen.Tab) {
                NavigationBar {
                    val items = listOf(
                        "Подключение" to Icons.Filled.Link,
                        "Цепочки" to Icons.Filled.List,
                        "Профили" to Icons.Filled.Person,
                    )
                    items.forEachIndexed { i, (label, icon) ->
                        NavigationBarItem(
                            selected = s.index == i,
                            onClick = { lastTab = i; screen = Screen.Tab(i) },
                            icon = { Icon(icon, label) },
                            label = { Text(label) },
                        )
                    }
                }
            }
        },
    ) { padding ->
        val modifier = Modifier.padding(padding)
        when (val s = screen) {
            is Screen.Tab -> when (s.index) {
                0 -> StatusScreen(model, connect, modifier)
                1 -> ChainsScreen(model, onOpen = { screen = Screen.EditChain(it) }, modifier)
                else -> ProfilesScreen(model, onOpen = { screen = Screen.EditProfile(it) }, modifier)
            }
            is Screen.EditChain -> ChainEditor(model, s.id, connect, onClose = back, modifier)
            is Screen.EditProfile -> ProfileEditor(model, s.id, onClose = back, modifier)
        }
    }

    alert?.let { message ->
        AlertDialog(
            onDismissRequest = { model.alert.value = null },
            confirmButton = { TextButton(onClick = { model.alert.value = null }) { Text("OK") } },
            text = { Text(message) },
        )
    }
}
