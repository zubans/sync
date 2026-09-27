package com.example.contactsync.data

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
class ApiException(message: String) : IOException(message)

class Api(
    private val session: Session,
    private val client: OkHttpClient = OkHttpClient.Builder()
        .connectTimeout(15, TimeUnit.SECONDS)
        .readTimeout(60, TimeUnit.SECONDS)
        .build(),
) {
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
                !response.isSuccessful -> throw ApiException(errorMessage(response.code, body))
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
    }
}
