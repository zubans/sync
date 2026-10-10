package com.example.contactsync.apps

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class DownloadProgressTest {
    @Test
    fun `fraction and percent follow downloaded bytes`() {
        val progress = DownloadProgress(downloaded = 45, total = 100)
        assertEquals(0.45f, progress.fraction!!, 0.0001f)
        assertEquals(45, progress.percent)
    }

    @Test
    fun `unknown size gives indeterminate progress`() {
        val progress = DownloadProgress(downloaded = 1024, total = 0)
        assertNull(progress.fraction)
        assertEquals(-1, progress.percent)
    }

    @Test
    fun `bytes beyond declared size are capped at hundred percent`() {
        assertEquals(100, DownloadProgress(downloaded = 120, total = 100).percent)
    }
}
