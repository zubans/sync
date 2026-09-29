package com.example.contactsync.sync

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import java.time.Duration
import java.time.ZoneId
import java.time.ZonedDateTime

class NightlyBackupTest {

    private val zone = ZoneId.of("Europe/Moscow")
    private fun at(day: Int, hour: Int, minute: Int = 0) = ZonedDateTime.of(2026, 9, day, hour, minute, 0, 0, zone)

    @Test
    fun `next check is at 2am today or tomorrow`() {
        assertEquals(Duration.ofHours(1).plusMinutes(30), NightlyBackup.delayUntilNextStart(at(29, 0, 30)))
        assertEquals(Duration.ofHours(12), NightlyBackup.delayUntilNextStart(at(29, 14)))
        assertEquals(Duration.ofDays(1), NightlyBackup.delayUntilNextStart(at(29, 2)))
    }

    @Test
    fun `backup starts only at night`() {
        assertTrue(NightlyBackup.isNight(at(29, 2)))
        assertTrue(NightlyBackup.isNight(at(29, 5, 59)))
        assertFalse(NightlyBackup.isNight(at(29, 6)))
        assertFalse(NightlyBackup.isNight(at(29, 23)))
    }

    @Test
    fun `backup is due once a week without drifting`() {
        val lastRun = at(22, 2, 30).toInstant().toEpochMilli()

        assertTrue(NightlyBackup.isDue(0, at(29, 2).toInstant().toEpochMilli()))
        assertFalse(NightlyBackup.isDue(lastRun, at(28, 2, 5).toInstant().toEpochMilli()))
        // Ровно через неделю, чуть раньше вчерашнего времени запуска — уже пора.
        assertTrue(NightlyBackup.isDue(lastRun, at(29, 2, 5).toInstant().toEpochMilli()))
    }
}
