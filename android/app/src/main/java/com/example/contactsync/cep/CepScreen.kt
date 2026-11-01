package com.example.contactsync.cep

import androidx.activity.compose.BackHandler
import androidx.activity.compose.rememberLauncherForActivityResult
import androidx.activity.result.contract.ActivityResultContracts
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.filled.Add
import androidx.compose.material.icons.filled.List
import androidx.compose.material.icons.filled.Lock
import androidx.compose.material.icons.filled.Person
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.Button
import androidx.compose.material3.Card
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.ExperimentalMaterial3Api
import androidx.compose.material3.ExtendedFloatingActionButton
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.IconButton
import androidx.compose.material3.ListItem
import androidx.compose.material3.MaterialTheme
import androidx.compose.material3.NavigationBar
import androidx.compose.material3.NavigationBarItem
import androidx.compose.material3.OutlinedButton
import androidx.compose.material3.OutlinedTextField
import androidx.compose.material3.Scaffold
import androidx.compose.material3.Switch
import androidx.compose.material3.Text
import androidx.compose.material3.TextButton
import androidx.compose.material3.TopAppBar
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.input.PasswordVisualTransformation
import androidx.compose.ui.text.input.VisualTransformation
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.lifecycle.compose.collectAsStateWithLifecycle

/** Экраны CEP: «Подключение» (состояние и журнал), «Цепочки», «Профили» и их редакторы. */
private sealed interface Screen {
    data class Tab(val index: Int) : Screen
    data class EditChain(val id: String) : Screen
    data class EditProfile(val id: String) : Screen
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun CepApp(model: CepViewModel, connect: (CepChain) -> Unit) {
    var screen by remember { mutableStateOf<Screen>(Screen.Tab(0)) }
    var lastTab by remember { mutableStateOf(0) }
    val alert by model.alert.collectAsStateWithLifecycle()

    // После «Подключить» — на вкладку состояния, где виден журнал.
    val phase by CepEngine.state.collectAsStateWithLifecycle()
    LaunchedEffect(phase.phase) {
        if (phase.phase == CepPhase.CONNECTING) {
            lastTab = 0
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
                        "Подключение" to Icons.Filled.Lock,
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

// Подключение

@Composable
private fun StatusScreen(model: CepViewModel, connect: (CepChain) -> Unit, modifier: Modifier) {
    val state by CepEngine.state.collectAsStateWithLifecycle()
    val log by CepEngine.log.collectAsStateWithLifecycle()
    val store by model.store.collectAsStateWithLifecycle()

    Column(modifier.fillMaxSize().padding(16.dp), verticalArrangement = Arrangement.spacedBy(12.dp)) {
        Card(Modifier.fillMaxWidth()) {
            Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
                Text(
                    if (state.chainName.isEmpty()) state.phase.title else "${state.chainName}: ${state.phase.title}",
                    style = MaterialTheme.typography.titleMedium,
                    color = phaseColor(state.phase),
                )
                state.hops.forEachIndexed { i, hop ->
                    Text("${i + 1}. ${hop.name} — ${hop.protocol?.title ?: hop.proto}")
                }
                state.error?.let { Text(it, color = MaterialTheme.colorScheme.error) }
                if (state.isActive) {
                    Button(onClick = model::disconnect, enabled = state.phase != CepPhase.STOPPING) { Text("Отключить") }
                }
            }
        }
        if (!state.isActive) {
            if (store.chains.isEmpty()) {
                Text("Цепочек пока нет: создайте её на вкладке «Цепочки» или загрузите profiles.json с Mac на вкладке «Профили».")
            }
            store.chains.forEach { chain ->
                Row(verticalAlignment = Alignment.CenterVertically) {
                    Text(chain.name, Modifier.weight(1f))
                    OutlinedButton(onClick = { connect(chain) }) { Text("Подключить") }
                }
            }
        }
        Row(verticalAlignment = Alignment.CenterVertically) {
            Text("Журнал", style = MaterialTheme.typography.titleSmall, modifier = Modifier.weight(1f))
            TextButton(onClick = CepEngine::clearLog) { Text("Очистить") }
        }
        val listState = rememberLazyListState()
        LaunchedEffect(log.size) { if (log.isNotEmpty()) listState.scrollToItem(log.size - 1) }
        SelectionContainer(Modifier.weight(1f)) {
            LazyColumn(state = listState, modifier = Modifier.fillMaxSize().horizontalScroll(rememberScrollState())) {
                items(log) { Text(it, fontFamily = FontFamily.Monospace, fontSize = 11.sp, softWrap = false) }
            }
        }
    }
}

@Composable
private fun phaseColor(phase: CepPhase): Color = when (phase) {
    CepPhase.CONNECTED -> Color(0xFF2E7D32)
    CepPhase.FAILED -> MaterialTheme.colorScheme.error
    else -> MaterialTheme.colorScheme.onSurface
}

// Цепочки

@Composable
private fun ChainsScreen(model: CepViewModel, onOpen: (String) -> Unit, modifier: Modifier) {
    val store by model.store.collectAsStateWithLifecycle()
    Box(modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize()) {
            items(store.chains, key = { it.id }) { chain ->
                ListItem(
                    headlineContent = { Text(chain.name) },
                    supportingContent = {
                        Text(chain.hops.mapNotNull { model.profile(it)?.name }.joinToString(" → ").ifEmpty { "нет узлов" })
                    },
                    modifier = Modifier.clickable { onOpen(chain.id) },
                )
                HorizontalDivider()
            }
        }
        ExtendedFloatingActionButton(
            onClick = { onOpen(model.addChain().id) },
            icon = { Icon(Icons.Filled.Add, null) },
            text = { Text("Новая цепочка") },
            modifier = Modifier.align(Alignment.BottomEnd).padding(16.dp),
        )
    }
}

@Composable
private fun ChainEditor(model: CepViewModel, id: String, connect: (CepChain) -> Unit, onClose: () -> Unit, modifier: Modifier) {
    val store by model.store.collectAsStateWithLifecycle()
    val chain = store.chains.firstOrNull { it.id == id } ?: return
    var dns by remember(id) { mutableStateOf(chain.dns.joinToString(", ")) }
    var addOpen by remember { mutableStateOf(false) }

    LazyColumn(modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item {
            OutlinedTextField(
                value = chain.name, onValueChange = { model.saveChain(chain.copy(name = it)) },
                label = { Text("Название") }, modifier = Modifier.fillMaxWidth(),
            )
        }
        item { Text("Узлы по порядку: каждый следующий — через предыдущий", style = MaterialTheme.typography.titleSmall) }
        items(chain.hops.size) { i ->
            val p = model.profile(chain.hops[i])
            Row(verticalAlignment = Alignment.CenterVertically) {
                Column(Modifier.weight(1f)) {
                    Text("${i + 1}. ${p?.name ?: "?"}")
                    Text(p?.protocol?.title ?: "", style = MaterialTheme.typography.bodySmall)
                    p?.protocol?.unsupportedReason?.let { Text(it, color = MaterialTheme.colorScheme.error, style = MaterialTheme.typography.bodySmall) }
                }
                TextButton(enabled = i > 0, onClick = { model.saveChain(chain.copy(hops = chain.hops.swap(i, i - 1))) }) { Text("↑") }
                TextButton(enabled = i < chain.hops.size - 1, onClick = { model.saveChain(chain.copy(hops = chain.hops.swap(i, i + 1))) }) { Text("↓") }
                TextButton(onClick = { model.saveChain(chain.copy(hops = chain.hops.filterIndexed { j, _ -> j != i })) }) { Text("✕") }
            }
        }
        item {
            Box {
                OutlinedButton(onClick = { addOpen = true }, enabled = store.profiles.isNotEmpty()) { Text("Добавить узел") }
                DropdownMenu(expanded = addOpen, onDismissRequest = { addOpen = false }) {
                    store.profiles.forEach { p ->
                        DropdownMenuItem(
                            text = { Text("${p.name} (${p.protocol?.title})") },
                            onClick = { addOpen = false; model.saveChain(chain.copy(hops = chain.hops + p.id)) },
                        )
                    }
                }
            }
        }
        item {
            OutlinedTextField(
                value = dns,
                onValueChange = {
                    dns = it
                    model.saveChain(chain.copy(dns = it.split(',', ' ', ';').map(String::trim).filter(String::isNotEmpty)))
                },
                label = { Text("DNS цепочки") },
                supportingText = { Text("Через запятую; запросы к ним идут через цепочку") },
                modifier = Modifier.fillMaxWidth(),
            )
        }
        item {
            LabeledSwitch("Блокировать IPv6", chain.blockIPv6) { model.saveChain(chain.copy(blockIPv6 = it)) }
        }
        item {
            Row(horizontalArrangement = Arrangement.spacedBy(8.dp), modifier = Modifier.padding(vertical = 12.dp)) {
                Button(onClick = { connect(chain) }) { Text("Подключить") }
                OutlinedButton(onClick = { model.deleteChain(id); onClose() }) { Text("Удалить") }
            }
        }
    }
}

private fun <T> List<T>.swap(i: Int, j: Int): List<T> = toMutableList().also { val t = it[i]; it[i] = it[j]; it[j] = t }

// Профили

@Composable
private fun ProfilesScreen(model: CepViewModel, onOpen: (String) -> Unit, modifier: Modifier) {
    val store by model.store.collectAsStateWithLifecycle()
    var menu by remember { mutableStateOf(false) }
    var protoMenu by remember { mutableStateOf(false) }
    var linkDialog by remember { mutableStateOf(false) }
    val importFile = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        uri?.let(model::importStore)
    }
    val exportFile = rememberLauncherForActivityResult(ActivityResultContracts.CreateDocument("application/json")) { uri ->
        uri?.let(model::exportStore)
    }

    Box(modifier.fillMaxSize()) {
        LazyColumn(Modifier.fillMaxSize()) {
            items(store.profiles, key = { it.id }) { p ->
                ListItem(
                    headlineContent = { Text(p.name) },
                    supportingContent = { Text(p.protocol?.unsupportedReason ?: p.protocol?.title ?: p.proto) },
                    modifier = Modifier.clickable { onOpen(p.id) },
                )
                HorizontalDivider()
            }
        }
        Box(Modifier.align(Alignment.BottomEnd).padding(16.dp)) {
            ExtendedFloatingActionButton(onClick = { menu = true }, icon = { Icon(Icons.Filled.Add, null) }, text = { Text("Добавить") })
            DropdownMenu(expanded = menu, onDismissRequest = { menu = false }) {
                DropdownMenuItem(text = { Text("Профиль из ссылки…") }, onClick = { menu = false; linkDialog = true })
                DropdownMenuItem(text = { Text("Новый профиль…") }, onClick = { menu = false; protoMenu = true })
                DropdownMenuItem(text = { Text("Загрузить profiles.json с Mac…") }, onClick = { menu = false; importFile.launch(arrayOf("*/*")) })
                DropdownMenuItem(text = { Text("Сохранить profiles.json…") }, onClick = { menu = false; exportFile.launch("profiles.json") })
            }
            DropdownMenu(expanded = protoMenu, onDismissRequest = { protoMenu = false }) {
                VpnProtocol.entries.forEach { proto ->
                    DropdownMenuItem(
                        text = { Text(proto.title + (proto.unsupportedReason?.let { " — не поддерживается" } ?: "")) },
                        onClick = { protoMenu = false; onOpen(model.addProfile(proto).id) },
                    )
                }
            }
        }
    }

    if (linkDialog) {
        var text by remember { mutableStateOf("") }
        AlertDialog(
            onDismissRequest = { linkDialog = false },
            title = { Text("Профиль из ссылки") },
            text = {
                OutlinedTextField(
                    value = text, onValueChange = { text = it }, minLines = 3,
                    label = { Text("vless:// hy2:// hysteria2:// hysteria://") },
                    supportingText = { Text("Можно несколько, по одной в строке") },
                )
            },
            confirmButton = { TextButton(onClick = { model.importLinks(text); linkDialog = false }) { Text("Добавить") } },
            dismissButton = { TextButton(onClick = { linkDialog = false }) { Text("Отмена") } },
        )
    }
}

@Composable
private fun ProfileEditor(model: CepViewModel, id: String, onClose: () -> Unit, modifier: Modifier) {
    val store by model.store.collectAsStateWithLifecycle()
    val p = store.profiles.firstOrNull { it.id == id } ?: return
    val proto = p.protocol ?: return
    val save: (CepProfile) -> Unit = model::saveProfile
    val loadConfig = rememberLauncherForActivityResult(ActivityResultContracts.OpenDocument()) { uri ->
        uri?.let(model::readConfigFile)?.let { save(p.copy(config = it)) }
    }

    LazyColumn(modifier.fillMaxSize().padding(horizontal = 16.dp), verticalArrangement = Arrangement.spacedBy(8.dp)) {
        item { Text(proto.title, style = MaterialTheme.typography.titleMedium) }
        proto.unsupportedReason?.let { reason ->
            item { Text("$reason. Профиль хранится и переносится обратно на Mac, но в цепочке на телефоне не запустится.", color = MaterialTheme.colorScheme.error) }
        }
        item { Field("Название", p.name) { save(p.copy(name = it)) } }
        when {
            proto.isLink -> item {
                Field("Ссылка", p.uri, minLines = 3) { save(p.copy(uri = it.trim())) }
            }
            proto == VpnProtocol.OPENVPN || proto == VpnProtocol.AMNEZIAWG -> {
                item {
                    Field(if (proto == VpnProtocol.OPENVPN) "Конфиг .ovpn" else "Конфиг .conf", p.config, minLines = 6, mono = true) {
                        save(p.copy(config = it))
                    }
                }
                item { OutlinedButton(onClick = { loadConfig.launch(arrayOf("*/*")) }) { Text("Загрузить из файла…") } }
                if (proto == VpnProtocol.OPENVPN) {
                    item { Field("Логин (auth-user-pass)", p.username) { save(p.copy(username = it)) } }
                    item { Field("Пароль", p.password, secret = true) { save(p.copy(password = it)) } }
                }
            }
            else -> {
                item { Field("Сервер", p.server, hint = "host, host:port или https://host:port") { save(p.copy(server = it.trim())) } }
                item { Field("Логин", p.username) { save(p.copy(username = it)) } }
                item { Field("Пароль", p.password, secret = true) { save(p.copy(password = it)) } }
                if (proto == VpnProtocol.OPENCONNECT) {
                    item { FlavorPicker(p.openconnectFlavor) { save(p.copy(openconnectFlavor = it)) } }
                    item { Field("Отпечаток сертификата", p.serverCert, hint = "pin-sha256:… или sha1/sha256", mono = true) { save(p.copy(serverCert = it.trim())) } }
                    item { Field("Группа (authgroup)", p.authGroup) { save(p.copy(authGroup = it)) } }
                }
                item { LabeledSwitch("Не проверять сертификат", p.ignoreCertErrors) { save(p.copy(ignoreCertErrors = it)) } }
            }
        }
        item {
            Field(
                "Сети через этот узел", p.routes,
                hint = "Например 192.168.1.119, 10.0.0.0/8 — пойдут через этот узел, а не дальше по цепочке",
            ) { save(p.copy(routes = it)) }
        }
        item {
            OutlinedButton(onClick = { model.deleteProfile(id); onClose() }, modifier = Modifier.padding(vertical = 12.dp)) { Text("Удалить профиль") }
        }
    }
}

@Composable
private fun FlavorPicker(value: String, onChange: (String) -> Unit) {
    var open by remember { mutableStateOf(false) }
    val current = OpenConnectFlavor.entries.firstOrNull { it.raw == value } ?: OpenConnectFlavor.ANYCONNECT
    Box {
        OutlinedButton(onClick = { open = true }) { Text("Протокол: ${current.title}") }
        DropdownMenu(expanded = open, onDismissRequest = { open = false }) {
            OpenConnectFlavor.entries.forEach { f ->
                DropdownMenuItem(text = { Text(f.title) }, onClick = { open = false; onChange(f.raw) })
            }
        }
    }
}

@Composable
private fun Field(
    label: String,
    value: String,
    hint: String? = null,
    minLines: Int = 1,
    secret: Boolean = false,
    mono: Boolean = false,
    onChange: (String) -> Unit,
) {
    OutlinedTextField(
        value = value,
        onValueChange = onChange,
        label = { Text(label) },
        supportingText = hint?.let { { Text(it) } },
        minLines = minLines,
        singleLine = minLines == 1,
        visualTransformation = if (secret) PasswordVisualTransformation()
        else VisualTransformation.None,
        textStyle = if (mono) MaterialTheme.typography.bodySmall.copy(fontFamily = FontFamily.Monospace) else MaterialTheme.typography.bodyLarge,
        modifier = Modifier.fillMaxWidth(),
    )
}

@Composable
private fun LabeledSwitch(label: String, checked: Boolean, onChange: (Boolean) -> Unit) {
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth()) {
        Text(label, Modifier.weight(1f))
        Spacer(Modifier.width(8.dp))
        Switch(checked = checked, onCheckedChange = onChange)
    }
}
