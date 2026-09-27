package com.example.contactsync.contacts

import android.content.ContentProviderOperation
import android.content.ContentResolver
import android.content.ContentUris
import android.provider.ContactsContract
import android.provider.ContactsContract.CommonDataKinds.Email
import android.provider.ContactsContract.CommonDataKinds.Phone
import android.provider.ContactsContract.CommonDataKinds.StructuredName
import android.provider.ContactsContract.Contacts
import android.provider.ContactsContract.Data
import android.provider.ContactsContract.RawContacts

/** Контакт телефонной книги (агрегированный из одного или нескольких raw-контактов). */
data class LocalContact(
    val contactId: Long,
    val lookupKey: String,
    val rawContactIds: List<Long>,
    val name: String?,
    val phones: List<String>,
    val emails: List<String>,
) {
    val fingerprint: String get() = Fingerprint.of(name, phones, emails)
}

/** Данные контакта для записи в телефонную книгу. */
data class ContactData(val name: String?, val phones: List<String>, val emails: List<String>) {
    val fingerprint: String get() = Fingerprint.of(name, phones, emails)
}

/**
 * «Отпечаток» контакта для поиска дублей: одинаковый контакт, записанный разными приложениями
 * или по-разному отформатированный номер, дают один отпечаток.
 */
object Fingerprint {
    fun of(name: String?, phones: List<String>, emails: List<String>): String {
        val normalizedName = name.orEmpty().trim().lowercase().replace(Regex("\\s+"), " ")
        // Последние 10 цифр: +7 900 000-00-01 и 8 (900) 000-00-01 — один номер.
        val normalizedPhones = phones.map { it.filter(Char::isDigit).takeLast(10) }.filter { it.isNotEmpty() }.sorted()
        val keys = normalizedPhones.ifEmpty { emails.map { it.trim().lowercase() }.sorted() }
        return normalizedName + "|" + keys.distinct().joinToString(",")
    }
}

/**
 * Чтение и запись телефонной книги через ContactsProvider.
 * Требует READ_CONTACTS, для записи — WRITE_CONTACTS.
 */
class PhoneContacts(private val resolver: ContentResolver) {

    fun readAll(): List<LocalContact> {
        val rawIds = mutableMapOf<Long, MutableList<Long>>()
        resolver.query(
            RawContacts.CONTENT_URI,
            arrayOf(RawContacts._ID, RawContacts.CONTACT_ID),
            "${RawContacts.DELETED} = 0",
            null,
            null,
        )?.use { c ->
            while (c.moveToNext()) {
                rawIds.getOrPut(c.getLong(1)) { mutableListOf() }.add(c.getLong(0))
            }
        }

        val phones = mutableMapOf<Long, MutableList<String>>()
        val emails = mutableMapOf<Long, MutableList<String>>()
        resolver.query(
            Data.CONTENT_URI,
            arrayOf(Data.CONTACT_ID, Data.MIMETYPE, Data.DATA1),
            "${Data.MIMETYPE} IN (?, ?)",
            arrayOf(Phone.CONTENT_ITEM_TYPE, Email.CONTENT_ITEM_TYPE),
            null,
        )?.use { c ->
            while (c.moveToNext()) {
                val value = c.getString(2)?.trim().orEmpty()
                if (value.isEmpty()) continue
                val target = if (c.getString(1) == Phone.CONTENT_ITEM_TYPE) phones else emails
                target.getOrPut(c.getLong(0)) { mutableListOf() }.add(value)
            }
        }

        val result = mutableListOf<LocalContact>()
        resolver.query(
            Contacts.CONTENT_URI,
            arrayOf(Contacts._ID, Contacts.LOOKUP_KEY, Contacts.DISPLAY_NAME_PRIMARY),
            null,
            null,
            "${Contacts.DISPLAY_NAME_PRIMARY} ASC",
        )?.use { c ->
            while (c.moveToNext()) {
                val id = c.getLong(0)
                result += LocalContact(
                    contactId = id,
                    // LOOKUP_KEY стабильнее _ID: переживает агрегацию и пересоздание строк контакта.
                    lookupKey = c.getString(1) ?: id.toString(),
                    rawContactIds = rawIds[id].orEmpty(),
                    name = c.getString(2),
                    phones = phones[id]?.distinct().orEmpty(),
                    emails = emails[id]?.distinct().orEmpty(),
                )
            }
        }
        return result
    }

    /** Создаёт контакт в памяти телефона (без привязки к аккаунту). Возвращает id raw-контакта. */
    fun insert(data: ContactData): Long {
        val ops = arrayListOf(
            ContentProviderOperation.newInsert(RawContacts.CONTENT_URI)
                .withValue(RawContacts.ACCOUNT_TYPE, null)
                .withValue(RawContacts.ACCOUNT_NAME, null)
                .build(),
        )
        ops += dataRows(data) { it.withValueBackReference(Data.RAW_CONTACT_ID, 0) }
        val results = resolver.applyBatch(ContactsContract.AUTHORITY, ops)
        return ContentUris.parseId(results[0].uri!!)
    }

    /** Перезаписывает имя, телефоны и email raw-контакта. */
    fun update(rawContactId: Long, data: ContactData) {
        val ops = arrayListOf(
            ContentProviderOperation.newDelete(Data.CONTENT_URI)
                .withSelection(
                    "${Data.RAW_CONTACT_ID} = ? AND ${Data.MIMETYPE} IN (?, ?, ?)",
                    arrayOf(
                        rawContactId.toString(),
                        StructuredName.CONTENT_ITEM_TYPE,
                        Phone.CONTENT_ITEM_TYPE,
                        Email.CONTENT_ITEM_TYPE,
                    ),
                )
                .build(),
        )
        ops += dataRows(data) { it.withValue(Data.RAW_CONTACT_ID, rawContactId) }
        resolver.applyBatch(ContactsContract.AUTHORITY, ops)
    }

    fun delete(rawContactId: Long) {
        resolver.delete(ContentUris.withAppendedId(RawContacts.CONTENT_URI, rawContactId), null, null)
    }

    /** id raw-контактов, которые ещё существуют. */
    fun existingRawIds(ids: Collection<Long>): Set<Long> {
        if (ids.isEmpty()) return emptySet()
        val result = mutableSetOf<Long>()
        ids.chunked(500).forEach { chunk ->
            resolver.query(
                RawContacts.CONTENT_URI,
                arrayOf(RawContacts._ID),
                "${RawContacts.DELETED} = 0 AND ${RawContacts._ID} IN (${chunk.joinToString(",")})",
                null,
                null,
            )?.use { c -> while (c.moveToNext()) result += c.getLong(0) }
        }
        return result
    }

    /** Email Google-аккаунтов, к которым привязаны контакты телефона. */
    fun googleAccountNames(): Set<String> {
        val result = mutableSetOf<String>()
        resolver.query(
            RawContacts.CONTENT_URI,
            arrayOf(RawContacts.ACCOUNT_NAME),
            "${RawContacts.ACCOUNT_TYPE} = ?",
            arrayOf(GOOGLE_ACCOUNT_TYPE),
            null,
        )?.use { c -> while (c.moveToNext()) c.getString(0)?.let(result::add) }
        return result
    }

    private fun dataRows(
        data: ContactData,
        bindRaw: (ContentProviderOperation.Builder) -> ContentProviderOperation.Builder,
    ): List<ContentProviderOperation> = buildList {
        data.name?.takeIf { it.isNotBlank() }?.let { name ->
            add(
                bindRaw(ContentProviderOperation.newInsert(Data.CONTENT_URI))
                    .withValue(Data.MIMETYPE, StructuredName.CONTENT_ITEM_TYPE)
                    .withValue(StructuredName.DISPLAY_NAME, name)
                    .build(),
            )
        }
        data.phones.forEach { phone ->
            add(
                bindRaw(ContentProviderOperation.newInsert(Data.CONTENT_URI))
                    .withValue(Data.MIMETYPE, Phone.CONTENT_ITEM_TYPE)
                    .withValue(Phone.NUMBER, phone)
                    .withValue(Phone.TYPE, Phone.TYPE_MOBILE)
                    .build(),
            )
        }
        data.emails.forEach { email ->
            add(
                bindRaw(ContentProviderOperation.newInsert(Data.CONTENT_URI))
                    .withValue(Data.MIMETYPE, Email.CONTENT_ITEM_TYPE)
                    .withValue(Email.ADDRESS, email)
                    .withValue(Email.TYPE, Email.TYPE_OTHER)
                    .build(),
            )
        }
    }

    companion object {
        const val GOOGLE_ACCOUNT_TYPE = "com.google"
    }
}
