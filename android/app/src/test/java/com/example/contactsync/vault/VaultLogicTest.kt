package com.example.contactsync.vault

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test

class VaultLogicTest {

    private val site = "https://accounts.example.com"
    private fun entry(password: String, at: Long, username: String = "anna", uris: List<String> = listOf(site)) =
        VaultEntry("Example", username, password, uris, createdAt = 1, modifiedAt = at, passwordChangedAt = at)

    @Test
    fun `changing password keeps the old one in history`() {
        val changed = VaultLogic.changePassword(entry("old", 10), "new", 20)

        assertEquals("new", changed.password)
        assertEquals(20, changed.passwordChangedAt)
        assertEquals(listOf(PasswordHistoryEntry("old", 10)), changed.history)
    }

    @Test
    fun `same password is not a change`() {
        val original = entry("same", 10)

        assertEquals(original, VaultLogic.changePassword(original, "same", 20))
    }

    @Test
    fun `history is limited and has no duplicates`() {
        var e = entry("p0", 0)
        for (i in 1..15) e = VaultLogic.changePassword(e, "p$i", i.toLong())
        e = VaultLogic.changePassword(e, "p14", 16)

        assertEquals(VaultLogic.HISTORY_LIMIT, e.history.size)
        assertEquals("p15", e.history.first().password)
        assertFalse(e.history.any { it.password == "p14" })
    }

    @Test
    fun `conflict keeps newest password and puts the other into history`() {
        val base = entry("v1", 10)
        val phoneA = VaultLogic.changePassword(base, "fromA", 20)
        val phoneB = VaultLogic.changePassword(base, "fromB", 30).copy(notes = "note from B")

        val merged = VaultLogic.merge(phoneA, phoneB)

        assertEquals("fromB", merged.password)
        assertEquals("note from B", merged.notes)
        assertEquals(listOf("fromA", "v1"), merged.history.map { it.password })
    }

    @Test
    fun `merge is symmetric so both devices converge`() {
        val base = entry("v1", 10)
        val a = VaultLogic.changePassword(base, "fromA", 30).copy(uris = listOf(site, "androidapp://com.example"))
        val b = base.copy(title = "Renamed", modifiedAt = 40)

        assertEquals(VaultLogic.merge(a, b), VaultLogic.merge(b, a))
    }

    @Test
    fun `merge takes fields from newer edit but password from newer password change`() {
        val base = entry("v1", 10)
        val passwordChanged = VaultLogic.changePassword(base, "v2", 20)
        val renamedLater = base.copy(title = "Renamed", modifiedAt = 30)

        val merged = VaultLogic.merge(passwordChanged, renamedLater)

        assertEquals("Renamed", merged.title)
        assertEquals("v2", merged.password)
        assertEquals(listOf("v1"), merged.history.map { it.password })
    }

    @Test
    fun `edit wins over concurrent delete`() {
        val edited = entry("new", 20)

        assertEquals(edited, VaultLogic.resolve(local = null, remote = edited))
        assertEquals(edited, VaultLogic.resolve(local = edited, remote = null))
        assertEquals(null, VaultLogic.resolve(local = null, remote = null))
    }

    @Test
    fun `save creates entry for new site`() {
        val plan = VaultLogic.planSave(emptyList(), "https://shop.example.org", "shop.example.org", "anna", "pwd", 5)

        assertTrue(plan is SavePlan.Create)
        assertEquals(listOf("https://shop.example.org"), (plan as SavePlan.Create).entry.uris)
    }

    @Test
    fun `save with same username updates password and keeps history`() {
        val records = listOf(VaultRecord("1", entry("old", 10)), VaultRecord("2", entry("other", 10, username = "boris")))

        val plan = VaultLogic.planSave(records, "https://example.com/login", "example.com", "Anna", "new", 20)

        plan as SavePlan.Update
        assertEquals("1", plan.id)
        assertEquals("new", plan.after.password)
        assertEquals(listOf("old"), plan.after.history.map { it.password })
    }

    @Test
    fun `save of unchanged password is a no-op`() {
        val plan = VaultLogic.planSave(listOf(VaultRecord("1", entry("pwd", 10))), site, "x", "anna", "pwd", 20)

        assertEquals(SavePlan.Unchanged("1"), plan)
    }

    @Test
    fun `password change form without username updates the only entry`() {
        val plan = VaultLogic.planSave(listOf(VaultRecord("1", entry("old", 10))), site, "x", "", "new", 20)

        assertEquals("new", (plan as SavePlan.Update).after.password)
    }

    @Test
    fun `saving from an app adds the app to entry uris`() {
        val records = listOf(VaultRecord("1", entry("pwd", 10, uris = listOf("androidapp://com.example.app"))))

        val plan = VaultLogic.planSave(records, "androidapp://com.example.app", "x", "anna", "pwd2", 20)

        assertEquals(listOf("androidapp://com.example.app"), (plan as SavePlan.Update).after.uris)
    }

    @Test
    fun `domain matching uses registrable domain`() {
        val e = entry("p", 1, uris = listOf("https://www.example.com"))

        assertTrue(VaultLogic.matches(e, "https://accounts.example.com"))
        assertTrue(VaultLogic.matches(e, "example.com"))
        assertFalse(VaultLogic.matches(e, "https://example.com.evil.net"))
        assertFalse(VaultLogic.matches(e, "androidapp://com.example"))
        assertEquals("bank.co.uk", VaultLogic.baseDomain("login.bank.co.uk"))
        assertFalse(VaultLogic.matches(entry("p", 1, uris = listOf("https://bank.co.uk")), "https://other.co.uk"))
    }
}
