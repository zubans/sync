package com.example.contactsync.cep

import android.Manifest
import android.app.Activity
import android.graphics.Color
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.material3.MaterialTheme
import com.example.contactsync.launcher.LauncherColors

/**
 * Отдельное приложение «CEP»: цепочки VPN-узлов (телефон → узел 1 → узел 2 → … → интернет),
 * Android-версия CEP для macOS с тем же форматом профилей.
 */
class CepActivity : ComponentActivity() {

    private val vm: CepViewModel by viewModels()

    /** Цепочка, для которой система спрашивает разрешение на VPN. */
    private var pendingChain: CepChain? = null

    private val vpnPermission = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) {
        val chain = pendingChain ?: return@registerForActivityResult
        pendingChain = null
        if (it.resultCode == Activity.RESULT_OK) vm.connect(chain)
        else vm.alert.value = "Без разрешения на VPN цепочку не поднять"
    }

    private val notifications = registerForActivityResult(ActivityResultContracts.RequestPermission()) {}

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(SystemBarStyle.dark(Color.TRANSPARENT), SystemBarStyle.dark(Color.TRANSPARENT))
        setContent {
            MaterialTheme(colorScheme = LauncherColors) {
                CepApp(vm, connect = ::connect)
            }
        }
    }

    /** Разрешение на VPN спрашивает система один раз; уведомления — чтобы была видна кнопка «Отключить». */
    private fun connect(chain: CepChain) {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU) notifications.launch(Manifest.permission.POST_NOTIFICATIONS)
        val intent = VpnService.prepare(this)
        if (intent == null) {
            vm.connect(chain)
        } else {
            pendingChain = chain
            vpnPermission.launch(intent)
        }
    }
}
