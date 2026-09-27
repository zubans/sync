package com.example.contactsync.vault

import com.example.contactsync.data.VaultChange
import com.example.contactsync.data.VaultItemDto
import com.example.contactsync.data.VaultItemsResponse
import com.example.contactsync.data.VaultKeyDto
import com.example.contactsync.data.VaultPushResponse
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow
import kotlinx.coroutines.flow.asStateFlow
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.withContext
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import java.util.UUID
import javax.crypto.AEADBadTagException
import javax.crypto.SecretKey

enum class VaultStatus { NOT_SET_UP, LOCKED, UNLOCKED }

class WrongMasterPasswordException : Exception("Неверный мастер-пароль")

data class VaultSyncReport(val pushed: Int, val pulled: Int, val merged: Int, val pendingConflicts: Int)

/**
 * Хранилище паролей на устройстве.
 *
 * Правки сразу пишутся в локальный «исходящий» список (outbox) зашифрованными и отправляются при синхронизации
 * с ревизией, от которой делались. Конфликт (запись изменили на другом устройстве) сводится по правилам
 * [VaultLogic.resolve]: побеждает свежая версия, вытесненный пароль уходит в историю. Для сведения нужен ключ,
 * поэтому в заблокированном состоянии конфликт ждёт разблокировки, а остальное синхронизируется как есть —
 * отправка и получение шифротекста ключа не требуют.
 */
/** Серверная часть хранилища (реализована в [com.example.contactsync.data.Api]). */
interface VaultBackend {
    suspend fun vault(): VaultKeyDto?
    suspend fun createVault(key: VaultKeyDto): VaultKeyDto
    suspend fun rekeyVault(key: VaultKeyDto): VaultKeyDto
    suspend fun deleteVault()
    suspend fun vaultItems(since: Int): VaultItemsResponse
    suspend fun pushVault(changes: List<VaultChange>): VaultPushResponse
}

class VaultRepository(
    private val store: VaultStorage,
    private val api: VaultBackend,
    private val kdfIterations: Int = VaultCrypto.KDF_ITERATIONS,
    /** Разблокировка по отпечатку; null — недоступна (в тестах). */
    val biometric: BiometricUnlock? = null,
) {
    private val json = Json { ignoreUnknownKeys = true }
    private val mutex = Mutex()

    @Volatile private var vaultKey: SecretKey? = null
    @Volatile private var lastUsedAt = 0L

    private val _status = MutableStateFlow(initialStatus())
    val status: StateFlow<VaultStatus> = _status.asStateFlow()

    private val _records = MutableStateFlow<List<VaultRecord>>(emptyList())
    val records: StateFlow<List<VaultRecord>> = _records.asStateFlow()

    /** Есть ли правки, не принятые сервером. */
    val hasPendingChanges: Boolean get() = store.load().outbox.isNotEmpty()

    /** Ключ, если хранилище разблокировано и не истёк таймаут бездействия. */
    fun key(): SecretKey? {
        val key = vaultKey ?: return null
        if (System.currentTimeMillis() - lastUsedAt > AUTO_LOCK_MS) {
            lock()
            return null
        }
        lastUsedAt = System.currentTimeMillis()
        return key
    }

    fun isUnlocked(): Boolean = key() != null

    // --- Создание, разблокировка, смена пароля ---

    /** Создаёт хранилище на сервере. Если оно уже есть (создано с другого устройства) — нужно разблокировать его. */
    suspend fun setUp(masterPassword: CharArray) = mutex.withLock {
        val salt = VaultCrypto.newSalt()
        val vaultKey = VaultCrypto.newVaultKey()
        val protectedKey = withContext(Dispatchers.Default) {
            VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey(masterPassword, salt, kdfIterations), vaultKey)
        }
        val created = api.createVault(VaultKeyDto(VaultCrypto.KDF_ALGORITHM, kdfIterations, salt, protectedKey))
        store.save(VaultSnapshot(key = created))
        setUnlocked(vaultKey)
    }

    suspend fun unlock(masterPassword: CharArray) {
        val params = store.load().key ?: refreshKey() ?: error("Хранилище не создано")
        val key = withContext(Dispatchers.Default) {
            try {
                VaultCrypto.unwrapKey(VaultCrypto.deriveMasterKey(masterPassword, params.kdfSalt, params.kdfIterations), params.protectedKey)
            } catch (e: AEADBadTagException) {
                throw WrongMasterPasswordException()
            }
        }
        setUnlocked(key)
    }

    /** Разблокировка ключом, полученным по отпечатку. */
    fun unlockWith(key: SecretKey) = setUnlocked(key)

    fun lock() {
        vaultKey = null
        _records.value = emptyList()
        _status.value = initialStatus()
    }

    /** Смена мастер-пароля: ключ хранилища тот же, записи не перешифровываются. */
    suspend fun changeMasterPassword(newPassword: CharArray) = mutex.withLock {
        val key = key() ?: error("Хранилище заблокировано")
        val salt = VaultCrypto.newSalt()
        val protectedKey = withContext(Dispatchers.Default) {
            VaultCrypto.wrapKey(VaultCrypto.deriveMasterKey(newPassword, salt, kdfIterations), key)
        }
        val updated = api.rekeyVault(VaultKeyDto(VaultCrypto.KDF_ALGORITHM, kdfIterations, salt, protectedKey))
        store.update { it.copy(key = updated) }
    }

    /** Сброс (забыт мастер-пароль): удаляет хранилище на сервере и на устройстве. */
    suspend fun reset() = mutex.withLock {
        api.deleteVault()
        clearLocal()
    }

    /** Выход из аккаунта. */
    fun clearLocal() {
        store.clear()
        biometric?.disable()
        lock()
    }

    // --- Правки ---

    suspend fun upsert(id: String?, entry: VaultEntry): String = mutex.withLock {
        val key = key() ?: error("Хранилище заблокировано")
        val itemId = id ?: UUID.randomUUID().toString()
        val data = encrypt(key, itemId, entry)
        store.update { s ->
            val base = s.outbox[itemId]?.baseRevision ?: s.items[itemId]?.revision
            s.copy(outbox = s.outbox + (itemId to PendingChange(base, data)))
        }
        refreshRecords()
        itemId
    }

    suspend fun delete(id: String) = mutex.withLock {
        store.update { s ->
            val base = s.outbox[id]?.baseRevision ?: s.items[id]?.revision
            if (base == null) {
                s.copy(outbox = s.outbox - id)
            } else {
                s.copy(outbox = s.outbox + (id to PendingChange(base, deleted = true)))
            }
        }
        refreshRecords()
    }

    /** Применяет результат [VaultLogic.planSave] (логин, введённый в другом приложении). */
    suspend fun applySave(plan: SavePlan) {
        when (plan) {
            is SavePlan.Create -> upsert(null, plan.entry)
            is SavePlan.Update -> upsert(plan.id, plan.after)
            is SavePlan.Unchanged -> Unit
        }
    }

    fun planSave(uri: String, title: String, username: String, password: String): SavePlan =
        VaultLogic.planSave(_records.value, uri, title, username, password, System.currentTimeMillis())

    fun recordsFor(uri: String): List<VaultRecord> = _records.value.filter { VaultLogic.matches(it.entry, uri) }

    // --- Синхронизация ---

    /** Узнаёт с сервера параметры ключа (хранилище могли создать или сбросить с другого устройства). */
    suspend fun refreshKey(): VaultKeyDto? {
        val remote = api.vault()
        if (remote == null) {
            if (store.load().key != null) clearLocal()
            _status.value = VaultStatus.NOT_SET_UP
            return null
        }
        store.update { it.copy(key = remote) }
        if (_status.value == VaultStatus.NOT_SET_UP) _status.value = VaultStatus.LOCKED
        return remote
    }

    suspend fun sync(): VaultSyncReport = mutex.withLock {
        refreshKey() ?: return VaultSyncReport(0, 0, 0, 0)
        var pushed = 0
        var merged = 0

        // Несколько раундов: сведённый конфликт отправляется снова, пока не примут.
        for (round in 1..MAX_PUSH_ROUNDS) {
            val outbox = store.load().outbox
            if (outbox.isEmpty()) break
            val response = api.pushVault(outbox.map { (id, c) -> VaultChange(id, c.baseRevision, c.data, c.deleted) })
            var resolvedAny = false
            store.update { s ->
                var items = s.items
                var pending = s.outbox
                for (result in response.results) {
                    val sent = outbox[result.id] ?: continue
                    when (result.status) {
                        "ok" -> {
                            items = items + (result.id to StoredItem(result.revision, sent.data, sent.deleted))
                            if (pending[result.id] == sent) pending = pending - result.id
                            pushed++
                        }
                        "conflict" -> {
                            val current = result.current ?: continue
                            items = items + (result.id to current.toStored())
                            val resolved = resolveConflict(result.id, sent, current)
                            if (resolved != null) {
                                pending = if (resolved === DROP) pending - result.id else pending + (result.id to resolved)
                                merged++
                                resolvedAny = true
                            }
                        }
                    }
                }
                s.copy(items = items, outbox = pending)
            }
            if (!resolvedAny) break
        }

        val pulled = pull()
        refreshRecords()
        VaultSyncReport(pushed, pulled, merged, store.load().outbox.size)
    }

    private suspend fun pull(): Int {
        val since = store.load().revision
        val response = api.vaultItems(since)
        store.update { s ->
            s.copy(
                revision = response.revision,
                items = s.items + response.items.associate { it.id to it.toStored() },
            )
        }
        return response.items.size
    }

    /**
     * Сводит локальную правку с серверной версией. null — нельзя без ключа (заблокировано), [DROP] — правка
     * больше не нужна (сервер уже в нужном состоянии), иначе — новая правка от ревизии сервера.
     */
    private fun resolveConflict(id: String, local: PendingChange, remote: VaultItemDto): PendingChange? {
        val key = vaultKey ?: return null
        val localEntry = if (local.deleted) null else local.data?.let { decrypt(key, id, it) }
        val remoteEntry = if (remote.deleted) null else remote.data?.let { decrypt(key, id, it) }
        val resolved = VaultLogic.resolve(localEntry, remoteEntry)
        return when {
            resolved == null && remote.deleted -> DROP
            resolved == null -> PendingChange(remote.revision, deleted = true)
            resolved == remoteEntry -> DROP
            else -> PendingChange(remote.revision, encrypt(key, id, resolved))
        }
    }

    // --- Вспомогательное ---

    private fun setUnlocked(key: SecretKey) {
        vaultKey = key
        lastUsedAt = System.currentTimeMillis()
        _status.value = VaultStatus.UNLOCKED
        refreshRecords()
    }

    private fun refreshRecords() {
        val key = vaultKey ?: return
        val snapshot = store.load()
        _records.value = snapshot.ids
            .mapNotNull { id -> snapshot.effectiveData(id)?.let { data -> runCatching { VaultRecord(id, decrypt(key, id, data)) }.getOrNull() } }
            .sortedBy { it.entry.title.lowercase() }
    }

    private fun initialStatus(): VaultStatus =
        if (store.load().key == null) VaultStatus.NOT_SET_UP else VaultStatus.LOCKED

    private fun encrypt(key: SecretKey, id: String, entry: VaultEntry): String =
        VaultCrypto.encryptItem(key, id, json.encodeToString(entry).toByteArray())

    private fun decrypt(key: SecretKey, id: String, data: String): VaultEntry =
        json.decodeFromString(String(VaultCrypto.decryptItem(key, id, data)))

    private fun VaultItemDto.toStored() = StoredItem(revision, data, deleted)

    companion object {
        /** Автоблокировка после 5 минут бездействия. */
        const val AUTO_LOCK_MS = 5 * 60 * 1000L
        private const val MAX_PUSH_ROUNDS = 3
        private val DROP = PendingChange(null)
    }
}
