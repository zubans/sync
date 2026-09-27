package com.example.contactsync.vault

import com.example.contactsync.data.VaultChange
import com.example.contactsync.data.VaultChangeResult
import com.example.contactsync.data.VaultItemDto
import com.example.contactsync.data.VaultItemsResponse
import com.example.contactsync.data.VaultKeyDto
import com.example.contactsync.data.VaultPushResponse
import kotlinx.coroutines.runBlocking
import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Assert.assertTrue
import org.junit.Test

/** Сервер с той же логикой ревизий, что и настоящий (VaultService на Symfony). */
private class FakeServer : VaultBackend {
    var key: VaultKeyDto? = null
    var revision = 0
    val items = mutableMapOf<String, VaultItemDto>()

    override suspend fun vault() = key?.copy(revision = revision)
    override suspend fun createVault(key: VaultKeyDto) = key.also { this.key = it }
    override suspend fun rekeyVault(key: VaultKeyDto) = key.also { this.key = it }
    override suspend fun deleteVault() {
        key = null
        items.clear()
        revision = 0
    }

    override suspend fun vaultItems(since: Int) =
        VaultItemsResponse(revision, items.values.filter { it.revision > since }.sortedBy { it.revision })

    override suspend fun pushVault(changes: List<VaultChange>): VaultPushResponse {
        val results = changes.map { c ->
            val current = items[c.id]
            when {
                current == null && c.deleted -> VaultChangeResult(c.id, "ok", 0)
                current != null && c.baseRevision != current.revision -> VaultChangeResult(c.id, "conflict", current = current)
                else -> {
                    val item = VaultItemDto(c.id, ++revision, if (c.deleted) null else c.data, c.deleted)
                    items[c.id] = item
                    VaultChangeResult(c.id, "ok", item.revision)
                }
            }
        }
        return VaultPushResponse(revision, results)
    }
}

private class MemoryStorage : VaultStorage {
    private var snapshot = VaultSnapshot()
    override fun load() = snapshot
    override fun save(snapshot: VaultSnapshot) {
        this.snapshot = snapshot
    }
    override fun update(block: (VaultSnapshot) -> VaultSnapshot) = block(snapshot).also { snapshot = it }
    override fun clear() {
        snapshot = VaultSnapshot()
    }
}

class VaultSyncTest {

    private val server = FakeServer()
    private val master = "master-password".toCharArray()

    private fun device() = VaultRepository(MemoryStorage(), server, kdfIterations = 1000)

    private fun login(password: String, at: Long) =
        VaultEntry("Example", "anna", password, listOf("https://example.com"), createdAt = 1, modifiedAt = at, passwordChangedAt = at)

    /** Два телефона одного пользователя с общей записью. */
    private fun twoDevices(): Triple<VaultRepository, VaultRepository, String> = runBlocking {
        val a = device().apply { setUp(master) }
        val id = a.upsert(null, login("v1", 10))
        a.sync()
        val b = device().apply { unlock(master) }
        b.sync()
        Triple(a, b, id)
    }

    @Test
    fun `entry created on one phone appears on another`() = runBlocking {
        val (_, b, id) = twoDevices()

        assertEquals(listOf(id), b.records.value.map { it.id })
        assertEquals("v1", b.records.value.single().entry.password)
    }

    @Test
    fun `concurrent password changes converge and lose nothing`() = runBlocking {
        val (a, b, id) = twoDevices()
        val base = a.records.value.single().entry

        a.upsert(id, VaultLogic.changePassword(base, "fromA", 20))
        b.upsert(id, VaultLogic.changePassword(base, "fromB", 30))

        a.sync()
        val report = b.sync() // конфликт: сервер уже принял правку A
        a.sync()

        assertEquals(1, report.merged)
        for (device in listOf(a, b)) {
            val entry = device.records.value.single().entry
            assertEquals("fromB", entry.password)
            assertEquals(listOf("fromA", "v1"), entry.history.map { it.password })
            assertTrue(!device.hasPendingChanges)
        }
    }

    @Test
    fun `conflict on a locked phone waits for unlock`() = runBlocking {
        val (a, b, id) = twoDevices()
        val base = b.records.value.single().entry
        b.upsert(id, VaultLogic.changePassword(base, "fromB", 30))
        b.lock()
        a.upsert(id, VaultLogic.changePassword(base, "fromA", 20))
        a.sync()

        // Фоновая синхронизация без ключа: свести нельзя, правка остаётся в очереди.
        assertEquals(1, b.sync().pendingConflicts)

        b.unlock(master)
        b.sync()
        a.sync()
        assertEquals("fromB", a.records.value.single().entry.password)
        assertEquals(listOf("fromA", "v1"), a.records.value.single().entry.history.map { it.password })
    }

    @Test
    fun `edit on one phone wins over delete on another`() = runBlocking {
        val (a, b, id) = twoDevices()
        a.delete(id)
        b.upsert(id, VaultLogic.changePassword(b.records.value.single().entry, "kept", 30))

        b.sync()
        a.sync() // удаление A конфликтует с правкой B — запись остаётся

        assertEquals("kept", a.records.value.single().entry.password)
        b.sync()
        assertEquals("kept", b.records.value.single().entry.password)
    }

    @Test
    fun `delete propagates to other phones`() = runBlocking {
        val (a, b, id) = twoDevices()

        a.delete(id)
        a.sync()
        b.sync()

        assertTrue(b.records.value.isEmpty())
    }

    @Test
    fun `master password change applies to other phones without re-encrypting`() = runBlocking {
        val (a, b, _) = twoDevices()
        a.changeMasterPassword("new-master".toCharArray())
        b.lock()
        b.sync()

        assertThrows(WrongMasterPasswordException::class.java) { runBlocking { b.unlock(master) } }
        b.unlock("new-master".toCharArray())
        assertEquals("v1", b.records.value.single().entry.password)
    }

    @Test
    fun `server reset clears the vault on other phones`() = runBlocking {
        val (a, b, _) = twoDevices()

        a.reset()
        b.sync()

        assertEquals(VaultStatus.NOT_SET_UP, b.status.value)
        assertTrue(b.records.value.isEmpty())
    }

    @Test
    fun `saving a changed password from autofill updates the entry`() = runBlocking {
        val (a, b, _) = twoDevices()

        val plan = a.planSave("https://login.example.com", "example.com", "anna", "changed-on-site")
        a.applySave(plan)
        a.sync()
        b.sync()

        val entry = b.records.value.single().entry
        assertEquals("changed-on-site", entry.password)
        assertEquals(listOf("v1"), entry.history.map { it.password })
        assertTrue("https://login.example.com" in entry.uris)
    }
}
