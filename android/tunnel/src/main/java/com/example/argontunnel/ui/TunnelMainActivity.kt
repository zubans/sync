package com.example.argontunnel.ui

import android.app.Activity
import android.content.Intent
import android.net.VpnService
import android.os.Build
import android.os.Bundle
import androidx.activity.ComponentActivity
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.verticalScroll
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.Checkbox
import androidx.compose.material3.FilterChip
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.RadioButton
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.runtime.Composable
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.saveable.rememberSaveable
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.text.input.KeyboardType
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import com.example.argontunnel.BuildConfig
import com.example.argontunnel.data.RoutingMode
import com.example.argontunnel.data.TunnelSettings
import com.example.argontunnel.service.ArgonVpnService

class TunnelMainActivity : ComponentActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge()
        setContent {
            MaterialTheme {
                Scaffold(modifier = Modifier.fillMaxSize()) { padding ->
                    TunnelScreen(modifier = Modifier.padding(padding))
                }
            }
        }
    }
}

@Composable
fun TunnelScreen(modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val settings = remember { TunnelSettings(context) }
    val status by ArgonVpnService.status.collectAsState()

    var serverHost by rememberSaveable { mutableStateOf(settings.serverHost) }
    var serverPort by rememberSaveable { mutableStateOf(settings.serverPort.toString()) }
    var bindInterface by rememberSaveable { mutableStateOf(settings.bindInterface) }
    var tunName by rememberSaveable { mutableStateOf(settings.tunInterfaceName) }
    var masterPassword by rememberSaveable { mutableStateOf(settings.masterPassword) }
    var showPassword by rememberSaveable { mutableStateOf(false) }
    var routingMode by rememberSaveable { mutableStateOf(settings.routingMode) }
    var selectedPackages by remember { mutableStateOf(settings.selectedPackages) }
    var customPackageInput by rememberSaveable { mutableStateOf("") }
    var testMaterial by rememberSaveable { mutableStateOf("") }

    val availableInterfaces = remember { settings.availableNetworkInterfaces() }
    val installedApps = remember { settings.installedRoutableApps() }

    fun saveSettings() {
        settings.serverHost = serverHost
        settings.serverPort = serverPort.toIntOrNull() ?: BuildConfig.DEFAULT_SERVER_PORT
        settings.bindInterface = bindInterface
        settings.tunInterfaceName = tunName
        settings.masterPassword = masterPassword
        settings.routingMode = routingMode
        settings.selectedPackages = selectedPackages
    }

    fun startVpnService() {
        saveSettings()
        val intent = Intent(context, ArgonVpnService::class.java).apply {
            action = ArgonVpnService.ACTION_START
        }
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            context.startForegroundService(intent)
        } else {
            context.startService(intent)
        }
    }

    val vpnPermissionLauncher = rememberLauncherForActivityResult(
        ActivityResultContracts.StartActivityForResult(),
    ) { result ->
        if (result.resultCode == Activity.RESULT_OK) {
            startVpnService()
        }
    }

    fun requestAndStartTunnel() {
        saveSettings()
        val prepareIntent = VpnService.prepare(context)
        if (prepareIntent != null) {
            vpnPermissionLauncher.launch(prepareIntent)
        } else {
            startVpnService()
        }
    }

    fun stopTunnel() {
        val intent = Intent(context, ArgonVpnService::class.java).apply {
            action = ArgonVpnService.ACTION_STOP
        }
        context.startService(intent)
    }

    Column(
        modifier = modifier
            .fillMaxSize()
            .padding(16.dp)
            .verticalScroll(rememberScrollState()),
        verticalArrangement = Arrangement.spacedBy(12.dp),
    ) {
        Text("Туннель Argon4", style = MaterialTheme.typography.headlineMedium)
        Text(
            "Шифрованная надстройка протокола обмена данными (Argon2id + AES-256-GCM)",
            style = MaterialTheme.typography.bodySmall,
        )

        // Карточка состояния туннеля
        Card(modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(4.dp)) {
                Text(
                    when {
                        status.running -> "Статус: Подключён (${status.assignedIp ?: "10.44.0.2/24"})"
                        status.connecting -> "Статус: Установка соединения…"
                        else -> "Статус: Отключён"
                    },
                    style = MaterialTheme.typography.titleMedium,
                )
                status.serverEndpoint?.let { Text("Сервер Ubuntu: $it", style = MaterialTheme.typography.bodySmall) }
                status.boundInterface?.let { Text("Исходящий интерфейс: $it", style = MaterialTheme.typography.bodySmall) }
                Text(
                    "Отправлено: ${status.txPackets} пак. (${status.txBytes} Б) · Принято: ${status.rxPackets} пак. (${status.rxBytes} Б)",
                    style = MaterialTheme.typography.bodySmall,
                )
                status.lastError?.let {
                    Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall)
                }
            }
        }

        if (status.running || status.connecting) {
            OutlinedButton(onClick = ::stopTunnel, modifier = Modifier.fillMaxWidth()) {
                Text("Остановить шифрованный туннель")
            }
        } else {
            Button(
                onClick = ::requestAndStartTunnel,
                enabled = masterPassword.isNotBlank() && serverHost.isNotBlank(),
                modifier = Modifier.fillMaxWidth(),
            ) {
                Text("Включить шифрованный туннель Argon4")
            }
        }

        HorizontalDivider()

        // Сетевой адрес и интерфейс
        Text("Адрес и сетевой интерфейс", style = MaterialTheme.typography.titleMedium)

        Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.fillMaxWidth()) {
            OutlinedTextField(
                value = serverHost,
                onValueChange = { serverHost = it },
                label = { Text("IP / хост сервера Ubuntu") },
                singleLine = true,
                modifier = Modifier.weight(2f),
            )
            OutlinedTextField(
                value = serverPort,
                onValueChange = { serverPort = it.filter(Char::isDigit) },
                label = { Text("UDP порт") },
                keyboardOptions = KeyboardOptions(keyboardType = KeyboardType.Number),
                singleLine = true,
                modifier = Modifier.weight(1f),
            )
        }

        OutlinedTextField(
            value = bindInterface,
            onValueChange = { bindInterface = it },
            label = { Text("Исходящий сетевой интерфейс клиента (пусто = авто, напр. wlan0)") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        if (availableInterfaces.isNotEmpty()) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp)) {
                FilterChip(
                    selected = bindInterface.isBlank(),
                    onClick = { bindInterface = "" },
                    label = { Text("Авто") },
                )
                availableInterfaces.take(3).forEach { ifaceDesc ->
                    val ifaceName = ifaceDesc.substringBefore(" ")
                    FilterChip(
                        selected = bindInterface == ifaceName,
                        onClick = { bindInterface = ifaceName },
                        label = { Text(ifaceDesc) },
                    )
                }
            }
        }

        OutlinedTextField(
            value = tunName,
            onValueChange = { tunName = it },
            label = { Text("Имя виртуального интерфейса туннеля (напр. argon0)") },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        // Мастер-пароль Argon4
        Text("Мастер-пароль (Argon2id)", style = MaterialTheme.typography.titleMedium)
        OutlinedTextField(
            value = masterPassword,
            onValueChange = { masterPassword = it },
            label = { Text("Мастер-пароль для шифрования материала") },
            visualTransformation = if (showPassword) VisualTransformation.None else PasswordVisualTransformation(),
            trailingIcon = {
                TextButton(onClick = { showPassword = !showPassword }) {
                    Text(if (showPassword) "Скрыть" else "Показать")
                }
            },
            singleLine = true,
            modifier = Modifier.fillMaxWidth(),
        )

        // Прямая отправка зашифрованного пакета/материала по мастер-паролю на интерфейс сервера
        Card(modifier = Modifier.fillMaxWidth()) {
            Column(Modifier.padding(12.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
                Text("Отправка зашифрованного материала", style = MaterialTheme.typography.titleSmall)
                OutlinedTextField(
                    value = testMaterial,
                    onValueChange = { testMaterial = it },
                    label = { Text("Пакет / материал для отправки на сервер") },
                    modifier = Modifier.fillMaxWidth(),
                )
                OutlinedButton(
                    onClick = {
                        saveSettings()
                        settings.pendingTestMaterial = testMaterial.ifBlank { "argon4-ping" }
                        val intent = Intent(context, ArgonVpnService::class.java).apply {
                            action = ArgonVpnService.ACTION_SEND_TEST_PAYLOAD
                        }
                        context.startService(intent)
                    },
                    enabled = masterPassword.isNotBlank() && serverHost.isNotBlank(),
                    modifier = Modifier.fillMaxWidth(),
                ) {
                    Text("Зашифровать и отправить пакет на интерфейс")
                }
            }
        }

        HorizontalDivider()

        // Универсальная маршрутизация приложений
        Text("Маршрутизация приложений", style = MaterialTheme.typography.titleMedium)
        Text(
            "По умолчанию заворачивается Contact Sync. Выберите дополнительные приложения (КБ, CEP и др.) или весь трафик.",
            style = MaterialTheme.typography.bodySmall,
        )

        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .clickable { routingMode = RoutingMode.SELECTED_APPS },
        ) {
            RadioButton(
                selected = routingMode == RoutingMode.SELECTED_APPS,
                onClick = { routingMode = RoutingMode.SELECTED_APPS },
            )
            Text("Выбранные приложения (по умолчанию Contact Sync)")
        }

        Row(
            verticalAlignment = Alignment.CenterVertically,
            modifier = Modifier
                .fillMaxWidth()
                .clickable { routingMode = RoutingMode.ALL_APPS },
        ) {
            RadioButton(
                selected = routingMode == RoutingMode.ALL_APPS,
                onClick = { routingMode = RoutingMode.ALL_APPS },
            )
            Text("Все приложения устройства (полный туннель)")
        }

        if (routingMode == RoutingMode.SELECTED_APPS) {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), verticalAlignment = Alignment.CenterVertically) {
                OutlinedTextField(
                    value = customPackageInput,
                    onValueChange = { customPackageInput = it.trim() },
                    label = { Text("Добавить пакет вручную (КБ / CEP / др.)") },
                    singleLine = true,
                    modifier = Modifier.weight(1f),
                )
                Button(
                    onClick = {
                        if (customPackageInput.isNotBlank()) {
                            selectedPackages = selectedPackages + customPackageInput
                            customPackageInput = ""
                            saveSettings()
                        }
                    },
                ) {
                    Text("+")
                }
            }

            installedApps.forEach { appItem ->
                val checked = selectedPackages.contains(appItem.packageName)
                Row(
                    verticalAlignment = Alignment.CenterVertically,
                    modifier = Modifier
                        .fillMaxWidth()
                        .clickable {
                            selectedPackages = if (checked) {
                                selectedPackages - appItem.packageName
                            } else {
                                selectedPackages + appItem.packageName
                            }
                            saveSettings()
                        }
                        .padding(vertical = 4.dp),
                ) {
                    Checkbox(
                        checked = checked,
                        onCheckedChange = { isChecked ->
                            selectedPackages = if (isChecked) {
                                selectedPackages + appItem.packageName
                            } else {
                                selectedPackages - appItem.packageName
                            }
                            saveSettings()
                        },
                    )
                    Column(Modifier.weight(1f)) {
                        Text(appItem.label, style = MaterialTheme.typography.bodyMedium)
                        Text(appItem.packageName, style = MaterialTheme.typography.bodySmall)
                    }
                }
            }
        }

        Spacer(Modifier.height(24.dp))
    }
}
