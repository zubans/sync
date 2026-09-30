package com.example.contactsync.sync

import com.example.contactsync.contacts.ExternalId
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
        ServerContact(id, name, phones.toList(), emptyList(), updatedAt = updatedAt)

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
    fun `links are mapped to all raw contacts of a contact`() {
        val mapped = SyncPlanner.applyLinks(
            local = listOf(local(1, "Иван", raw = listOf(10, 11)), local(2, "Удалён на сервере")),
            links = mapOf("lk-1" to "s1"),
        )

        assertEquals(mapOf(10L to "s1", 11L to "s1"), mapped)
    }

    @Test
    fun `contacts removed by admin are deleted with all their raw contacts`() {
        val local = listOf(local(1, "Иван", raw = listOf(10, 11)), local(2, "Мария"))

        assertEquals(listOf(10L, 11L), SyncPlanner.rawIdsToRemove(local, listOf("lk-1", "lk-unknown")))
        assertTrue(SyncPlanner.rawIdsToRemove(local, emptyList()).isEmpty())
    }

    @Test
    fun `long lookup keys are replaced by a stable hash that fits the server limit`() {
        val longKey = "0r1-" + "3F2B4A".repeat(60)
        val contact = LocalContact(1, longKey, listOf(10), "Иван", listOf("+79001234567"), emptyList())

        val upload = SyncPlanner.buildUpload(listOf(contact, local(2, "Мария", "+79000000002")), Mappings())

        assertTrue(upload[0].externalId.startsWith("sha256:"))
        assertTrue(upload[0].externalId.length <= ExternalId.MAX_LENGTH)
        assertEquals(upload[0].externalId, ExternalId.of(longKey))
        assertEquals("lk-2", upload[1].externalId)
        // Ответ сервера сопоставляется с контактом по тому же идентификатору.
        assertEquals(mapOf(10L to "s1"), SyncPlanner.applyLinks(listOf(contact), mapOf(upload[0].externalId to "s1")))
        assertEquals(listOf(10L), SyncPlanner.rawIdsToRemove(listOf(contact), listOf(upload[0].externalId)))
    }

    @Test
    fun `values over server limits are trimmed instead of failing the whole sync`() {
        val contact = LocalContact(1, "lk", listOf(10), "Я".repeat(300), List(60) { "+7900000${"%04d".format(it)}" } + "1".repeat(70), emptyList())

        val upload = SyncPlanner.buildUpload(listOf(contact), Mappings()).single()

        assertEquals(255, upload.name!!.length)
        assertEquals(50, upload.phones.size)
        assertTrue(upload.phones.all { it.length <= 64 })
    }

    @Test
    fun `contacts without phones are not uploaded`() {
        val telegramOnly = LocalContact(1, "tg", listOf(10), "Из Telegram", emptyList(), emptyList())
        val emailOnly = LocalContact(2, "mail", listOf(20), "Только email", listOf(" "), listOf("a@b.c"))
        val real = LocalContact(3, "real", listOf(30), "С телефоном", listOf("+79001234567"), emptyList(), photoKey = "5:1")

        val upload = SyncPlanner.buildUpload(listOf(telegramOnly, emailOnly, real), Mappings(), photos = mapOf(3L to "abc"))

        assertEquals(listOf("real"), upload.map { it.externalId })
        assertEquals("abc", upload.single().photo)
    }


    @Test
    fun `family contacts are delivered once and never updated or deleted`() {
        val plan = SyncPlanner.planFamily(
            server = listOf(
                server("new", "Новый", "+79000000001"),
                server("twin", "Дедушка", "+7 900 222-22-22"),
                server("delivered", "Уже был", updatedAt = "t2"),
                server("same-as-new", "новый", "8 900 000 00 01"),
            ),
            local = listOf(local(5, "Дедушка", "+79002222222")),
            // Доставленную копию пользователь удалил, а ещё один контакт убрали из семьи.
            current = mapOf(
                "delivered" to FamilyEntry(99, "t1", createdByUs = true),
                "unshared" to FamilyEntry(50, "t1", createdByUs = true),
            ),
        )

        // Новый ставится один раз (второй такой же от другого члена семьи — нет).
        assertEquals(listOf("new"), plan.toInsert.map { it.serverId })
        // Совпавший с контактом телефона не ставится; удалённый пользователем не возвращается.
        assertEquals(FamilyEntry(50, "t1", createdByUs = false), plan.delivered["twin"])
        assertEquals(FamilyEntry(99, "t1", createdByUs = true), plan.delivered["delivered"])
        // Убранный из семьи просто забыт — удалять с телефона нечего.
        assertEquals(setOf("twin", "delivered"), plan.delivered.keys)
    }

    @Test
    fun `delivered family contacts are uploaded as normal contacts`() {
        val upload = SyncPlanner.buildUpload(
            local = listOf(local(1, "Личный", "+79000000001"), local(2, "Из семьи", "+79000000002")),
            mappings = Mappings(personal = mapOf(10L to "s1"), family = mapOf("f1" to FamilyEntry(20, "t1", createdByUs = true))),
        )

        assertEquals(listOf("lk-1" to "s1", "lk-2" to null), upload.map { it.externalId to it.serverId })
    }

    @Test
    fun `restore matches a contact delivered from family instead of duplicating it`() {
        val plan = SyncPlanner.planRestore(
            server = listOf(server("s1", "Бабушка", "+79001111111")),
            local = listOf(local(1, "Бабушка", "+79001111111")),
            mappings = Mappings(family = mapOf("f1" to FamilyEntry(10, "t1", createdByUs = true))),
        )

        assertTrue(plan.toInsert.isEmpty())
        assertEquals(mapOf(10L to "s1"), plan.matched)
    }
}
