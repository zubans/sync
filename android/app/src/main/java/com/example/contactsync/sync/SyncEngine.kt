package com.example.contactsync.sync

import android.Manifest
import android.content.Context
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.ContextCompat
import com.example.contactsync.contacts.ContactData
import com.example.contactsync.contacts.GoogleAccounts
import com.example.contactsync.contacts.LocalContact
import com.example.contactsync.contacts.PhoneContacts
import com.example.contactsync.contacts.PhotoHashes
import com.example.contactsync.data.Api
import com.example.contactsync.data.DeviceDto
import com.example.contactsync.data.ServerUpdate
import com.example.contactsync.data.Session
import com.example.contactsync.data.SyncRequest
import com.example.contactsync.data.UnauthorizedException
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import java.io.File

data class SyncReport(
    val uploaded: Int,
    val created: Int,
    val updated: Int,
    val deleted: Int,
    val restored: Int = 0,
    /** Удалено с телефона, потому что администратор удалил на сервере. */
    val removedByAdmin: Int = 0,
    /** Переписано на телефоне по правкам с сервера. */
    val serverUpdated: Int = 0,
    val familyAdded: Int = 0,
    val googleAccounts: List<String> = emptyList(),
) {
    fun summary(): String = buildList {
        if (restored > 0) add("восстановлено $restored")
        add("выгружено $uploaded (новых $created, изменено $updated, удалено $deleted)")
        if (removedByAdmin > 0) add("удалено администратором $removedByAdmin")
        if (serverUpdated > 0) add("изменено на сервере $serverUpdated")
        if (familyAdded > 0) add("добавлено из семьи $familyAdded")
    }.joinToString("; ").replaceFirstChar { it.uppercase() }
}

/**
 * Сценарии синхронизации. Все запуски (кнопка, фон, вход) идут через один мьютекс,
 * чтобы не править телефонную книгу и файл соответствий параллельно.
 */
class SyncEngine(
    private val context: Context,
    private val session: Session,
    private val api: Api,
) {
    private val phone = PhoneContacts(context.contentResolver)
    private val store = MappingStore(context)
    private val photoHashes = PhotoHashes(phone, File(context.filesDir, "photo-hashes.json"))

    fun hasPermissions(): Boolean = REQUIRED_PERMISSIONS.all {
        ContextCompat.checkSelfPermission(context, it) == PackageManager.PERMISSION_GRANTED
    }

    /**
     * После входа: вернуть на телефон личные контакты с сервера, положить семейные,
     * затем выгрузить телефонную книгу.
     */
    suspend fun restoreAndSync(): SyncReport = lock.withLock {
        withContext(Dispatchers.IO) {
            val restored = restorePersonal()
            val report = upload()
            val family = pullFamily()
            finish(family.copy(uploaded = report.uploaded, created = report.created, updated = report.updated, deleted = report.deleted, removedByAdmin = report.removedByAdmin, restored = restored + report.restored, serverUpdated = report.serverUpdated, googleAccounts = report.googleAccounts))
        }
    }

    /** Обычная синхронизация: выгрузить изменения телефона и подтянуть семейные контакты. */
    suspend fun sync(): SyncReport = lock.withLock {
        withContext(Dispatchers.IO) {
            val report = upload()
            val family = pullFamily()
            finish(family.copy(uploaded = report.uploaded, created = report.created, updated = report.updated, deleted = report.deleted, removedByAdmin = report.removedByAdmin, restored = report.restored, serverUpdated = report.serverUpdated, googleAccounts = report.googleAccounts))
        }
    }

    fun clearLocalState() {
        store.clear()
    }

    private suspend fun restorePersonal(): Int {
        val server = api.personalContacts()
        val mappings = store.load()
        val plan = SyncPlanner.planRestore(server, phone.readAll(), mappings)

        val personal = mappings.personal.toMutableMap()
        personal += plan.matched
        for (contact in plan.toInsert) {
            personal[phone.insert(contact.toData(downloadPhoto(contact.photo)))] = contact.serverId
        }
        store.save(mappings.copy(personal = personal))
        return plan.toInsert.size
    }

    private suspend fun upload(): SyncReport {
        val local = phone.readAll()
        val mappings = store.load()
        val accounts = GoogleAccounts.collect(context, phone)
        val photos = photoHashes.hashes(local)
        val result = api.sync(
            SyncRequest(
                device = DeviceDto(session.installId, "${Build.MANUFACTURER} ${Build.MODEL}"),
                googleAccounts = accounts,
                contacts = SyncPlanner.buildUpload(local, mappings, photos),
            ),
        )
        uploadPhotos(result.missingPhotos, photos)
        val serverUpdated = applyServerUpdates(result.updates, local, photos)
        // Контакты, которые администратор удалил на сервере, удаляем и из телефонной книги.
        val toRemove = SyncPlanner.rawIdsToRemove(local, result.removed)
        toRemove.forEach(phone::delete)

        // Соответствия пересобираем по ответу: так же уходят записи удалённых контактов.
        val links = result.links.associate { it.externalId to it.serverId }
        val personal = SyncPlanner.applyLinks(local, links).toMutableMap()

        // Восстановленные из корзины: ставим на телефон и запоминаем serverId — со следующей
        // синхронизацией контакт привяжется к серверному, а не создастся заново.
        val restorePlan = SyncPlanner.planRestore(result.restored, local, mappings.copy(personal = personal))
        personal += restorePlan.matched
        for (contact in restorePlan.toInsert) {
            personal[phone.insert(contact.toData(downloadPhoto(contact.photo)))] = contact.serverId
        }
        store.save(mappings.copy(personal = personal))

        return SyncReport(
            result.total, result.created, result.updated, result.deleted,
            restored = restorePlan.toInsert.size,
            serverUpdated = serverUpdated,
            removedByAdmin = result.removed.size,
            googleAccounts = accounts,
        )
    }

    /**
     * Записывает на телефон правки, сделанные на сервере (админка, объединение дублей). Фото меняется,
     * только если на сервере другое; убранное на сервере фото с телефона не удаляем.
     */
    private suspend fun applyServerUpdates(updates: List<ServerUpdate>, local: List<LocalContact>, photos: Map<Long, String>): Int {
        var applied = 0
        for (update in updates) {
            val contact = local.firstOrNull { it.externalId == update.externalId } ?: continue
            val rawId = phone.rawIdForWrite(contact.rawContactIds) ?: continue
            val photo = update.photo?.takeIf { it != photos[contact.contactId] }?.let { downloadPhoto(it) }
            phone.update(rawId, ContactData(update.name, update.phones, update.emails, photo, update.birthday))
            applied++
        }
        return applied
    }

    /** Загружает фото, которых нет на сервере. Сбой с фото не должен ломать синхронизацию контактов. */
    private suspend fun uploadPhotos(missing: List<String>, photos: Map<Long, String>) {
        for (sha in missing) {
            val contactId = photos.entries.firstOrNull { it.value == sha }?.key ?: continue
            val bytes = phone.photoBytes(contactId)?.takeIf { PhotoHashes.sha256(it) == sha } ?: continue
            runCatching { api.uploadContactPhoto(sha, bytes) }
                .onFailure { if (it is UnauthorizedException) throw it }
        }
    }

    private suspend fun downloadPhoto(sha: String?): ByteArray? =
        sha?.let { runCatching { api.downloadContactPhoto(it) }.getOrNull() }

    private suspend fun pullFamily(): SyncReport {
        val response = api.familyContacts()
        session.updateFamily(response.family)

        val mappings = store.load()
        val plan = SyncPlanner.planFamily(response.contacts, phone.readAll(), mappings.family)

        val family = plan.delivered.toMutableMap()
        for (contact in plan.toInsert) {
            family[contact.serverId] = FamilyEntry(phone.insert(contact.toData(downloadPhoto(contact.photo))), contact.updatedAt, createdByUs = true)
        }
        store.save(mappings.copy(family = family))

        return SyncReport(uploaded = 0, created = 0, updated = 0, deleted = 0, familyAdded = plan.toInsert.size)
    }

    private fun finish(report: SyncReport): SyncReport {
        session.lastSyncAt = System.currentTimeMillis()
        session.lastSyncSummary = report.summary()
        return report
    }

    companion object {
        val REQUIRED_PERMISSIONS = arrayOf(
            Manifest.permission.READ_CONTACTS,
            Manifest.permission.WRITE_CONTACTS,
        )

        /** Общий на процесс: воркеры и UI используют разные экземпляры движка. */
        private val lock = Mutex()
    }
}
