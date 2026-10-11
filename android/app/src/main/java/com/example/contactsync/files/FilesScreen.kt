package com.example.contactsync.files

import androidx.activity.compose.BackHandler
import androidx.compose.animation.AnimatedVisibility
import androidx.compose.animation.fadeIn
import androidx.compose.animation.fadeOut
import androidx.compose.animation.slideInVertically
import androidx.compose.animation.slideOutVertically
import androidx.compose.foundation.ExperimentalFoundationApi
import androidx.compose.foundation.background
import androidx.compose.foundation.border
import androidx.compose.foundation.clickable
import androidx.compose.foundation.combinedClickable
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.BoxWithConstraints
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.PaddingValues
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.safeDrawingPadding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.LazyListState
import androidx.compose.foundation.lazy.LazyRow
import androidx.compose.foundation.lazy.grid.GridCells
import androidx.compose.foundation.lazy.grid.LazyGridState
import androidx.compose.foundation.lazy.grid.LazyVerticalGrid
import androidx.compose.foundation.lazy.grid.items
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.itemsIndexed
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicTextField
import androidx.compose.foundation.text.KeyboardActions
import androidx.compose.foundation.text.KeyboardOptions
import androidx.compose.foundation.text.selection.SelectionContainer
import androidx.compose.foundation.verticalScroll
import androidx.compose.material.icons.Icons
import androidx.compose.material.icons.automirrored.filled.ArrowBack
import androidx.compose.material.icons.automirrored.filled.KeyboardArrowRight
import androidx.compose.material.icons.filled.Check
import androidx.compose.material.icons.filled.Close
import androidx.compose.material.icons.filled.Delete
import androidx.compose.material.icons.filled.Edit
import androidx.compose.material.icons.filled.Info
import androidx.compose.material.icons.filled.KeyboardArrowDown
import androidx.compose.material.icons.filled.KeyboardArrowUp
import androidx.compose.material.icons.filled.MoreVert
import androidx.compose.material.icons.filled.Refresh
import androidx.compose.material.icons.filled.Search
import androidx.compose.material.icons.filled.Share
import androidx.compose.material.icons.filled.Star
import androidx.compose.material.icons.outlined.Star
import androidx.compose.material3.AlertDialog
import androidx.compose.material3.CircularProgressIndicator
import androidx.compose.material3.DropdownMenu
import androidx.compose.material3.DropdownMenuItem
import androidx.compose.material3.HorizontalDivider
import androidx.compose.material3.Icon
import androidx.compose.material3.LinearProgressIndicator
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
import androidx.compose.ui.draw.alpha
import androidx.compose.ui.draw.clip
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalFocusManager
import androidx.compose.ui.text.TextRange
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.input.ImeAction
import androidx.compose.ui.text.input.TextFieldValue
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import com.example.contactsync.launcher.Accent
import com.example.contactsync.launcher.Background
import com.example.contactsync.launcher.CardColor
import com.example.contactsync.launcher.CardRaised
import com.example.contactsync.launcher.Down
import com.example.contactsync.launcher.RU
import com.example.contactsync.launcher.TextMain
import com.example.contactsync.launcher.TextMuted
import kotlinx.coroutines.delay
import java.io.File
import java.time.Instant
import java.time.LocalDate
import java.time.ZoneId
import java.time.format.DateTimeFormatter

/** Диалог, открытый поверх списка. */
private sealed interface FilesDialog {
    data object NewFolder : FilesDialog
    data class Rename(val entry: FileEntry) : FilesDialog
    data class Delete(val entries: List<FileEntry>) : FilesDialog
    data class Details(val entry: FileEntry) : FilesDialog
}

@Composable
internal fun FilesScreen(state: FilesState, vm: FilesViewModel, onRequestAccess: () -> Unit, onExit: () -> Unit) {
    if (!state.hasAccess) {
        AccessScreen(onRequestAccess)
        return
    }
    val context = LocalContext.current
    var dialog by remember { mutableStateOf<FilesDialog?>(null) }
    val byPath = remember(state.shown) { state.shown.associateBy { it.path } }
    val selected = state.selection.mapNotNull { byPath[it] }

    BackHandler {
        when {
            state.selection.isNotEmpty() -> vm.clearSelection()
            state.query.isNotEmpty() -> vm.search("")
            !vm.up() -> onExit()
        }
    }

    val onOpen: (FileEntry) -> Unit = { entry ->
        when {
            state.selection.isNotEmpty() -> vm.toggleSelection(entry.path)
            entry.isDir -> vm.open(entry.path)
            !context.openFile(entry) -> vm.showMessage("Нет приложения, чтобы открыть «${entry.name}»")
        }
    }
    val onAction: (FileEntry, ItemAction) -> Unit = { entry, action ->
        when (action) {
            ItemAction.OPEN_WITH -> if (!context.openFile(entry, choose = true)) vm.showMessage("Нет приложения для этого файла")
            ItemAction.SHOW_IN_FOLDER -> entry.path.substringBeforeLast('/').let(vm::open)
            ItemAction.COPY -> vm.copy(listOf(entry.path), cut = false)
            ItemAction.CUT -> vm.copy(listOf(entry.path), cut = true)
            ItemAction.RENAME -> dialog = FilesDialog.Rename(entry)
            ItemAction.SHARE -> context.shareFiles(listOf(entry))
            ItemAction.FAVORITE -> vm.toggleFavorite(entry.path)
            ItemAction.DETAILS -> dialog = FilesDialog.Details(entry)
            ItemAction.DELETE -> dialog = FilesDialog.Delete(listOf(entry))
        }
    }

    BoxWithConstraints(Modifier.fillMaxSize().background(Background)) {
        val wide = maxWidth >= 700.dp
        Row(Modifier.fillMaxSize().safeDrawingPadding()) {
            if (wide) Sidebar(state, vm, Modifier.width(272.dp).fillMaxHeight())
            Column(Modifier.weight(1f).fillMaxHeight().padding(start = if (wide) 0.dp else 12.dp, end = 12.dp, top = 12.dp)) {
                if (state.selection.isNotEmpty()) {
                    SelectionBar(
                        state = state,
                        selected = selected,
                        onClose = vm::clearSelection,
                        onSelectAll = vm::selectAll,
                        onCopy = { vm.copy(state.selection, cut = false) },
                        onCut = { vm.copy(state.selection, cut = true) },
                        onRename = { selected.singleOrNull()?.let { dialog = FilesDialog.Rename(it) } },
                        onShare = { context.shareFiles(selected) },
                        onDetails = { selected.singleOrNull()?.let { dialog = FilesDialog.Details(it) } },
                        onDelete = { dialog = FilesDialog.Delete(selected) },
                    )
                } else {
                    TopBar(state, vm, wide)
                }
                if (!wide) PlaceChips(state, vm)
                Breadcrumbs(state, vm)
                Box(Modifier.weight(1f).fillMaxWidth().clip(RoundedCornerShape(topStart = 24.dp, topEnd = 24.dp)).background(CardColor)) {
                    FileList(state, vm, onOpen, onAction)
                }
            }
        }

        Column(
            Modifier.align(Alignment.BottomEnd).safeDrawingPadding().padding(20.dp),
            horizontalAlignment = Alignment.End,
            verticalArrangement = Arrangement.spacedBy(12.dp),
        ) {
            AnimatedVisibility(state.task != null, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                state.task?.let { TaskCard(it, vm::cancelTask) }
            }
            AnimatedVisibility(state.clipboard != null && state.task == null, enter = slideInVertically { it } + fadeIn(), exit = slideOutVertically { it } + fadeOut()) {
                state.clipboard?.let { PasteCard(it, state.query.isEmpty(), vm::paste, vm::clearClipboard) }
            }
            AnimatedVisibility(state.selection.isEmpty() && state.query.isEmpty(), enter = fadeIn(), exit = fadeOut()) {
                Fab(Glyphs.NewFolder, "Папка") { dialog = FilesDialog.NewFolder }
            }
        }

        // Сверху, под панелью: внизу место занято кнопкой «Папка» и карточками буфера и операций.
        MessageToast(state.message, vm::consumeMessage, Modifier.align(Alignment.TopCenter).safeDrawingPadding().padding(top = 76.dp))
    }

    when (val d = dialog) {
        FilesDialog.NewFolder -> NameDialog("Новая папка", "Создать", "Новая папка", rename = false, onDismiss = { dialog = null }) {
            vm.createFolder(it)
            dialog = null
        }
        is FilesDialog.Rename -> NameDialog("Переименовать", "Сохранить", d.entry.name, rename = true, onDismiss = { dialog = null }) {
            vm.rename(d.entry.path, it)
            dialog = null
        }
        is FilesDialog.Delete -> DeleteDialog(d.entries, onDismiss = { dialog = null }) {
            vm.delete(d.entries.map { it.path })
            dialog = null
        }
        is FilesDialog.Details -> DetailsDialog(d.entry, vm, onDismiss = { dialog = null })
        null -> Unit
    }
}

/* ---------- Боковая панель ---------- */

@Composable
private fun Sidebar(state: FilesState, vm: FilesViewModel, modifier: Modifier) {
    val current = currentPlace(state)
    Column(modifier.verticalScroll(rememberScrollState()).padding(horizontal = 16.dp, vertical = 12.dp)) {
        Text("Файлы", color = TextMain, fontSize = 30.sp, modifier = Modifier.padding(start = 8.dp, bottom = 16.dp))
        val storages = state.places.filter { it.kind == PlaceKind.STORAGE || it.kind == PlaceKind.SD_CARD }
        storages.forEach { StorageCard(it, it == current) { vm.open(it.path) } }
        SectionTitle("Папки")
        state.places.filter { it.kind !in setOf(PlaceKind.STORAGE, PlaceKind.SD_CARD, PlaceKind.FAVORITE) }.forEach {
            PlaceRow(it, it == current) { vm.open(it.path) }
        }
        val favorites = state.places.filter { it.kind == PlaceKind.FAVORITE }
        SectionTitle("Избранное")
        if (favorites.isEmpty()) {
            Text(
                "Добавьте папку через «⋮ → В избранное»",
                color = TextMuted,
                fontSize = 13.sp,
                modifier = Modifier.padding(horizontal = 12.dp, vertical = 4.dp),
            )
        }
        favorites.forEach { PlaceRow(it, it == current) { vm.open(it.path) } }
    }
}

@Composable
private fun SectionTitle(text: String) {
    Text(
        text.uppercase(),
        color = TextMuted,
        fontSize = 12.sp,
        letterSpacing = 1.sp,
        modifier = Modifier.padding(start = 12.dp, top = 20.dp, bottom = 6.dp),
    )
}

@Composable
private fun StorageCard(place: Place, selected: Boolean, onClick: () -> Unit) {
    val used = (place.totalBytes - place.freeBytes).coerceAtLeast(0)
    val fraction = if (place.totalBytes > 0) used.toFloat() / place.totalBytes else 0f
    Column(
        Modifier
            .padding(bottom = 8.dp)
            .fillMaxWidth()
            .clip(RoundedCornerShape(18.dp))
            .background(if (selected) Accent.copy(alpha = 0.16f) else CardColor)
            .clickable(onClick = onClick)
            .padding(14.dp),
    ) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Icon(place.kind.glyph, null, tint = Accent, modifier = Modifier.size(22.dp))
            Spacer(Modifier.width(10.dp))
            Text(place.label, color = TextMain, fontSize = 16.sp, fontWeight = FontWeight.Medium)
        }
        Spacer(Modifier.height(10.dp))
        Box(Modifier.fillMaxWidth().height(6.dp).clip(CircleShape).background(CardRaised)) {
            Box(
                Modifier.fillMaxWidth(fraction).fillMaxHeight().clip(CircleShape)
                    .background(if (fraction > 0.9f) Down else Accent),
            )
        }
        Spacer(Modifier.height(6.dp))
        Text(
            "Свободно ${formatSize(place.freeBytes)} из ${formatSize(place.totalBytes)}",
            color = TextMuted,
            fontSize = 12.sp,
        )
    }
}

@Composable
private fun PlaceRow(place: Place, selected: Boolean, onClick: () -> Unit) {
    Row(
        Modifier
            .fillMaxWidth()
            .clip(RoundedCornerShape(12.dp))
            .background(if (selected) Accent.copy(alpha = 0.16f) else Color.Transparent)
            .clickable(onClick = onClick)
            .padding(horizontal = 12.dp, vertical = 10.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(place.kind.glyph, null, tint = if (selected) Accent else place.kind.tint, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(12.dp))
        Text(
            place.label,
            color = if (selected) Accent else TextMain,
            fontSize = 15.sp,
            maxLines = 1,
            overflow = TextOverflow.Ellipsis,
        )
    }
}

/** На узком экране вместо боковой панели — строка «чипов» с местами. */
@Composable
private fun PlaceChips(state: FilesState, vm: FilesViewModel) {
    val current = currentPlace(state)
    LazyRow(
        horizontalArrangement = Arrangement.spacedBy(8.dp),
        contentPadding = PaddingValues(vertical = 4.dp),
        modifier = Modifier.padding(top = 8.dp),
    ) {
        items(state.places, key = { it.path + it.kind }) { place ->
            val selected = place == current
            Row(
                Modifier
                    .clip(RoundedCornerShape(12.dp))
                    .background(if (selected) Accent else CardRaised)
                    .clickable { vm.open(place.path) }
                    .padding(horizontal = 12.dp, vertical = 8.dp),
                verticalAlignment = Alignment.CenterVertically,
            ) {
                Icon(place.kind.glyph, null, tint = if (selected) Background else place.kind.tint, modifier = Modifier.size(16.dp))
                Spacer(Modifier.width(6.dp))
                Text(place.label, color = if (selected) Background else TextMain, fontSize = 14.sp)
            }
        }
    }
}

private val PlaceKind.glyph: ImageVector
    get() = when (this) {
        PlaceKind.STORAGE -> Glyphs.Phone
        PlaceKind.SD_CARD -> Glyphs.SdCard
        PlaceKind.DOWNLOADS -> Glyphs.Download
        PlaceKind.CAMERA -> Glyphs.Camera
        PlaceKind.PICTURES -> Glyphs.Image
        PlaceKind.DOCUMENTS -> Glyphs.Document
        PlaceKind.MUSIC -> Glyphs.Music
        PlaceKind.MOVIES -> Glyphs.Movie
        PlaceKind.FAVORITE -> Glyphs.Folder
    }

private val PlaceKind.tint: Color
    get() = when (this) {
        PlaceKind.DOWNLOADS -> Accent
        PlaceKind.CAMERA, PlaceKind.PICTURES -> FileKind.IMAGE.color
        PlaceKind.DOCUMENTS -> FileKind.DOCUMENT.color
        PlaceKind.MUSIC -> FileKind.AUDIO.color
        PlaceKind.MOVIES -> FileKind.VIDEO.color
        PlaceKind.FAVORITE -> FileKind.SLIDES.color
        else -> TextMuted
    }

/** Самое точное место, в котором лежит текущая папка: «Загрузки» точнее «Внутренней памяти». */
private fun currentPlace(state: FilesState): Place? =
    state.places.filter { state.dir == it.path || state.dir.startsWith(it.path + "/") }.maxByOrNull { it.path.length }

/* ---------- Верхняя панель ---------- */

@Composable
private fun TopBar(state: FilesState, vm: FilesViewModel, wide: Boolean) {
    val focus = LocalFocusManager.current
    var sortMenu by remember { mutableStateOf(false) }
    var moreMenu by remember { mutableStateOf(false) }
    Row(verticalAlignment = Alignment.CenterVertically, modifier = Modifier.fillMaxWidth().height(56.dp)) {
        if (!wide) {
            Text("Файлы", color = TextMain, fontSize = 24.sp, modifier = Modifier.padding(end = 12.dp))
        }
        Row(
            Modifier
                .weight(1f)
                .height(48.dp)
                .clip(RoundedCornerShape(24.dp))
                .background(CardColor)
                .padding(horizontal = 16.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Icons.Default.Search, null, tint = TextMuted, modifier = Modifier.size(20.dp))
            Spacer(Modifier.width(10.dp))
            Box(Modifier.weight(1f)) {
                if (state.query.isEmpty()) {
                    Text("Поиск в «${currentPlace(state)?.takeIf { it.path == state.dir }?.label ?: File(state.dir).name}» и вложенных папках", color = TextMuted, fontSize = 15.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
                }
                BasicTextField(
                    value = state.query,
                    onValueChange = vm::search,
                    singleLine = true,
                    textStyle = TextStyle(color = TextMain, fontSize = 15.sp),
                    cursorBrush = SolidColor(Accent),
                    keyboardOptions = KeyboardOptions(imeAction = ImeAction.Search),
                    keyboardActions = KeyboardActions(onSearch = { focus.clearFocus() }),
                    modifier = Modifier.fillMaxWidth(),
                )
            }
            if (state.searching) {
                CircularProgressIndicator(color = Accent, strokeWidth = 2.dp, modifier = Modifier.size(18.dp))
            } else if (state.query.isNotEmpty()) {
                Icon(
                    Icons.Default.Close,
                    "Очистить",
                    tint = TextMuted,
                    modifier = Modifier.size(20.dp).clip(CircleShape).clickable {
                        vm.search("")
                        focus.clearFocus()
                    },
                )
            }
        }
        Spacer(Modifier.width(8.dp))
        Box {
            BarButton(Glyphs.Sort, "Сортировка") { sortMenu = true }
            DropdownMenu(sortMenu, onDismissRequest = { sortMenu = false }, containerColor = CardRaised) {
                SortField.entries.forEach { field ->
                    val active = state.sort.field == field
                    DropdownMenuItem(
                        text = { Text(field.title, color = if (active) Accent else TextMain) },
                        trailingIcon = {
                            if (active) {
                                Icon(
                                    if (state.sort.descending) Icons.Default.KeyboardArrowDown else Icons.Default.KeyboardArrowUp,
                                    null,
                                    tint = Accent,
                                )
                            }
                        },
                        onClick = { vm.setSort(field) },
                    )
                }
            }
        }
        BarButton(if (state.grid) Glyphs.Rows else Glyphs.Grid, if (state.grid) "Список" else "Сетка", onClick = vm::toggleGrid)
        Box {
            BarButton(Icons.Default.MoreVert, "Ещё") { moreMenu = true }
            DropdownMenu(moreMenu, onDismissRequest = { moreMenu = false }, containerColor = CardRaised) {
                val favorite = state.dir in state.favorites
                DropdownMenuItem(
                    text = { Text(if (favorite) "Убрать из избранного" else "В избранное", color = TextMain) },
                    leadingIcon = { Icon(if (favorite) Icons.Filled.Star else Icons.Outlined.Star, null, tint = FileKind.SLIDES.color) },
                    onClick = {
                        vm.toggleFavorite(state.dir)
                        moreMenu = false
                    },
                )
                DropdownMenuItem(
                    text = { Text(if (state.showHidden) "Не показывать скрытые" else "Показывать скрытые", color = TextMain) },
                    leadingIcon = { Icon(Glyphs.Eye, null, tint = TextMuted) },
                    onClick = {
                        vm.toggleHidden()
                        moreMenu = false
                    },
                )
                DropdownMenuItem(
                    text = { Text("Обновить", color = TextMain) },
                    leadingIcon = { Icon(Icons.Default.Refresh, null, tint = TextMuted) },
                    onClick = {
                        vm.reload()
                        moreMenu = false
                    },
                )
            }
        }
    }
}

@Composable
private fun SelectionBar(
    state: FilesState,
    selected: List<FileEntry>,
    onClose: () -> Unit,
    onSelectAll: () -> Unit,
    onCopy: () -> Unit,
    onCut: () -> Unit,
    onRename: () -> Unit,
    onShare: () -> Unit,
    onDetails: () -> Unit,
    onDelete: () -> Unit,
) {
    Row(
        Modifier.fillMaxWidth().height(56.dp).clip(RoundedCornerShape(28.dp)).background(Accent.copy(alpha = 0.16f)).padding(horizontal = 4.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        BarButton(Icons.Default.Close, "Снять выделение", onClick = onClose)
        Text(
            "Выбрано: ${state.selection.size}",
            color = TextMain,
            fontSize = 17.sp,
            fontWeight = FontWeight.Medium,
            modifier = Modifier.weight(1f).padding(start = 4.dp),
        )
        if (state.selection.size < state.shown.size) BarButton(Glyphs.SelectAll, "Выбрать все", onClick = onSelectAll)
        BarButton(Glyphs.Copy, "Копировать", onClick = onCopy)
        BarButton(Glyphs.Cut, "Вырезать", onClick = onCut)
        if (selected.size == 1) BarButton(Icons.Default.Edit, "Переименовать", onClick = onRename)
        if (selected.isNotEmpty() && selected.none { it.isDir }) BarButton(Icons.Default.Share, "Поделиться", onClick = onShare)
        if (selected.size == 1) BarButton(Icons.Default.Info, "Свойства", onClick = onDetails)
        BarButton(Icons.Default.Delete, "Удалить", tint = Down, onClick = onDelete)
    }
}

@Composable
private fun BarButton(icon: ImageVector, description: String, tint: Color = TextMain, onClick: () -> Unit) {
    Box(
        Modifier.size(48.dp).clip(CircleShape).clickable(onClick = onClick),
        contentAlignment = Alignment.Center,
    ) {
        Icon(icon, description, tint = tint, modifier = Modifier.size(22.dp))
    }
}

/** Путь от корня хранилища: «Внутренняя память › Download › Telegram». Каждый шаг кликабелен. */
@Composable
private fun Breadcrumbs(state: FilesState, vm: FilesViewModel) {
    val root = state.places
        .filter { it.kind == PlaceKind.STORAGE || it.kind == PlaceKind.SD_CARD }
        .filter { state.dir == it.path || state.dir.startsWith(it.path + "/") }
        .maxByOrNull { it.path.length }
    val crumbs = remember(state.dir, root) {
        if (root == null) {
            listOf(state.dir to state.dir)
        } else {
            val parts = state.dir.removePrefix(root.path).split('/').filter { it.isNotEmpty() }
            listOf(root.label to root.path) + parts.runningFold(root.path) { acc, p -> "$acc/$p" }.drop(1).zip(parts) { path, name -> name to path }
        }
    }
    val listState = rememberLazyListState()
    LaunchedEffect(crumbs.size) { listState.animateScrollToItem((crumbs.size - 1).coerceAtLeast(0)) }
    LazyRow(
        state = listState,
        verticalAlignment = Alignment.CenterVertically,
        contentPadding = PaddingValues(vertical = 8.dp),
        modifier = Modifier.fillMaxWidth(),
    ) {
        itemsIndexed(crumbs) { index, (name, path) ->
            val last = index == crumbs.lastIndex
            if (index > 0) Icon(Icons.AutoMirrored.Filled.KeyboardArrowRight, null, tint = TextMuted, modifier = Modifier.size(18.dp))
            Text(
                name,
                color = if (last) TextMain else TextMuted,
                fontSize = 15.sp,
                fontWeight = if (last) FontWeight.Medium else FontWeight.Normal,
                modifier = Modifier.clip(RoundedCornerShape(8.dp)).clickable(enabled = !last) { vm.open(path) }.padding(horizontal = 8.dp, vertical = 6.dp),
            )
        }
    }
}

/* ---------- Список файлов ---------- */

private enum class ItemAction(val title: String) {
    OPEN_WITH("Открыть с помощью…"),
    SHOW_IN_FOLDER("Показать в папке"),
    COPY("Копировать"),
    CUT("Вырезать"),
    RENAME("Переименовать"),
    SHARE("Поделиться"),
    FAVORITE("В избранное"),
    DETAILS("Свойства"),
    DELETE("Удалить"),
}

@Composable
private fun FileList(state: FilesState, vm: FilesViewModel, onOpen: (FileEntry) -> Unit, onAction: (FileEntry, ItemAction) -> Unit) {
    val items = state.shown
    val searching = state.searchResults != null
    when {
        items.isEmpty() && (state.loading || state.searching) -> Box(Modifier.fillMaxSize(), contentAlignment = Alignment.Center) {
            CircularProgressIndicator(color = Accent)
        }
        items.isEmpty() -> EmptyState(if (searching) "Ничего не найдено" else "Папка пуста", if (searching) "Попробуйте другое имя" else "Создайте папку кнопкой ниже или вставьте файлы")
        state.grid -> {
            // Своя позиция прокрутки у каждой папки — при возврате на уровень вверх список остаётся на месте.
            val key = if (searching) "search" else state.dir
            val gridState = remember(key) { LazyGridState(vm.scrollPositions[key] ?: 0) }
            DisposableEffect(key) { onDispose { vm.scrollPositions[key] = gridState.firstVisibleItemIndex } }
            LazyVerticalGrid(
                GridCells.Adaptive(136.dp),
                state = gridState,
                contentPadding = PaddingValues(12.dp, 12.dp, 12.dp, 120.dp),
                verticalArrangement = Arrangement.spacedBy(8.dp),
                horizontalArrangement = Arrangement.spacedBy(8.dp),
            ) {
                items(items, key = { it.path }) { entry ->
                    FileTile(entry, state, onOpen, vm::toggleSelection, onAction, Modifier.animateItem())
                }
            }
        }
        else -> {
            val key = if (searching) "search" else state.dir
            val listState = remember(key) { LazyListState(vm.scrollPositions[key] ?: 0) }
            DisposableEffect(key) { onDispose { vm.scrollPositions[key] = listState.firstVisibleItemIndex } }
            LazyColumn(state = listState, contentPadding = PaddingValues(top = 8.dp, bottom = 120.dp)) {
                if (searching) {
                    item(key = "header") {
                        Text(
                            "Найдено: ${itemsCount(items.size)}" + if (items.size >= 500) " (показаны первые)" else "",
                            color = TextMuted,
                            fontSize = 13.sp,
                            modifier = Modifier.padding(horizontal = 20.dp, vertical = 8.dp),
                        )
                    }
                }
                items(items, key = { it.path }) { entry ->
                    FileRow(entry, state, onOpen, vm::toggleSelection, onAction, Modifier.animateItem())
                }
            }
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun FileRow(
    entry: FileEntry,
    state: FilesState,
    onOpen: (FileEntry) -> Unit,
    onToggle: (String) -> Unit,
    onAction: (FileEntry, ItemAction) -> Unit,
    modifier: Modifier,
) {
    val selected = entry.path in state.selection
    Row(
        modifier
            .fillMaxWidth()
            .padding(horizontal = 8.dp, vertical = 1.dp)
            .clip(RoundedCornerShape(14.dp))
            .background(if (selected) Accent.copy(alpha = 0.16f) else Color.Transparent)
            .combinedClickable(onClick = { onOpen(entry) }, onLongClick = { onToggle(entry.path) })
            .padding(horizontal = 12.dp, vertical = 8.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        FileIcon(entry, 44.dp, Modifier.dimIf(entry.hidden))
        Spacer(Modifier.width(14.dp))
        Column(Modifier.weight(1f)) {
            Text(entry.name, color = TextMain, fontSize = 16.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
            Text(subtitle(entry, state), color = TextMuted, fontSize = 13.sp, maxLines = 1, overflow = TextOverflow.Ellipsis)
        }
        if (state.selection.isNotEmpty()) {
            CheckMark(selected)
        } else {
            ItemMenu(entry, state, onAction)
        }
    }
}

@OptIn(ExperimentalFoundationApi::class)
@Composable
private fun FileTile(
    entry: FileEntry,
    state: FilesState,
    onOpen: (FileEntry) -> Unit,
    onToggle: (String) -> Unit,
    onAction: (FileEntry, ItemAction) -> Unit,
    modifier: Modifier,
) {
    val selected = entry.path in state.selection
    Box(
        modifier
            .clip(RoundedCornerShape(18.dp))
            .background(if (selected) Accent.copy(alpha = 0.16f) else CardRaised.copy(alpha = 0.5f))
            .then(if (selected) Modifier.border(1.5.dp, Accent, RoundedCornerShape(18.dp)) else Modifier)
            .combinedClickable(onClick = { onOpen(entry) }, onLongClick = { onToggle(entry.path) }),
    ) {
        Column(Modifier.fillMaxWidth().padding(12.dp), horizontalAlignment = Alignment.CenterHorizontally) {
            FileIcon(entry, 84.dp, Modifier.dimIf(entry.hidden))
            Spacer(Modifier.height(8.dp))
            Text(
                entry.name,
                color = TextMain,
                fontSize = 14.sp,
                maxLines = 2,
                minLines = 2,
                textAlign = TextAlign.Center,
                overflow = TextOverflow.Ellipsis,
            )
            Text(
                if (entry.isDir) itemsCount(entry.childCount) else formatSize(entry.size),
                color = TextMuted,
                fontSize = 12.sp,
                maxLines = 1,
            )
        }
        Box(Modifier.align(Alignment.TopEnd).padding(4.dp)) {
            if (state.selection.isNotEmpty()) CheckMark(selected) else ItemMenu(entry, state, onAction)
        }
    }
}

/** Скрытые файлы (с точкой в начале имени) показываются приглушёнными. */
private fun Modifier.dimIf(hidden: Boolean) = if (hidden) alpha(0.5f) else this

@Composable
private fun CheckMark(selected: Boolean) {
    Box(
        Modifier
            .padding(8.dp)
            .size(26.dp)
            .clip(CircleShape)
            .background(if (selected) Accent else Color.Transparent)
            .border(2.dp, if (selected) Accent else TextMuted, CircleShape),
        contentAlignment = Alignment.Center,
    ) {
        if (selected) Icon(Icons.Default.Check, null, tint = Background, modifier = Modifier.size(18.dp))
    }
}

@Composable
private fun ItemMenu(entry: FileEntry, state: FilesState, onAction: (FileEntry, ItemAction) -> Unit) {
    var open by remember { mutableStateOf(false) }
    Box {
        Box(Modifier.size(40.dp).clip(CircleShape).clickable { open = true }, contentAlignment = Alignment.Center) {
            Icon(Icons.Default.MoreVert, "Действия", tint = TextMuted, modifier = Modifier.size(20.dp))
        }
        DropdownMenu(open, onDismissRequest = { open = false }, containerColor = CardRaised) {
            ItemAction.entries
                .filter {
                    when (it) {
                        ItemAction.OPEN_WITH, ItemAction.SHARE -> !entry.isDir
                        ItemAction.FAVORITE -> entry.isDir
                        ItemAction.SHOW_IN_FOLDER -> state.searchResults != null
                        else -> true
                    }
                }
                .forEach { action ->
                    if (action == ItemAction.DELETE) HorizontalDivider(color = CardColor)
                    val title = if (action == ItemAction.FAVORITE && entry.path in state.favorites) "Убрать из избранного" else action.title
                    DropdownMenuItem(
                        text = { Text(title, color = if (action == ItemAction.DELETE) Down else TextMain) },
                        onClick = {
                            open = false
                            onAction(entry, action)
                        },
                    )
                }
        }
    }
}

private fun subtitle(entry: FileEntry, state: FilesState): String {
    val info = if (entry.isDir) itemsCount(entry.childCount) else formatSize(entry.size)
    val base = "${formatDate(entry.modified)} · $info"
    if (state.searchResults == null) return base
    val parent = entry.path.substringBeforeLast('/').removePrefix(state.dir).trimStart('/')
    return if (parent.isEmpty()) base else "$parent · $base"
}

private val TIME = DateTimeFormatter.ofPattern("HH:mm", RU)
private val DAY = DateTimeFormatter.ofPattern("d MMM, HH:mm", RU)
private val FULL = DateTimeFormatter.ofPattern("d MMM yyyy", RU)

/** «Сегодня, 14:30», «Вчера, 09:12», «3 окт., 18:00», «12 мар. 2024». */
internal fun formatDate(millis: Long): String {
    val zone = ZoneId.systemDefault()
    val time = Instant.ofEpochMilli(millis).atZone(zone)
    val today = LocalDate.now(zone)
    return when (time.toLocalDate()) {
        today -> "Сегодня, ${TIME.format(time)}"
        today.minusDays(1) -> "Вчера, ${TIME.format(time)}"
        else -> if (time.year == today.year) DAY.format(time) else FULL.format(time)
    }
}

@Composable
private fun EmptyState(title: String, hint: String) {
    Column(Modifier.fillMaxSize().padding(32.dp), horizontalAlignment = Alignment.CenterHorizontally, verticalArrangement = Arrangement.Center) {
        Icon(Glyphs.Folder, null, tint = CardRaised, modifier = Modifier.size(96.dp))
        Spacer(Modifier.height(16.dp))
        Text(title, color = TextMain, fontSize = 18.sp)
        Text(hint, color = TextMuted, fontSize = 14.sp, textAlign = TextAlign.Center)
    }
}

/* ---------- Плавающие элементы ---------- */

@Composable
private fun Fab(icon: ImageVector, label: String, onClick: () -> Unit) {
    Row(
        Modifier.height(56.dp).clip(RoundedCornerShape(18.dp)).background(Accent).clickable(onClick = onClick).padding(horizontal = 20.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(icon, null, tint = Background, modifier = Modifier.size(22.dp))
        Spacer(Modifier.width(10.dp))
        Text(label, color = Background, fontSize = 16.sp, fontWeight = FontWeight.Medium)
    }
}

@Composable
private fun PasteCard(clip: FileClipboard, enabled: Boolean, onPaste: () -> Unit, onClear: () -> Unit) {
    Row(
        Modifier.clip(RoundedCornerShape(18.dp)).background(CardRaised).padding(start = 16.dp, end = 6.dp, top = 6.dp, bottom = 6.dp),
        verticalAlignment = Alignment.CenterVertically,
    ) {
        Icon(if (clip.cut) Glyphs.Cut else Glyphs.Copy, null, tint = Accent, modifier = Modifier.size(20.dp))
        Spacer(Modifier.width(10.dp))
        Text(
            (if (clip.cut) "Вырезано: " else "Скопировано: ") + itemsCount(clip.paths.size),
            color = TextMain,
            fontSize = 15.sp,
        )
        Spacer(Modifier.width(12.dp))
        Row(
            Modifier
                .clip(RoundedCornerShape(12.dp))
                .background(if (enabled) Accent else CardColor)
                .clickable(enabled = enabled, onClick = onPaste)
                .padding(horizontal = 14.dp, vertical = 10.dp),
            verticalAlignment = Alignment.CenterVertically,
        ) {
            Icon(Glyphs.Paste, null, tint = if (enabled) Background else TextMuted, modifier = Modifier.size(18.dp))
            Spacer(Modifier.width(6.dp))
            Text("Вставить сюда", color = if (enabled) Background else TextMuted, fontSize = 15.sp, fontWeight = FontWeight.Medium)
        }
        BarButton(Icons.Default.Close, "Очистить буфер", tint = TextMuted, onClick = onClear)
    }
}

@Composable
private fun TaskCard(task: FileTask, onCancel: () -> Unit) {
    Column(Modifier.width(320.dp).clip(RoundedCornerShape(18.dp)).background(CardRaised).padding(16.dp)) {
        Row(verticalAlignment = Alignment.CenterVertically) {
            Column(Modifier.weight(1f)) {
                Text(task.title, color = TextMain, fontSize = 15.sp, fontWeight = FontWeight.Medium)
                Text(
                    task.fraction?.let { "${(it * 100).toInt()} % · ${formatSize(task.doneBytes)} из ${formatSize(task.totalBytes)}" } ?: "Подготовка…",
                    color = TextMuted,
                    fontSize = 13.sp,
                )
            }
            TextButton(onClick = onCancel) { Text("Отмена", color = Down) }
        }
        Spacer(Modifier.height(8.dp))
        val fraction = task.fraction
        if (fraction != null) {
            LinearProgressIndicator(progress = { fraction }, color = Accent, trackColor = CardColor, modifier = Modifier.fillMaxWidth().clip(CircleShape))
        } else {
            LinearProgressIndicator(color = Accent, trackColor = CardColor, modifier = Modifier.fillMaxWidth().clip(CircleShape))
        }
    }
}

@Composable
private fun MessageToast(message: String?, onShown: () -> Unit, modifier: Modifier) {
    LaunchedEffect(message) {
        if (message != null) {
            delay(3500)
            onShown()
        }
    }
    AnimatedVisibility(message != null, modifier = modifier, enter = fadeIn() + slideInVertically { -it / 2 }, exit = fadeOut()) {
        Text(
            message.orEmpty(),
            color = TextMain,
            fontSize = 15.sp,
            modifier = Modifier
                .padding(horizontal = 24.dp)
                .clip(RoundedCornerShape(14.dp))
                .background(Color(0xFF2A2F38))
                .clickable(onClick = onShown)
                .padding(horizontal = 18.dp, vertical = 12.dp),
        )
    }
}

/* ---------- Диалоги ---------- */

@Composable
private fun NameDialog(
    title: String,
    confirm: String,
    initial: String,
    rename: Boolean,
    onDismiss: () -> Unit,
    onConfirm: (String) -> Unit,
) {
    val selectStem = rename && !File(initial).extension.isEmpty()
    // При переименовании выделено имя без расширения — его обычно и меняют.
    val stemEnd = if (selectStem) initial.lastIndexOf('.').takeIf { it > 0 } ?: initial.length else initial.length
    var value by remember { mutableStateOf(TextFieldValue(initial, TextRange(0, stemEnd))) }
    val error = nameError(value.text)
    // Переименование в то же имя — не ошибка, но и сохранять нечего.
    val canConfirm = error == null && (!rename || value.text != initial)
    val focus = remember { FocusRequester() }
    LaunchedEffect(Unit) { focus.requestFocus() }
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = CardRaised,
        title = { Text(title, color = TextMain) },
        text = {
            OutlinedTextField(
                value = value,
                onValueChange = { value = it },
                singleLine = true,
                isError = error != null,
                supportingText = { error?.let { Text(it) } },
                keyboardOptions = KeyboardOptions(imeAction = ImeAction.Done),
                keyboardActions = KeyboardActions(onDone = { if (canConfirm) onConfirm(value.text) }),
                modifier = Modifier.fillMaxWidth().focusRequester(focus),
            )
        },
        confirmButton = {
            TextButton(onClick = { onConfirm(value.text) }, enabled = canConfirm) {
                Text(confirm, color = Accent)
            }
        },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена", color = TextMuted) } },
    )
}

@Composable
private fun DeleteDialog(entries: List<FileEntry>, onDismiss: () -> Unit, onConfirm: () -> Unit) {
    val what = entries.singleOrNull()?.let { "«${it.name}»" + if (it.isDir) " со всем содержимым" else "" } ?: itemsCount(entries.size)
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = CardRaised,
        title = { Text("Удалить $what?", color = TextMain) },
        text = { Text("Корзины нет — восстановить файлы будет нельзя.", color = TextMuted) },
        confirmButton = { TextButton(onClick = onConfirm) { Text("Удалить", color = Down) } },
        dismissButton = { TextButton(onClick = onDismiss) { Text("Отмена", color = TextMuted) } },
    )
}

@Composable
private fun DetailsDialog(entry: FileEntry, vm: FilesViewModel, onDismiss: () -> Unit) {
    val size by produceState<Long?>(if (entry.isDir) null else entry.size, entry.path) {
        if (entry.isDir) value = vm.folderSize(entry.path)
    }
    AlertDialog(
        onDismissRequest = onDismiss,
        containerColor = CardRaised,
        icon = { FileIcon(entry, 72.dp) },
        title = { Text(entry.name, color = TextMain, textAlign = TextAlign.Center) },
        text = {
            SelectionContainer {
                Column(verticalArrangement = Arrangement.spacedBy(10.dp)) {
                    DetailRow("Тип", if (entry.isDir) "Папка" else mimeOf(entry).takeIf { it != "*/*" } ?: entry.extension.uppercase().ifEmpty { "Файл" })
                    DetailRow("Размер", size?.let { "${formatSize(it)} (${"%,d".format(RU, it)} байт)" } ?: "Считаем…")
                    if (entry.isDir) DetailRow("Внутри", itemsCount(entry.childCount))
                    DetailRow("Изменён", formatDate(entry.modified))
                    DetailRow("Путь", entry.path)
                }
            }
        },
        confirmButton = { TextButton(onClick = onDismiss) { Text("Закрыть", color = Accent) } },
    )
}

@Composable
private fun DetailRow(label: String, value: String) {
    Row {
        Text(label, color = TextMuted, fontSize = 14.sp, modifier = Modifier.width(88.dp))
        Text(value, color = TextMain, fontSize = 14.sp)
    }
}

/* ---------- Нет доступа ---------- */

@Composable
private fun AccessScreen(onRequest: () -> Unit) {
    Column(
        Modifier.fillMaxSize().background(Background).safeDrawingPadding().padding(32.dp),
        horizontalAlignment = Alignment.CenterHorizontally,
        verticalArrangement = Arrangement.Center,
    ) {
        Icon(Glyphs.Folder, null, tint = Accent, modifier = Modifier.size(112.dp))
        Spacer(Modifier.height(20.dp))
        Text("Файлы", color = TextMain, fontSize = 30.sp)
        Spacer(Modifier.height(8.dp))
        Text(
            "Чтобы показывать, копировать и удалять файлы, приложению нужен доступ ко всем файлам. " +
                "В настройках включите «Разрешить доступ к управлению всеми файлами» и вернитесь назад.",
            color = TextMuted,
            fontSize = 16.sp,
            textAlign = TextAlign.Center,
            modifier = Modifier.width(480.dp),
        )
        Spacer(Modifier.height(24.dp))
        Text(
            "Разрешить",
            color = Background,
            fontSize = 17.sp,
            fontWeight = FontWeight.Medium,
            modifier = Modifier.clip(RoundedCornerShape(16.dp)).background(Accent).clickable(onClick = onRequest).padding(horizontal = 28.dp, vertical = 14.dp),
        )
    }
}
