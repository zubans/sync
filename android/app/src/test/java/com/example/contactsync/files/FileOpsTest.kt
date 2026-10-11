package com.example.contactsync.files

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Rule
import org.junit.Test
import org.junit.rules.TemporaryFolder
import java.io.File
import java.io.IOException

class FileOpsTest {

    @get:Rule
    val tmp = TemporaryFolder()

    private fun entry(name: String, isDir: Boolean = false, size: Long = 0, modified: Long = 0) =
        FileEntry("/x/$name", name, isDir, size, modified)

    @Test
    fun `kind is taken from extension ignoring case`() {
        assertEquals(FileKind.IMAGE, kindOf("Фото.JPG", false))
        assertEquals(FileKind.APK, kindOf("sync.apk", false))
        assertEquals(FileKind.FOLDER, kindOf("Music.mp3", true))
        assertEquals(FileKind.OTHER, kindOf("README", false))
    }

    @Test
    fun `numbers in names are compared by value`() {
        val names = listOf("IMG_10.jpg", "img_2.jpg", "IMG_1.jpg", "Альбом")
        assertEquals(listOf("IMG_1.jpg", "img_2.jpg", "IMG_10.jpg", "Альбом"), names.sortedWith(::naturalCompare))
    }

    @Test
    fun `folders stay on top in any order`() {
        val list = listOf(entry("b.txt", size = 5), entry("Загрузки", isDir = true), entry("a.txt", size = 50))
        assertEquals(listOf("Загрузки", "a.txt", "b.txt"), list.sortedBy(SortOrder()).map { it.name })
        assertEquals(
            listOf("Загрузки", "a.txt", "b.txt"),
            list.sortedBy(SortOrder(SortField.SIZE, descending = true)).map { it.name },
        )
    }

    @Test
    fun `sizes are human readable`() {
        assertEquals("0 Б", formatSize(0))
        assertEquals("1023 Б", formatSize(1023))
        assertEquals("1,5 КБ", formatSize(1536))
        assertEquals("2 МБ", formatSize(2L * 1024 * 1024))
        assertEquals("120 ГБ", formatSize(120L * 1024 * 1024 * 1024))
    }

    @Test
    fun `items count agrees with number`() {
        assertEquals("1 элемент", itemsCount(1))
        assertEquals("3 элемента", itemsCount(3))
        assertEquals("11 элементов", itemsCount(11))
        assertEquals("21 элемент", itemsCount(21))
    }

    @Test
    fun `unique name increments existing number`() {
        val taken = setOf("фото.jpg", "фото (2).jpg", "Папка")
        assertEquals("новое.jpg", uniqueName("новое.jpg", false) { it in taken })
        assertEquals("фото (3).jpg", uniqueName("фото.jpg", false) { it in taken })
        assertEquals("фото (3).jpg", uniqueName("фото (2).jpg", false) { it in taken })
        assertEquals("Папка (2)", uniqueName("Папка", true) { it in taken })
        assertEquals(".bashrc (2)", uniqueName(".bashrc", false) { it == ".bashrc" })
    }

    @Test
    fun `bad names are rejected`() {
        assertNull(nameError("Отчёт.pdf"))
        assertTrue(nameError(" ") != null)
        assertTrue(nameError("a/b") != null)
        assertTrue(nameError("..") != null)
    }

    @Test
    fun `copy keeps original and renames on conflict`() {
        val src = tmp.newFolder("src")
        File(src, "a.txt").writeText("один")
        File(src, "sub").mkdir()
        File(src, "sub/b.txt").writeText("два")
        val dest = tmp.newFolder("dest")
        File(dest, "src").mkdir()

        var bytes = 0L
        val copied = FileTransfer.copy(src, dest, onBytes = { bytes += it })

        assertEquals("src (2)", copied.name)
        assertEquals("два", File(copied, "sub/b.txt").readText())
        assertTrue(File(src, "a.txt").exists())
        assertEquals(FileTransfer.totalSize(src), bytes)
    }

    @Test(expected = IOException::class)
    fun `folder cannot be copied into itself`() {
        val src = tmp.newFolder("src")
        FileTransfer.copy(src, File(src, "inner").apply { mkdir() })
    }

    @Test
    fun `move removes source`() {
        val file = tmp.newFile("a.txt").apply { writeText("x") }
        val dest = tmp.newFolder("dest")

        val moved = FileTransfer.move(file, dest)

        assertFalse(file.exists())
        assertEquals("x", moved.readText())
    }

    @Test
    fun `cancelled copy leaves no partial file`() {
        val file = tmp.newFile("big.bin").apply { writeBytes(ByteArray(1024 * 1024)) }
        val dest = tmp.newFolder("dest")
        var active = true

        try {
            FileTransfer.copy(file, dest, onBytes = { active = false }, isActive = { active })
        } catch (_: IOException) {
        }

        assertEquals(0, dest.list()!!.size)
    }

    @Test
    fun `search skips hidden folders unless asked`() {
        val root = tmp.newFolder("root")
        File(root, ".cache").mkdir()
        File(root, ".cache/отчёт.txt").writeText("")
        File(root, "Документы").mkdir()
        File(root, "Документы/Отчёт за год.pdf").writeText("")

        assertEquals(listOf("Отчёт за год.pdf"), FileTransfer.search(root, "ОТЧЁТ", hidden = false, limit = 10).map { it.name })
        assertEquals(2, FileTransfer.search(root, "отчёт", hidden = true, limit = 10).size)
    }

    @Test
    fun `rename refuses existing name`() {
        val dir = tmp.newFolder("d")
        val a = File(dir, "a.txt").apply { writeText("") }
        File(dir, "b.txt").writeText("")

        val error = runCatching { FileTransfer.rename(a, "b.txt") }.exceptionOrNull()

        assertTrue(error is IOException)
        assertEquals("c.txt", FileTransfer.rename(a, "c.txt").name)
    }
}
