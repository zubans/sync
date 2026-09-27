package com.example.contactsync.vault

import android.content.Context
import com.example.contactsync.data.VaultKeyDto
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.io.File

/** Версия записи, известная серверу. */
@Serializable
data class StoredItem(val revision: Int, val data: String? = null, val deleted: Boolean = false)

/** Локальная правка, ещё не принятая сервером. */
@Serializable
data class PendingChange(val baseRevision: Int?, val data: String? = null, val deleted: Boolean = false)

@Serializable
data class VaultSnapshot(
    val key: VaultKeyDto? = null,
    /** До какой ревизии сервера мы всё получили. */
    val revision: Int = 0,
    val items: Map<String, StoredItem> = emptyMap(),
    val outbox: Map<String, PendingChange> = emptyMap(),
) {
    /** Шифротекст записи с учётом неотправленных правок; null — записи нет или удалена. */
    fun effectiveData(id: String): String? {
        outbox[id]?.let { return if (it.deleted) null else it.data }
        return items[id]?.takeUnless { it.deleted }?.data
    }

    val ids: Set<String> get() = items.keys + outbox.keys
}

/** Локальная копия хранилища. */
interface VaultStorage {
    fun load(): VaultSnapshot
    fun save(snapshot: VaultSnapshot)
    fun update(block: (VaultSnapshot) -> VaultSnapshot): VaultSnapshot
    fun clear()
}

/**
 * Локальная копия хранилища в файле: только шифротексты и параметры ключа, расшифровать без мастер-пароля нельзя.
 * Нужна для работы автозаполнения без сети и для отправки правок позже.
 */
class VaultStore(context: Context) : VaultStorage {

    private val file = File(context.filesDir, "vault.json")
    private val json = Json { ignoreUnknownKeys = true }

    @Synchronized
    override fun load(): VaultSnapshot = runCatching { json.decodeFromString<VaultSnapshot>(file.readText()) }.getOrDefault(VaultSnapshot())

    @Synchronized
    override fun save(snapshot: VaultSnapshot) {
        val tmp = File(file.parentFile, file.name + ".tmp")
        tmp.writeText(json.encodeToString(snapshot))
        tmp.renameTo(file)
    }

    @Synchronized
    override fun update(block: (VaultSnapshot) -> VaultSnapshot): VaultSnapshot = block(load()).also(::save)

    @Synchronized
    override fun clear() {
        file.delete()
    }
}
