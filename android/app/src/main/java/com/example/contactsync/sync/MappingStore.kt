package com.example.contactsync.sync

import android.content.Context
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File

/** Семейный контакт, положенный на телефон. */
@Serializable
data class FamilyEntry(
    val rawContactId: Long,
    /** updatedAt с сервера — чтобы понять, что контакт поменяли в админке. */
    val updatedAt: String,
    /** Контакт создан приложением (иначе совпал с уже существовавшим — такой не удаляем). */
    val createdByUs: Boolean,
)

@Serializable
data class Mappings(
    /** raw-контакт телефона → uuid личного контакта на сервере. */
    val personal: Map<Long, String> = emptyMap(),
    /** uuid семейного контакта на сервере → его копия на телефоне. */
    val family: Map<String, FamilyEntry> = emptyMap(),
)

/**
 * Соответствие контактов телефона серверным. Хранится в файле приложения,
 * привязано к вошедшему пользователю и очищается при выходе.
 */
class MappingStore(context: Context) {

    private val file = File(context.filesDir, "mappings.json")
    private val json = Json { ignoreUnknownKeys = true }

    fun load(): Mappings = runCatching { json.decodeFromString<Mappings>(file.readText()) }.getOrDefault(Mappings())

    fun save(mappings: Mappings) {
        val tmp = File(file.parentFile, file.name + ".tmp")
        tmp.writeText(json.encodeToString(mappings))
        tmp.renameTo(file)
    }

    fun clear() {
        file.delete()
    }
}
