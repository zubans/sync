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

/**
 * Доставка общих контактов семьи на телефон. Семья нужна для заполнения новых устройств:
 * недостающие контакты ставятся один раз, дальше это обычные контакты владельца телефона —
 * приложение их не обновляет и не удаляет, даже если контакт убрали из семьи.
 */
data class FamilyPlan(
    val toInsert: List<ServerContact>,
    /** Уже доставленные (serverId → запись на телефоне): повторно не ставим, даже если запись удалили. */
    val delivered: Map<String, FamilyEntry>,
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
        val byFingerprint = local.groupBy { it.fingerprint }

        val toInsert = mutableListOf<ServerContact>()
        val matched = mutableMapOf<Long, String>()
        val usedContacts = mutableSetOf<Long>()
        for (contact in server) {
            if (contact.serverId in alreadyOnPhone) continue
            val twin = byFingerprint[contact.fingerprint]?.firstOrNull { it.contactId !in usedContacts }
            when {
                twin == null || twin.rawContactIds.isEmpty() -> toInsert += contact
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
        // Такой же контакт (имя + телефоны) уже есть на телефоне — второй не нужен.
        val onPhone = local.groupBy { it.fingerprint }
        val toInsert = mutableListOf<ServerContact>()
        val inserting = mutableSetOf<String>()
        val delivered = mutableMapOf<String, FamilyEntry>()

        for (contact in server) {
            val already = current[contact.serverId]
            val twin = onPhone[contact.fingerprint]?.firstOrNull()?.rawContactIds?.firstOrNull()
            when {
                already != null -> delivered[contact.serverId] = already
                twin != null -> delivered[contact.serverId] = FamilyEntry(twin, contact.updatedAt, createdByUs = false)
                inserting.add(contact.fingerprint) -> toInsert += contact
            }
        }
        // Записи о контактах, которых больше нет в семье, просто забываем — с телефона ничего не удаляем.
        return FamilyPlan(toInsert, delivered)
    }

    /**
     * Контакты для выгрузки: только с телефоном. Контакты без телефона — обычно скрытые служебные
     * записи мессенджеров (Telegram и т. п.), которых нет в телефонной книге. Доставленные из семьи —
     * обычные контакты владельца телефона и выгружаются вместе со всеми.
     *
     * @param photos SHA-256 фото по contactId
     */
    fun buildUpload(local: List<LocalContact>, mappings: Mappings, photos: Map<Long, String> = emptyMap()): List<ContactUpload> {
        return local
            .filter { c -> c.phones.any { it.isNotBlank() } }
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
