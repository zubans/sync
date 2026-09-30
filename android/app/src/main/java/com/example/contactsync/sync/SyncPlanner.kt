package com.example.contactsync.sync

import com.example.contactsync.contacts.ContactData
import com.example.contactsync.contacts.Fingerprint
import com.example.contactsync.contacts.LocalContact
import com.example.contactsync.data.ContactUpload
import com.example.contactsync.data.ServerContact

fun ServerContact.toData(photo: ByteArray? = null) = ContactData(name, phones, emails, photo)

val ServerContact.fingerprint: String get() = Fingerprint.of(name, phones, emails)

/** Что сделать, чтобы восстановить личные контакты с сервера. */
data class RestorePlan(
    /** Контактов нет на телефоне — создать. */
    val toInsert: List<ServerContact>,
    /** Уже есть на телефоне (по отпечатку) — только запомнить соответствие rawId → serverId. */
    val matched: Map<Long, String>,
)

/** Что сделать, чтобы семейные контакты на телефоне совпали с серверными. */
data class FamilyPlan(
    val toInsert: List<ServerContact>,
    /** rawId → новые данные (контакт создан нами и изменён в админке). */
    val toUpdate: Map<Long, ServerContact>,
    /** raw-контакты, созданные нами для семейных контактов, которых больше нет. */
    val toDelete: List<Long>,
    /** Соответствия, которые остаются (без учёта вставок — их id известны только после записи). */
    val keep: Map<String, FamilyEntry>,
    /** Контакты, пропавшие из семьи, которые ещё лежат на телефоне (serverId → копия). */
    val vanished: Map<String, FamilyEntry> = emptyMap(),
)

/**
 * Чистая логика сопоставления контактов телефона и сервера — без Android API, покрыта unit-тестами.
 */
object SyncPlanner {

    fun planRestore(
        server: List<ServerContact>,
        local: List<LocalContact>,
        mappings: Mappings,
    ): RestorePlan {
        val localRawIds = local.flatMap { it.rawContactIds }.toSet()
        val alreadyOnPhone = mappings.personal.filterKeys { it in localRawIds }.values.toSet()
        val familyRawIds = mappings.family.values.map { it.rawContactId }.toSet()
        val byFingerprint = local.groupBy { it.fingerprint }

        val toInsert = mutableListOf<ServerContact>()
        val matched = mutableMapOf<Long, String>()
        val usedContacts = mutableSetOf<Long>()
        for (contact in server) {
            if (contact.serverId in alreadyOnPhone) continue
            val twin = byFingerprint[contact.fingerprint]?.firstOrNull { it.contactId !in usedContacts }
            when {
                twin == null || twin.rawContactIds.isEmpty() -> toInsert += contact
                // Такой же контакт уже лежит на телефоне как семейный — второй не нужен.
                twin.rawContactIds.any { it in familyRawIds } -> usedContacts += twin.contactId
                else -> {
                    usedContacts += twin.contactId
                    twin.rawContactIds.forEach { matched[it] = contact.serverId }
                }
            }
        }
        return RestorePlan(toInsert, matched)
    }

    fun planFamily(
        server: List<ServerContact>,
        local: List<LocalContact>,
        current: Map<String, FamilyEntry>,
    ): FamilyPlan {
        val localRawIds = local.flatMap { it.rawContactIds }.toSet()
        val serverIds = server.map { it.serverId }.toSet()
        val usedRawIds = current.values.map { it.rawContactId }.toMutableSet()
        // Совпавший по отпечатку контакт телефона (в т. ч. личный) становится копией семейного,
        // чтобы не держать на телефоне дубль. Из личных он при следующей выгрузке уйдёт.
        val candidates = local
            .filter { c -> c.rawContactIds.none { it in usedRawIds } }
            .groupBy { it.fingerprint }
            .mapValues { it.value.toMutableList() }

        val toInsert = mutableListOf<ServerContact>()
        val toUpdate = mutableMapOf<Long, ServerContact>()
        val toDelete = mutableListOf<Long>()
        val keep = mutableMapOf<String, FamilyEntry>()

        val vanished = mutableMapOf<String, FamilyEntry>()
        for ((serverId, entry) in current) {
            if (serverId !in serverIds && entry.rawContactId in localRawIds) {
                vanished[serverId] = entry
                if (entry.createdByUs) toDelete += entry.rawContactId
            }
        }

        for (contact in server) {
            val entry = current[contact.serverId]?.takeIf { it.rawContactId in localRawIds }
            when {
                entry == null -> {
                    val twin = candidates[contact.fingerprint]?.removeFirstOrNull()
                    if (twin != null && twin.rawContactIds.isNotEmpty()) {
                        keep[contact.serverId] = FamilyEntry(twin.rawContactIds.first(), contact.updatedAt, createdByUs = false)
                    } else {
                        toInsert += contact
                    }
                }
                entry.updatedAt != contact.updatedAt && entry.createdByUs -> {
                    toUpdate[entry.rawContactId] = contact
                    keep[contact.serverId] = entry.copy(updatedAt = contact.updatedAt)
                }
                else -> keep[contact.serverId] = entry.copy(updatedAt = contact.updatedAt)
            }
        }
        return FamilyPlan(toInsert, toUpdate, toDelete, keep, vanished)
    }

    /**
     * Личные контакты для выгрузки: только с телефоном и кроме копий семейных контактов.
     * Контакты без телефона — обычно скрытые служебные записи мессенджеров (Telegram и т. п.),
     * которых нет в телефонной книге.
     *
     * @param photos SHA-256 фото по contactId
     */
    fun buildUpload(local: List<LocalContact>, mappings: Mappings, photos: Map<Long, String> = emptyMap()): List<ContactUpload> {
        val familyRawIds = mappings.family.values.map { it.rawContactId }.toSet()
        return local
            .filter { c -> c.phones.any { it.isNotBlank() } }
            .filter { c -> c.rawContactIds.none { it in familyRawIds } }
            .map { c ->
                // Подрезаем под ограничения сервера: один нестандартный контакт не должен
                // валить синхронизацию всех остальных (сервер отклоняет пакет целиком).
                ContactUpload(
                    externalId = c.externalId,
                    serverId = c.rawContactIds.firstNotNullOfOrNull { mappings.personal[it] },
                    name = c.name?.take(MAX_NAME),
                    phones = c.phones.filter { it.length <= MAX_PHONE }.take(MAX_VALUES),
                    emails = c.emails.filter { it.length <= MAX_EMAIL }.take(MAX_VALUES),
                    photo = photos[c.contactId],
                )
            }
    }

    /** raw-контакты телефона, которые нужно удалить: их контакты администратор удалил на сервере. */
    fun rawIdsToRemove(local: List<LocalContact>, removedExternalIds: Collection<String>): List<Long> {
        if (removedExternalIds.isEmpty()) return emptyList()
        val removed = removedExternalIds.toSet()
        return local.filter { it.externalId in removed }.flatMap { it.rawContactIds }
    }

    /**
     * Контакты, пропавшие из семьи, которые администратор вернул в личные этого пользователя
     * (у них тот же serverId): их не удаляем с телефона, а дальше считаем личными.
     *
     * @return rawId → serverId для личных соответствий
     */
    fun releasedToPersonal(vanished: Map<String, FamilyEntry>, personalServerIds: Set<String>): Map<Long, String> =
        vanished.filterKeys { it in personalServerIds }.entries.associate { (serverId, entry) -> entry.rawContactId to serverId }

    /** Обновляет соответствие rawId → serverId по ответу сервера на выгрузку. */
    fun applyLinks(
        local: List<LocalContact>,
        links: Map<String, String>,
    ): Map<Long, String> = buildMap {
        for (contact in local) {
            val serverId = links[contact.externalId] ?: continue
            contact.rawContactIds.forEach { put(it, serverId) }
        }
    }

    // Ограничения сервера (server/src/Dto/ContactInput.php).
    private const val MAX_NAME = 255
    private const val MAX_PHONE = 64
    private const val MAX_EMAIL = 255
    private const val MAX_VALUES = 50
}
