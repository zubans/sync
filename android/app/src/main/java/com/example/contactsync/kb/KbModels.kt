package com.example.contactsync.kb

import kotlinx.serialization.Serializable

/** Ответы KB, только нужные приложению поля (остальные игнорируются). */
@Serializable
data class KbUser(val username: String, val displayName: String? = null)

@Serializable
data class KbProject(val id: Int, val slug: String, val key: String? = null, val name: String)

@Serializable
data class KbTask(
    val id: Int,
    val projectSlug: String,
    val projectKey: String? = null,
    val title: String,
    /** todo | in_progress | review | tech_debt | done | cancelled */
    val status: String,
    val priority: String = "normal",
    /** feature | bug | analysis | docs | epic */
    val type: String = "feature",
    /** Последний прогон модели по задаче; null — модель задачу не выполняла. */
    val run: KbRun? = null,
) {
    /** «SYNC-432» — как задачу называют в KB. */
    val code: String get() = projectKey?.let { "$it-$id" } ?: "#$id"
}

@Serializable
data class KbRun(
    val id: Int,
    /** planning | planned | discussing | running | review | accepted | rejected | failed */
    val status: String,
    /** Авторевью последнего diff: running | done | failed. */
    val reviewStatus: String? = null,
    val openComments: Int = 0,
)

@Serializable
data class KbMeeting(
    val id: Int,
    val title: String = "",
    /** draft | uploaded | compressing | ready | transcribing | postprocessing | done | waiting_local | needs_choice | failed */
    val status: String,
    val progress: KbProgress? = null,
    val error: String? = null,
)

@Serializable
data class KbProgress(val done: Int, val total: Int)

@Serializable
data class KbSignedUpload(val url: String, val headers: Map<String, String> = emptyMap(), val key: String)

@Serializable
data class KbModelOptions(val preselectedModelId: Int? = null)

@Serializable
data class KbSettings(val audioMaxMinutes: Int = 60)

@Serializable
data class KbLoginRequest(val username: String, val password: String)

@Serializable
data class KbNewMeeting(val title: String, val date: String)

@Serializable
data class KbAttachAudio(val key: String, val modelId: Int?)

@Serializable
data class KbSignRequest(val filename: String, val contentType: String)
