package com.example.contactsync.sync

import com.example.contactsync.contacts.Fingerprint
import com.example.contactsync.contacts.LocalContact
import com.example.contactsync.data.ServerContact
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class SyncPlannerTest {

    private fun local(id: Long, name: String, vararg phones: String, raw: List<Long> = listOf(id * 10)) =
        LocalContact(id, "lk-$id", raw, name, phones.toList(), emptyList())

    private fun server(id: String, name: String, vararg phones: String, updatedAt: String = "t1") =
        ServerContact(id, name, phones.toList(), emptyList(), updatedAt)

    @Test
    fun `fingerprint ignores phone formatting and name case`() {
        assertEquals(
            Fingerprint.of("Иван  Петров", listOf("+7 900 000-00-01"), emptyList()),
            Fingerprint.of("иван петров", listOf("8 (900) 000-00-01"), emptyList()),
        )
    }

    @Test
    fun `restore inserts missing and matches existing by fingerprint`() {
        val plan = SyncPlanner.planRestore(
            server = listOf(server("s1", "Иван", "+79000000001"), server("s2", "Мария", "+79000000002")),
            local = listOf(local(1, "иван", "8 900 000 00 01", raw = listOf(10, 11))),
            mappings = Mappings(),
        )

        assertEquals(listOf("s2"), plan.toInsert.map { it.serverId })
        assertEquals(mapOf(10L to "s1", 11L to "s1"), plan.matched)
    }

    @Test
    fun `restore skips contacts already mapped to this phone`() {
        val plan = SyncPlanner.planRestore(
            server = listOf(server("s1", "Иван")),
            local = listOf(local(1, "Иван Иванович")),
            mappings = Mappings(personal = mapOf(10L to "s1")),
        )

        assertTrue(plan.toInsert.isEmpty())
        assertTrue(plan.matched.isEmpty())
    }

    @Test
    fun `restore does not duplicate a contact that is already a family copy`() {
        val plan = SyncPlanner.planRestore(
            server = listOf(server("s1", "Бабушка", "+79001111111")),
            local = listOf(local(1, "Бабушка", "+79001111111")),
            mappings = Mappings(family = mapOf("f1" to FamilyEntry(10, "t1", createdByUs = true))),
        )

        assertTrue(plan.toInsert.isEmpty())
        assertTrue(plan.matched.isEmpty())
    }

    @Test
    fun `family plan inserts, updates, adopts and deletes`() {
        val current = mapOf(
            "keep" to FamilyEntry(10, "t1", createdByUs = true),
            "changed" to FamilyEntry(20, "t1", createdByUs = true),
            "gone" to FamilyEntry(30, "t1", createdByUs = true),
            "gone-foreign" to FamilyEntry(40, "t1", createdByUs = false),
        )
        val phone = listOf(
            local(1, "Keep"), local(2, "Changed"), local(3, "Gone"), local(4, "Foreign"),
            local(5, "Дедушка", "+79002222222"),
        )

        val plan = SyncPlanner.planFamily(
            server = listOf(
                server("keep", "Keep"),
                server("changed", "Changed v2", updatedAt = "t2"),
                server("grandpa", "Дедушка", "+7 900 222-22-22"),
                server("new", "Новый"),
            ),
            local = phone,
            current = current,
        )

        assertEquals(listOf("new"), plan.toInsert.map { it.serverId })
        assertEquals(setOf(20L), plan.toUpdate.keys)
        // Созданный нами удаляется, совпавший с чужим контактом — остаётся на телефоне.
        assertEquals(listOf(30L), plan.toDelete)
        assertEquals(FamilyEntry(50, "t1", createdByUs = false), plan.keep["grandpa"])
        assertEquals("t2", plan.keep["changed"]?.updatedAt)
        assertNull(plan.keep["gone"])
    }

    @Test
    fun `family contact deleted by user on phone is inserted again`() {
        val plan = SyncPlanner.planFamily(
            server = listOf(server("f1", "Бабушка")),
            local = emptyList(),
            current = mapOf("f1" to FamilyEntry(10, "t1", createdByUs = true)),
        )

        assertEquals(listOf("f1"), plan.toInsert.map { it.serverId })
        assertTrue(plan.toDelete.isEmpty())
    }

    @Test
    fun `upload excludes family copies and passes known server ids`() {
        val upload = SyncPlanner.buildUpload(
            local = listOf(local(1, "Личный"), local(2, "Восстановленный"), local(3, "Семейный")),
            mappings = Mappings(
                personal = mapOf(20L to "s2"),
                family = mapOf("f1" to FamilyEntry(30, "t1", createdByUs = true)),
            ),
        )

        assertEquals(listOf("lk-1" to null, "lk-2" to "s2"), upload.map { it.externalId to it.serverId })
    }

    @Test
    fun `links are mapped to all raw contacts of a contact`() {
        val mapped = SyncPlanner.applyLinks(
            local = listOf(local(1, "Иван", raw = listOf(10, 11)), local(2, "Удалён на сервере")),
            links = mapOf("lk-1" to "s1"),
        )

        assertEquals(mapOf(10L to "s1", 11L to "s1"), mapped)
    }
}
