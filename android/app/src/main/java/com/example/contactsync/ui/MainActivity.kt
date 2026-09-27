package com.example.contactsync.ui

import android.os.Bundle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.padding
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material.icons.filled.ShoppingCart
import androidx.compose.material3.Icon
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.getValue
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.runtime.mutableIntStateOf
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import androidx.lifecycle.viewmodel.compose.viewModel
import com.example.contactsync.ui.vault.VaultScreen

/** FragmentActivity — нужна для системного диалога отпечатка (BiometricPrompt). */
class MainActivity : FragmentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MaterialTheme {
                val vm: AppViewModel = viewModel()
                val state by vm.state.collectAsStateWithLifecycle()
                if (state.loggedIn) MainTabs(state, vm) else LoginScreen(state, vm)
            }
        }
    }
}

private enum class Tab(val title: String, val icon: ImageVector) {
    CONTACTS("Контакты", Icons.Default.Person),
    PASSWORDS("Пароли", Icons.Default.Lock),
    APPS("Приложения", Icons.Default.ShoppingCart),
}

@Composable
private fun MainTabs(state: UiState, vm: AppViewModel) {
    var tab by rememberSaveable { mutableIntStateOf(0) }
    Scaffold(
        bottomBar = {
            NavigationBar {
                Tab.entries.forEachIndexed { index, item ->
                    NavigationBarItem(
                        selected = tab == index,
                        onClick = { tab = index },
                        icon = { Icon(item.icon, contentDescription = null) },
                        label = { Text(item.title) },
                    )
                }
            }
        },
    ) { padding ->
        Box(Modifier.padding(padding)) {
            when (Tab.entries[tab]) {
                Tab.CONTACTS -> HomeScreen(state, vm)
                Tab.PASSWORDS -> VaultScreen()
                Tab.APPS -> AppsScreen()
            }
        }
    }
}
