package com.example.contactsync.contacts

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Test

class BirthdayTest {
    @Test
    fun keepsServerFormats() {
        assertEquals("1980-05-17", Birthday.normalize("1980-05-17"))
        assertEquals("--05-17", Birthday.normalize("--05-17"))
    }

    @Test
    fun cutsTimeAddedByOtherApps() {
        assertEquals("1980-05-17", Birthday.normalize(" 1980-05-17T00:00:00.000Z"))
    }

    @Test
    fun dropsUnknownFormats() {
        // Сервер отклонил бы пакет целиком — такие даты не выгружаем.
        assertNull(Birthday.normalize("17.05.1980"))
        assertNull(Birthday.normalize("1980-13-01"))
        assertNull(Birthday.normalize(null))
    }
}
