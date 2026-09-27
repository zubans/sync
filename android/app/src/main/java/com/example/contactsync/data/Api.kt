package com.example.contactsync.data

import com.example.contactsync.vault.VaultBackend
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import kotlinx.serialization.Serializable
import kotlinx.serialization.encodeToString
import kotlinx.serialization.json.Json
import kotlinx.serialization.json.JsonObject
import kotlinx.serialization.json.jsonPrimitive
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import java.io.IOException
import java.util.concurrent.TimeUnit

@Serializable
data class FamilyDto(val id: Int, val name: String)

@Serializable
data class UserDto(val email: String, val family: FamilyDto? = null)

@Serializable
data class AuthResponse(val token: String, val user: UserDto)

@Serializable
data class Credentials(val email: String, val password: String)

@Serializable
data class DeviceDto(val installId: String, val model: String?)

/** Локальный контакт при выгрузке на сервер. */
@Serializable
data class ContactUpload(
    val externalId: String,
    val serverId: String? = null,
    val name: String?,
    val phones: List<String>,
    val emails: List<String>,
)

@Serializable
data class SyncRequest(
    val device: DeviceDto,
    val googleAccounts: List<String>,
    val contacts: List<ContactUpload>,
)

@Serializable
data class SyncLink(val externalId: String, val serverId: String)

@Serializable
data class SyncResult(
    val created: Int,
    val updated: Int,
    val deleted: Int,
    val total: Int,
    val links: List<SyncLink> = emptyList(),
    /** externalId контактов, удалённых администратором: их нужно удалить из телефонной книги. */
    val removed: List<String> = emptyList(),
)

/** Контакт, хранящийся на сервере. */
@Serializable
data class ServerContact(
    val serverId: String,
    val name: String? = null,
    val phones: List<String> = emptyList(),
    val emails: List<String> = emptyList(),
    val updatedAt: String,
)

@Serializable
data class ContactsResponse(val contacts: List<ServerContact>)

@Serializable
data class FamilyContactsResponse(val family: FamilyDto? = null, val contacts: List<ServerContact>)

/** Сервер не принял токен: нужно войти заново. */
class UnauthorizedException : IOException("Сессия истекла, войдите заново")

/** Ошибка, текст которой можно показать пользователю. */
class ApiException(message: String, val code: Int = 0) : IOException(message)

// --- Хранилище паролей ---

@Serializable
data class VaultKeyDto(
    val kdfAlgorithm: String,
    val kdfIterations: Int,
    val kdfSalt: String,
    val protectedKey: String,
    val revision: Int = 0,
    val kdfMemory: Int? = null,
    val kdfParallelism: Int? = null,
)

@Serializable
data class VaultItemDto(val id: String, val revision: Int, val data: String? = null, val deleted: Boolean = false)

@Serializable
data class VaultItemsResponse(val revision: Int, val items: List<VaultItemDto>)

@Serializable
data class VaultChange(val id: String, val baseRevision: Int?, val data: String?, val deleted: Boolean)

@Serializable
data class VaultChangesRequest(val changes: List<VaultChange>)

@Serializable
data class VaultChangeResult(
    val id: String,
    val status: String,
    val revision: Int = 0,
    val current: VaultItemDto? = null,
    val error: String? = null,
)

@Serializable
data class VaultPushResponse(val revision: Int, val results: List<VaultChangeResult>)

// --- Резервные копии приложений ---

@Serializable
data class ApkFileDto(val name: String, val sha256: String, val size: Long)

@Serializable
data class AppDto(
    val packageName: String,
    val label: String? = null,
    val versionName: String? = null,
    val versionCode: Long,
    val installer: String? = null,
    val signingSha256: String? = null,
    val files: List<ApkFileDto>,
)

@Serializable
data class InventoryRequest(val device: DeviceDto, val apps: List<AppDto>)

@Serializable
data class InventoryResponse(val missing: List<String>)

@Serializable
data class BackedUpApp(
    val packageName: String,
    val label: String? = null,
    val versionName: String? = null,
    val versionCode: Long,
    val fromPlay: Boolean,
    val signingSha256: String? = null,
    val files: List<ApkFileDto>,
    val size: Long,
    val backedUp: Boolean,
)

@Serializable
data class AppsResponse(val apps: List<BackedUpApp>)

@Serializable
data class UploadState(val complete: Boolean = false, val offset: Long = 0)

class Api(
    private val session: Session,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build(),
) : VaultBackend {
    private val json = Json { ignoreUnknownKeys = true; explicitNulls = false }

    suspend fun login(email: String, password: String): AuthResponse =
        post("/api/auth/login", json.encodeToString(Credentials(email, password)), auth = false)

    suspend fun register(email: String, password: String): AuthResponse =
        post("/api/auth/register", json.encodeToString(Credentials(email, password)), auth = false)

    suspend fun logout() {
        post<JsonObject?>("/api/auth/logout", "{}")
    }

    suspend fun me(): UserDto = get("/api/me")

    suspend fun sync(request: SyncRequest): SyncResult = post("/api/sync", json.encodeToString(request))

    suspend fun personalContacts(): List<ServerContact> = get<ContactsResponse>("/api/contacts").contacts

    suspend fun familyContacts(): FamilyContactsResponse = get("/api/family/contacts")

    /** null — хранилище ещё не создано. */
    override suspend fun vault(): VaultKeyDto? = try {
        get("/api/vault")
    } catch (e: ApiException) {
        if (e.code == 404) null else throw e
    }

    override suspend fun createVault(key: VaultKeyDto): VaultKeyDto = post("/api/vault", json.encodeToString(key))

    override suspend fun rekeyVault(key: VaultKeyDto): VaultKeyDto =
        execute(request("/api/vault/key", auth = true).put(json.encodeToString(key).toRequestBody(JSON)).build())

    override suspend fun deleteVault() {
        execute<JsonObject?>(request("/api/vault", auth = true).delete().build())
    }

    override suspend fun vaultItems(since: Int): VaultItemsResponse = get("/api/vault/items?since=$since")

    override suspend fun pushVault(changes: List<VaultChange>): VaultPushResponse =
        post("/api/vault/items", json.encodeToString(VaultChangesRequest(changes)))

    suspend fun inventory(request: InventoryRequest): InventoryResponse =
        post("/api/apps/inventory", json.encodeToString(request))

    suspend fun backedUpApps(): List<BackedUpApp> = get<AppsResponse>("/api/apps").apps

    suspend fun uploadState(sha256: String): UploadState = get("/api/apk/uploads/$sha256")

    /** Отправляет часть файла. При неверном смещении сервер отвечает 409 с верным — его и возвращаем. */
    suspend fun uploadChunk(sha256: String, offset: Long, bytes: ByteArray, length: Int): UploadState =
        withContext(Dispatchers.IO) {
            val request = request("/api/apk/uploads/$sha256?offset=$offset", auth = true)
                .put(bytes.toRequestBody(OCTET, 0, length))
                .build()
            client.newCall(request).execute().use { response ->
                val body = response.body?.string().orEmpty()
                when {
                    response.code == 401 -> throw UnauthorizedException()
                    response.isSuccessful || response.code == 409 -> json.decodeFromString<UploadState>(body)
                    else -> throw ApiException(errorMessage(response.code, body), response.code)
                }
            }
        }

    suspend fun completeUpload(sha256: String) {
        post<JsonObject?>("/api/apk/uploads/$sha256/complete", "{}")
    }

    /** Скачивает APK в файл. */
    suspend fun downloadApk(sha256: String, target: java.io.File) = withContext(Dispatchers.IO) {
        client.newCall(request("/api/apk/$sha256", auth = true).get().build()).execute().use { response ->
            if (response.code == 401) throw UnauthorizedException()
            if (!response.isSuccessful) throw ApiException("Не удалось скачать APK (HTTP ${response.code})", response.code)
            target.outputStream().use { out -> response.body!!.byteStream().copyTo(out) }
        }
    }

    private suspend inline fun <reified T> get(path: String): T =
        execute(request(path, auth = true).get().build())

    private suspend inline fun <reified T> post(path: String, body: String, auth: Boolean = true): T =
        execute(request(path, auth).post(body.toRequestBody(JSON)).build())

    private fun request(path: String, auth: Boolean): Request.Builder {
        val builder = Request.Builder()
            .url(session.serverUrl.trimEnd('/') + path)
            .header("Accept", "application/json")
        if (auth) {
            val token = session.token ?: throw UnauthorizedException()
            builder.header("Authorization", "Bearer $token")
        }
        return builder
    }

    private suspend inline fun <reified T> execute(request: Request): T = withContext(Dispatchers.IO) {
        client.newCall(request).execute().use { response ->
            val body = response.body?.string().orEmpty()
            when {
                response.code == 401 && request.header("Authorization") != null -> throw UnauthorizedException()
                !response.isSuccessful -> throw ApiException(errorMessage(response.code, body), response.code)
                body.isBlank() -> null as T
                else -> json.decodeFromString<T>(body)
            }
        }
    }

    private fun errorMessage(code: Int, body: String): String {
        val message = runCatching {
            val obj = json.decodeFromString<JsonObject>(body)
            (obj["error"] ?: obj["detail"])?.jsonPrimitive?.content
        }.getOrNull()
        return message ?: "Ошибка сервера (HTTP $code)"
    }

    private companion object {
        val JSON = "application/json; charset=utf-8".toMediaType()
        val OCTET = "application/octet-stream".toMediaType()
    }
}
