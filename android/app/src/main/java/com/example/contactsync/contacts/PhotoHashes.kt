package com.example.contactsync.contacts

import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File
import java.security.MessageDigest

/**
 * SHA-256 фото контактов с кэшем по [LocalContact.photoKey]: фото читаются с диска только
 * при появлении или смене, а не при каждой синхронизации.
 */
class PhotoHashes(private val phone: PhoneContacts, private val file: File) {

    private val json = Json { ignoreUnknownKeys = true }

    /** SHA-256 фото по contactId (только для контактов с фото). */
    fun hashes(contacts: List<LocalContact>): Map<Long, String> {
        val cache = runCatching { json.decodeFromString<Map<String, String>>(file.readText()) }.getOrDefault(emptyMap())
        val updated = mutableMapOf<String, String>()
        val result = mutableMapOf<Long, String>()
        for (contact in contacts) {
            val key = contact.photoKey ?: continue
            val sha = cache[key] ?: phone.photoBytes(contact.contactId)?.let(::sha256) ?: continue
            updated[key] = sha
            result[contact.contactId] = sha
        }
        if (updated != cache) file.writeText(json.encodeToString(updated))
        return result
    }

    companion object {
        fun sha256(bytes: ByteArray): String =
            MessageDigest.getInstance("SHA-256").digest(bytes).joinToString("") { "%02x".format(it) }
    }
}
