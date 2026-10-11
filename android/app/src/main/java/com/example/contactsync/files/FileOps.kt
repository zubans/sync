package com.example.contactsync.files

import java.io.File
import java.io.IOException
import java.util.Locale

/** Вид файла: по нему выбирается значок, цвет и превью. Определяется по расширению. */
enum class FileKind { FOLDER, IMAGE, VIDEO, AUDIO, PDF, DOCUMENT, SHEET, SLIDES, TEXT, CODE, ARCHIVE, APK, OTHER }

enum class SortField(val title: String) { NAME("Имя"), DATE("Дата"), SIZE("Размер"), TYPE("Тип") }

data class SortOrder(val field: SortField = SortField.NAME, val descending: Boolean = false)

/** Строка списка файлов. Снимок на момент чтения папки, файловую систему не трогает. */
data class FileEntry(
    val path: String,
    val name: String,
    val isDir: Boolean,
    /** Размер файла в байтах; у папки — 0, её размер считается отдельно по запросу. */
    val size: Long,
    /** Время изменения, мс от эпохи. */
    val modified: Long,
    /** Число элементов внутри папки; у файла — 0. */
    val childCount: Int = 0,
) {
    val extension: String get() = if (isDir) "" else name.substringAfterLast('.', "").lowercase(Locale.ROOT)
    val kind: FileKind get() = kindOf(name, isDir)
    val hidden: Boolean get() = name.startsWith(".")
}

private val KINDS: Map<String, FileKind> = buildMap {
    fun put(kind: FileKind, vararg ext: String) = ext.forEach { put(it, kind) }
    put(FileKind.IMAGE, "jpg", "jpeg", "png", "gif", "webp", "bmp", "heic", "heif", "svg", "avif")
    put(FileKind.VIDEO, "mp4", "mkv", "webm", "avi", "mov", "3gp", "m4v", "ts", "wmv")
    put(FileKind.AUDIO, "mp3", "m4a", "aac", "ogg", "opus", "wav", "flac", "amr", "mid", "wma")
    put(FileKind.PDF, "pdf")
    put(FileKind.DOCUMENT, "doc", "docx", "odt", "rtf", "epub", "fb2", "djvu")
    put(FileKind.SHEET, "xls", "xlsx", "ods", "csv", "tsv")
    put(FileKind.SLIDES, "ppt", "pptx", "odp", "key")
    put(FileKind.TEXT, "txt", "md", "log", "ini", "conf", "cfg", "properties")
    put(FileKind.CODE, "kt", "java", "py", "js", "ts", "php", "go", "sh", "json", "xml", "yaml", "yml", "html", "css", "sql", "c", "cpp", "h", "rs", "swift")
    put(FileKind.ARCHIVE, "zip", "rar", "7z", "tar", "gz", "tgz", "bz2", "xz")
    put(FileKind.APK, "apk", "apks", "xapk")
}

fun kindOf(name: String, isDir: Boolean): FileKind =
    if (isDir) FileKind.FOLDER else KINDS[name.substringAfterLast('.', "").lowercase(Locale.ROOT)] ?: FileKind.OTHER

/**
 * Сравнение имён «как у человека»: без учёта регистра, а числа внутри имени — по значению,
 * чтобы «IMG_2» шёл раньше «IMG_10».
 */
fun naturalCompare(a: String, b: String): Int {
    var i = 0
    var j = 0
    while (i < a.length && j < b.length) {
        val ca = a[i]
        val cb = b[j]
        if (ca.isDigit() && cb.isDigit()) {
            val startA = i
            val startB = j
            while (i < a.length && a[i].isDigit()) i++
            while (j < b.length && b[j].isDigit()) j++
            val numA = a.substring(startA, i).trimStart('0')
            val numB = b.substring(startB, j).trimStart('0')
            if (numA.length != numB.length) return numA.length - numB.length
            val cmp = numA.compareTo(numB)
            if (cmp != 0) return cmp
        } else {
            val cmp = ca.lowercaseChar().compareTo(cb.lowercaseChar())
            if (cmp != 0) return cmp
            i++
            j++
        }
    }
    return (a.length - i) - (b.length - j)
}

/** Папки всегда сверху, порядок внутри групп — по выбранному полю. При равенстве — по имени. */
fun List<FileEntry>.sortedBy(order: SortOrder): List<FileEntry> {
    val byName = Comparator<FileEntry> { a, b -> naturalCompare(a.name, b.name) }
    val byField: Comparator<FileEntry> = when (order.field) {
        SortField.NAME -> byName
        SortField.DATE -> compareBy<FileEntry> { it.modified }.then(byName)
        SortField.SIZE -> compareBy<FileEntry> { if (it.isDir) it.childCount.toLong() else it.size }.then(byName)
        SortField.TYPE -> compareBy<FileEntry> { it.extension }.then(byName)
    }
    return sortedWith(compareBy<FileEntry> { !it.isDir }.then(if (order.descending) byField.reversed() else byField))
}

private val UNITS = listOf("Б", "КБ", "МБ", "ГБ", "ТБ")

/** Размер для людей: «0 Б», «512 Б», «1,5 МБ», «12 ГБ». */
fun formatSize(bytes: Long): String {
    if (bytes < 1024) return "$bytes ${UNITS[0]}"
    var value = bytes.toDouble()
    var unit = 0
    while (value >= 1024 && unit < UNITS.lastIndex) {
        value /= 1024
        unit++
    }
    val text = if (value >= 100) "%.0f".format(Locale.ROOT, value) else "%.1f".format(Locale.ROOT, value).removeSuffix(".0")
    return "${text.replace('.', ',')} ${UNITS[unit]}"
}

/** «12 элементов», «1 элемент», «3 элемента». */
fun itemsCount(n: Int): String {
    val word = when {
        n % 100 in 11..14 -> "элементов"
        n % 10 == 1 -> "элемент"
        n % 10 in 2..4 -> "элемента"
        else -> "элементов"
    }
    return "$n $word"
}

/**
 * Свободное имя при копировании в папку, где такое уже есть: «фото.jpg» → «фото (2).jpg».
 * Номер у имени, которое уже им заканчивается, увеличивается, а не наращивается: «a (2)» → «a (3)».
 */
fun uniqueName(name: String, isDir: Boolean, exists: (String) -> Boolean): String {
    if (!exists(name)) return name
    val dot = if (isDir) -1 else name.lastIndexOf('.').takeIf { it > 0 } ?: -1
    val stem = if (dot > 0) name.substring(0, dot) else name
    val ext = if (dot > 0) name.substring(dot) else ""
    val match = Regex("""^(.*) \((\d+)\)$""").find(stem)
    val base = match?.groupValues?.get(1) ?: stem
    var n = match?.groupValues?.get(2)?.toIntOrNull()?.plus(1) ?: 2
    while (exists("$base ($n)$ext")) n++
    return "$base ($n)$ext"
}

/** Текст ошибки для имени нового файла или папки, `null` — имя подходит. */
fun nameError(name: String): String? = when {
    name.isBlank() -> "Введите имя"
    name == "." || name == ".." -> "Такое имя нельзя"
    '/' in name || '\u0000' in name -> "Имя не может содержать «/»"
    name.toByteArray().size > 255 -> "Слишком длинное имя"
    else -> null
}

/**
 * Операции с файлами поверх `java.io.File`. Длинные операции принимают `onBytes` — сколько байт
 * скопировано с прошлого вызова, — и `isActive`, чтобы их можно было отменить между файлами.
 */
object FileTransfer {

    /** Копирует [src] в [destDir] под свободным именем. Возвращает созданный файл или папку. */
    fun copy(src: File, destDir: File, onBytes: (Long) -> Unit = {}, isActive: () -> Boolean = { true }): File {
        checkNotInside(src, destDir)
        val target = File(destDir, uniqueName(src.name, src.isDirectory) { File(destDir, it).exists() })
        copyTree(src, target, onBytes, isActive)
        return target
    }

    /**
     * Переносит [src] в [destDir]. В пределах одного раздела — переименованием (мгновенно),
     * между разделами (память ↔ SD-карта) — копированием и удалением оригинала.
     */
    fun move(src: File, destDir: File, onBytes: (Long) -> Unit = {}, isActive: () -> Boolean = { true }): File {
        if (src.parentFile?.canonicalPath == destDir.canonicalPath) return src
        checkNotInside(src, destDir)
        val target = File(destDir, uniqueName(src.name, src.isDirectory) { File(destDir, it).exists() })
        if (src.renameTo(target)) {
            onBytes(totalSize(target))
            return target
        }
        copyTree(src, target, onBytes, isActive)
        if (!src.deleteRecursively()) throw IOException("Скопировано, но не удалось удалить «${src.name}»")
        return target
    }

    fun delete(file: File) {
        if (!file.deleteRecursively()) throw IOException("Не удалось удалить «${file.name}»")
    }

    fun rename(file: File, newName: String): File {
        nameError(newName)?.let { throw IOException(it) }
        val target = File(file.parentFile, newName)
        if (target.exists() && !target.path.equals(file.path, ignoreCase = true)) throw IOException("«$newName» уже есть")
        if (!file.renameTo(target)) throw IOException("Не удалось переименовать «${file.name}»")
        return target
    }

    fun createFolder(parent: File, name: String): File {
        nameError(name)?.let { throw IOException(it) }
        val dir = File(parent, name)
        if (dir.exists()) throw IOException("«$name» уже есть")
        if (!dir.mkdir()) throw IOException("Не удалось создать папку")
        return dir
    }

    /** Размер файла или всего содержимого папки. Ссылки на папки не обходятся. */
    fun totalSize(file: File, isActive: () -> Boolean = { true }): Long {
        if (!file.isDirectory) return file.length()
        var sum = 0L
        file.walkTopDown().onEnter { isActive() }.forEach { if (it.isFile) sum += it.length() }
        return sum
    }

    /**
     * Поиск по имени во всём дереве [root], без учёта регистра. Скрытые папки обходятся, только
     * если [hidden]; служебная `Android/data` (чужие данные, доступа к ней всё равно нет) — никогда.
     */
    fun search(root: File, query: String, hidden: Boolean, limit: Int, isActive: () -> Boolean = { true }): List<File> {
        val needle = query.trim().lowercase()
        if (needle.isEmpty()) return emptyList()
        return root.walkTopDown()
            .onEnter { dir -> isActive() && (dir == root || ((hidden || !dir.name.startsWith(".")) && !dir.isAndroidPrivate())) }
            .filter { it != root && (hidden || !it.name.startsWith(".")) && needle in it.name.lowercase() }
            .take(limit)
            .toList()
    }

    private fun File.isAndroidPrivate() = (name == "data" || name == "obb") && parentFile?.name == "Android"

    private fun checkNotInside(src: File, destDir: File) {
        val s = src.canonicalPath
        val d = destDir.canonicalPath
        if (d == s || d.startsWith("$s/")) throw IOException("Нельзя поместить папку «${src.name}» в саму себя")
    }

    private fun copyTree(src: File, target: File, onBytes: (Long) -> Unit, isActive: () -> Boolean) {
        if (!isActive()) throw IOException("Отменено")
        if (src.isDirectory) {
            if (!target.mkdirs() && !target.isDirectory) throw IOException("Не удалось создать «${target.name}»")
            src.listFiles()?.forEach { copyTree(it, File(target, it.name), onBytes, isActive) }
            return
        }
        src.inputStream().use { input ->
            target.outputStream().use { output ->
                val buffer = ByteArray(256 * 1024)
                while (true) {
                    val read = input.read(buffer)
                    if (read < 0) break
                    if (!isActive()) {
                        output.close()
                        target.delete()
                        throw IOException("Отменено")
                    }
                    output.write(buffer, 0, read)
                    onBytes(read.toLong())
                }
            }
        }
        target.setLastModified(src.lastModified())
    }
}

fun File.toEntry(): FileEntry {
    val dir = isDirectory
    return FileEntry(
        path = path,
        name = name,
        isDir = dir,
        size = if (dir) 0 else length(),
        modified = lastModified(),
        childCount = if (dir) list()?.size ?: 0 else 0,
    )
}
