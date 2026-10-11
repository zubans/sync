package com.example.contactsync.files

import android.app.Application
import android.content.Context
import android.os.Build
import android.os.Environment
import android.os.StatFs
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.viewModelScope
import kotlinx.coroutines.CancellationException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.flow.update
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.io.File

enum class PlaceKind { STORAGE, SD_CARD, DOWNLOADS, CAMERA, PICTURES, DOCUMENTS, MUSIC, MOVIES, FAVORITE }

/** Пункт боковой панели. У хранилищ ([PlaceKind.STORAGE], [PlaceKind.SD_CARD]) есть объём. */
data class Place(
    val label: String,
    val path: String,
    val kind: PlaceKind,
    val totalBytes: Long = 0,
    val freeBytes: Long = 0,
)

/** Что лежит в буфере «Копировать / Вырезать». */
data class FileClipboard(val paths: List<String>, val cut: Boolean)

/** Идущая операция с файлами: копирование, перенос, удаление. */
data class FileTask(val title: String, val doneBytes: Long = 0, val totalBytes: Long = 0) {
    val fraction: Float? get() = if (totalBytes > 0) (doneBytes.toFloat() / totalBytes).coerceIn(0f, 1f) else null
}

data class FilesState(
    val hasAccess: Boolean = false,
    val places: List<Place> = emptyList(),
    val dir: String = "",
    /** Содержимое [dir] с учётом сортировки и скрытых файлов. */
    val entries: List<FileEntry> = emptyList(),
    val loading: Boolean = false,
    val sort: SortOrder = SortOrder(),
    val grid: Boolean = false,
    val showHidden: Boolean = false,
    val query: String = "",
    /** Результаты поиска по всему дереву [dir]; `null` — поиска нет. */
    val searchResults: List<FileEntry>? = null,
    val searching: Boolean = false,
    val selection: Set<String> = emptySet(),
    val clipboard: FileClipboard? = null,
    val task: FileTask? = null,
    val message: String? = null,
) {
    val shown: List<FileEntry> get() = searchResults ?: entries
    val favorites: Set<String> get() = places.filter { it.kind == PlaceKind.FAVORITE }.mapTo(mutableSetOf()) { it.path }
}

/**
 * Файловый менеджер: обход папок, буфер обмена, операции в фоне с прогрессом и отменой.
 * Работает с `java.io.File` напрямую — для этого нужен доступ ко всем файлам (Android 11+)
 * или разрешение на память (Android 8–10).
 */
class FilesViewModel(application: Application) : AndroidViewModel(application) {

    private val prefs = application.getSharedPreferences("files", Context.MODE_PRIVATE)
    private val storageRoot: File = Environment.getExternalStorageDirectory()

    private val _state = MutableStateFlow(
        FilesState(
            dir = prefs.getString(KEY_DIR, null)?.takeIf { File(it).isDirectory } ?: storageRoot.path,
            sort = SortOrder(
                field = prefs.getString(KEY_SORT, null)?.let { runCatching { SortField.valueOf(it) }.getOrNull() } ?: SortField.NAME,
                descending = prefs.getBoolean(KEY_DESC, false),
            ),
            grid = prefs.getBoolean(KEY_GRID, false),
            showHidden = prefs.getBoolean(KEY_HIDDEN, false),
        ),
    )
    val state: StateFlow<FilesState> = _state.asStateFlow()

    /** Позиция прокрутки по папкам: при возврате на уровень вверх список не прыгает в начало. */
    val scrollPositions = mutableMapOf<String, Int>()

    private var raw: List<FileEntry> = emptyList()
    private var loadJob: Job? = null
    private var searchJob: Job? = null
    private var taskJob: Job? = null

    fun onResume(hasAccess: Boolean) {
        _state.update { it.copy(hasAccess = hasAccess) }
        if (!hasAccess) return
        refreshPlaces()
        reload()
    }

    fun open(path: String) {
        if (path == _state.value.dir && _state.value.searchResults == null) return
        searchJob?.cancel()
        _state.update { it.copy(dir = path, selection = emptySet(), query = "", searchResults = null, searching = false) }
        prefs.edit().putString(KEY_DIR, path).apply()
        reload()
    }

    /** На уровень вверх. `false` — выше корня хранилища не идём, пусть «Назад» закроет экран. */
    fun up(): Boolean {
        val dir = File(_state.value.dir)
        val parent = dir.parentFile ?: return false
        if (dir.path == storageRoot.path || placeRoots().any { it.path == dir.path }) return false
        open(parent.path)
        return true
    }

    fun reload() {
        val dir = File(_state.value.dir)
        loadJob?.cancel()
        loadJob = viewModelScope.launch {
            _state.update { it.copy(loading = true) }
            val list = withContext(Dispatchers.IO) { dir.listFiles()?.map { it.toEntry() } }
            raw = list.orEmpty()
            _state.update { s ->
                s.copy(
                    loading = false,
                    entries = present(raw, s),
                    selection = s.selection.filterTo(mutableSetOf()) { p -> raw.any { it.path == p } },
                    message = if (list == null) "Нет доступа к папке" else s.message,
                )
            }
        }
    }

    fun setSort(field: SortField) {
        val current = _state.value.sort
        val sort = if (current.field == field) current.copy(descending = !current.descending) else SortOrder(field)
        prefs.edit().putString(KEY_SORT, sort.field.name).putBoolean(KEY_DESC, sort.descending).apply()
        _state.update { it.copy(sort = sort).let { s -> s.copy(entries = present(raw, s), searchResults = s.searchResults?.sortedBy(sort)) } }
    }

    fun toggleGrid() {
        val grid = !_state.value.grid
        prefs.edit().putBoolean(KEY_GRID, grid).apply()
        _state.update { it.copy(grid = grid) }
    }

    fun toggleHidden() {
        val hidden = !_state.value.showHidden
        prefs.edit().putBoolean(KEY_HIDDEN, hidden).apply()
        _state.update { it.copy(showHidden = hidden).let { s -> s.copy(entries = present(raw, s)) } }
        if (_state.value.query.isNotBlank()) search(_state.value.query)
    }

    /** Поиск по имени во всех вложенных папках текущей; запускается с небольшой задержкой после ввода. */
    fun search(query: String) {
        searchJob?.cancel()
        _state.update { it.copy(query = query, selection = emptySet()) }
        if (query.isBlank()) {
            _state.update { it.copy(searchResults = null, searching = false) }
            return
        }
        searchJob = viewModelScope.launch {
            delay(SEARCH_DEBOUNCE_MS)
            _state.update { it.copy(searching = true) }
            val s = _state.value
            val found = withContext(Dispatchers.IO) {
                FileTransfer.search(File(s.dir), query, s.showHidden, SEARCH_LIMIT) { isActive }.map { it.toEntry() }
            }
            _state.update { it.copy(searchResults = found.sortedBy(it.sort), searching = false) }
        }
    }

    fun toggleSelection(path: String) = _state.update {
        it.copy(selection = if (path in it.selection) it.selection - path else it.selection + path)
    }

    fun selectAll() = _state.update { it.copy(selection = it.shown.mapTo(mutableSetOf()) { e -> e.path }) }

    fun clearSelection() = _state.update { it.copy(selection = emptySet()) }

    fun copy(paths: Collection<String>, cut: Boolean) {
        if (paths.isEmpty()) return
        // Сообщения нет: карточка буфера с кнопкой «Вставить сюда» видна сразу.
        _state.update { it.copy(clipboard = FileClipboard(paths.toList(), cut), selection = emptySet()) }
    }

    fun clearClipboard() = _state.update { it.copy(clipboard = null) }

    fun paste() {
        val clip = _state.value.clipboard ?: return
        val dest = File(_state.value.dir)
        runTask(if (clip.cut) "Перенос" else "Копирование", clip.paths.map(::File)) { file, onBytes, active ->
            if (clip.cut) FileTransfer.move(file, dest, onBytes, active) else FileTransfer.copy(file, dest, onBytes, active)
        }
        if (clip.cut) _state.update { it.copy(clipboard = null) }
    }

    fun delete(paths: Collection<String>) {
        _state.update { it.copy(selection = emptySet()) }
        runTask("Удаление", paths.map(::File), countBytes = false) { file, _, _ -> FileTransfer.delete(file) }
    }

    fun rename(path: String, newName: String) = io("Переименовано") {
        FileTransfer.rename(File(path), newName.trim())
        _state.update { it.copy(selection = emptySet()) }
    }

    fun createFolder(name: String) = io("Папка создана") { FileTransfer.createFolder(File(_state.value.dir), name.trim()) }

    fun toggleFavorite(path: String) {
        val favorites = prefs.getStringSet(KEY_FAVORITES, emptySet()).orEmpty().toMutableSet()
        if (!favorites.remove(path)) favorites += path
        prefs.edit().putStringSet(KEY_FAVORITES, favorites).apply()
        refreshPlaces()
    }

    fun cancelTask() {
        taskJob?.cancel()
    }

    fun consumeMessage() = _state.update { it.copy(message = null) }

    fun showMessage(text: String) = _state.update { it.copy(message = text) }

    /** Размер папки — обход всего дерева, поэтому только по запросу (в «Свойствах»). */
    suspend fun folderSize(path: String): Long = withContext(Dispatchers.IO) { FileTransfer.totalSize(File(path)) { isActive } }

    private fun present(list: List<FileEntry>, s: FilesState) =
        list.filter { s.showHidden || !it.hidden }.sortedBy(s.sort)

    private fun io(done: String, block: () -> Unit) = viewModelScope.launch {
        val error = withContext(Dispatchers.IO) { runCatching(block).exceptionOrNull() }
        _state.update { it.copy(message = error?.message ?: done) }
        reload()
        refreshPlaces()
    }

    /**
     * Операция над набором файлов в фоне. Прогресс считается в байтах (для удаления — в штуках),
     * отмена срабатывает между блоками копирования; уже обработанные файлы остаются как есть.
     */
    private fun runTask(
        title: String,
        files: List<File>,
        countBytes: Boolean = true,
        action: (File, (Long) -> Unit, () -> Boolean) -> Unit,
    ) {
        if (taskJob?.isActive == true) {
            _state.update { it.copy(message = "Дождитесь окончания: ${it.task?.title?.lowercase()}") }
            return
        }
        taskJob = viewModelScope.launch {
            _state.update { it.copy(task = FileTask(title)) }
            var failed: String? = null
            try {
                withContext(Dispatchers.IO) {
                    val total = if (countBytes) files.sumOf { FileTransfer.totalSize(it) { isActive } } else files.size.toLong()
                    var done = 0L
                    var reported = 0L
                    val onBytes: (Long) -> Unit = { n ->
                        done += n
                        // Не чаще раза на 1 % — иначе Compose перерисовывает экран на каждый блок.
                        if (done - reported > total / 100) {
                            reported = done
                            _state.update { it.copy(task = FileTask(title, done, total)) }
                        }
                    }
                    _state.update { it.copy(task = FileTask(title, 0, total)) }
                    for (file in files) {
                        if (!isActive) break
                        try {
                            action(file, onBytes) { isActive }
                        } catch (e: Exception) {
                            if (!isActive) break
                            failed = e.message ?: "Ошибка: ${file.name}"
                        }
                        if (!countBytes) onBytes(1)
                    }
                }
            } catch (e: CancellationException) {
                failed = "Отменено"
            } finally {
                val result = failed ?: "$title: готово, ${itemsCount(files.size)}"
                _state.update { it.copy(task = null, message = result) }
                reload()
                refreshPlaces()
            }
        }
    }

    private fun placeRoots(): List<Place> = _state.value.places.filter { it.kind == PlaceKind.STORAGE || it.kind == PlaceKind.SD_CARD }

    private fun refreshPlaces() = viewModelScope.launch {
        val places = withContext(Dispatchers.IO) { buildPlaces(getApplication()) }
        _state.update { it.copy(places = places) }
    }

    private fun buildPlaces(context: Context): List<Place> {
        val places = mutableListOf(storage("Внутренняя память", storageRoot, PlaceKind.STORAGE))
        // Корни SD-карт и флешек: у getExternalFilesDirs путь вида /storage/XXXX-XXXX/Android/data/<пакет>/files.
        context.getExternalFilesDirs(null).drop(1).filterNotNull().forEach { appDir ->
            val root = appDir.path.substringBefore("/Android/data/").let(::File)
            if (root.isDirectory) places += storage("SD-карта", root, PlaceKind.SD_CARD)
        }
        listOf(
            Triple("Загрузки", Environment.DIRECTORY_DOWNLOADS, PlaceKind.DOWNLOADS),
            Triple("Камера", Environment.DIRECTORY_DCIM, PlaceKind.CAMERA),
            Triple("Изображения", Environment.DIRECTORY_PICTURES, PlaceKind.PICTURES),
            Triple("Документы", Environment.DIRECTORY_DOCUMENTS, PlaceKind.DOCUMENTS),
            Triple("Музыка", Environment.DIRECTORY_MUSIC, PlaceKind.MUSIC),
            Triple("Видео", Environment.DIRECTORY_MOVIES, PlaceKind.MOVIES),
        ).forEach { (label, name, kind) ->
            val dir = File(storageRoot, name)
            if (dir.isDirectory) places += Place(label, dir.path, kind)
        }
        prefs.getStringSet(KEY_FAVORITES, emptySet()).orEmpty()
            .map(::File).filter { it.isDirectory }.sortedWith { a, b -> naturalCompare(a.name, b.name) }
            .forEach { places += Place(it.name, it.path, PlaceKind.FAVORITE) }
        return places
    }

    private fun storage(label: String, root: File, kind: PlaceKind): Place {
        val stat = runCatching { StatFs(root.path) }.getOrNull()
        return Place(label, root.path, kind, stat?.totalBytes ?: 0, stat?.availableBytes ?: 0)
    }

    private companion object {
        const val KEY_DIR = "dir"
        const val KEY_SORT = "sort"
        const val KEY_DESC = "desc"
        const val KEY_GRID = "grid"
        const val KEY_HIDDEN = "hidden"
        const val KEY_FAVORITES = "favorites"
        const val SEARCH_DEBOUNCE_MS = 300L
        const val SEARCH_LIMIT = 500
    }
}

/** Есть ли у приложения доступ к файлам общей памяти. */
fun hasStorageAccess(context: Context): Boolean =
    if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
        Environment.isExternalStorageManager()
    } else {
        context.checkSelfPermission(android.Manifest.permission.WRITE_EXTERNAL_STORAGE) == android.content.pm.PackageManager.PERMISSION_GRANTED
    }
