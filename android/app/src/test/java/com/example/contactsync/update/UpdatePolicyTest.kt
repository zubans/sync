package com.example.contactsync.update

import com.example.contactsync.data.ClientReleaseDto
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class UpdatePolicyTest {

    private fun version(code: Int) = ClientReleaseDto(code, "1.$code", "sha", 1, "http://server/api/app/download/$code")

    @Test
    fun `only a newer server version is offered`() {
        assertTrue(UpdatePolicy.isNewer(version(120), installedCode = 119))
        assertFalse(UpdatePolicy.isNewer(version(119), installedCode = 119))
        // Админ сделал активной прошлую версию — откатывать установленную не предлагаем.
        assertFalse(UpdatePolicy.isNewer(version(118), installedCode = 119))
        assertFalse(UpdatePolicy.isNewer(null, installedCode = 119))
    }

    @Test
    fun `each new version is notified once`() {
        assertTrue(UpdatePolicy.shouldNotify(version(120), installedCode = 119, notifiedCode = 0))
        assertFalse(UpdatePolicy.shouldNotify(version(120), installedCode = 119, notifiedCode = 120))
        assertTrue(UpdatePolicy.shouldNotify(version(121), installedCode = 119, notifiedCode = 120))
        assertFalse(UpdatePolicy.shouldNotify(version(119), installedCode = 119, notifiedCode = 0))
    }
}
