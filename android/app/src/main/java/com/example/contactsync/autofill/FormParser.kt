package com.example.contactsync.autofill

import android.app.assist.AssistStructure
import android.app.assist.AssistStructure.ViewNode
import android.os.Build
import android.text.InputType
import android.view.View
import android.view.autofill.AutofillId
import com.example.contactsync.vault.VaultLogic

/** Форма входа, найденная на экране другого приложения. */
data class ParsedForm(
    val packageName: String,
    /** Домен страницы, если форма в браузере или WebView. */
    val webDomain: String?,
    val usernameIds: List<AutofillId>,
    val passwordIds: List<AutofillId>,
    /** Введённые значения — заполнены в запросе на сохранение. */
    val username: String,
    val passwords: List<String>,
) {
    /** Адрес для поиска записей: сайт важнее приложения (один браузер — много сайтов). */
    val uri: String get() = webDomain?.let { "https://$it" } ?: VaultLogic.APP_SCHEME + packageName

    val allIds: List<AutofillId> get() = usernameIds + passwordIds

    /**
     * Новый пароль из формы. На форме смены пароля обычно поля «старый, новый, повтор» —
     * нужен последний непустой.
     */
    val newPassword: String? get() = passwords.lastOrNull { it.isNotEmpty() }
}

/**
 * Поиск полей логина и пароля. Смотрим, по убыванию надёжности: autofillHints, HTML-атрибуты
 * (autocomplete, type), inputType, затем id и подсказку поля.
 */
object FormParser {

    private val USERNAME_WORDS = listOf("user", "login", "email", "e-mail", "mail", "phone", "логин", "почт", "телефон", "имя пользователя")
    private val PASSWORD_WORDS = listOf("password", "passwd", "pass", "пароль")

    fun parse(structure: AssistStructure): ParsedForm? {
        val fields = mutableListOf<Field>()
        var webDomain: String? = null
        for (i in 0 until structure.windowNodeCount) {
            walk(structure.getWindowNodeAt(i).rootViewNode) { node ->
                if (webDomain == null && !node.webDomain.isNullOrBlank()) webDomain = node.webDomain
                classify(node, order = fields.size)?.let(fields::add)
            }
        }

        val passwords = fields.filter { it.kind == Kind.PASSWORD }
        var usernames = fields.filter { it.kind == Kind.USERNAME }
        // Явного поля логина нет, но перед паролем есть текстовое поле — скорее всего, это логин.
        if (usernames.isEmpty() && passwords.isNotEmpty()) {
            usernames = listOfNotNull(fields.filter { it.kind == Kind.TEXT && it.order < passwords.first().order }.lastOrNull())
        }
        if (usernames.isEmpty() && passwords.isEmpty()) return null

        return ParsedForm(
            packageName = structure.activityComponent.packageName,
            webDomain = webDomain?.lowercase()?.removePrefix("www."),
            usernameIds = usernames.map { it.id },
            passwordIds = passwords.map { it.id },
            username = usernames.firstNotNullOfOrNull { it.value?.takeIf(String::isNotBlank) }.orEmpty().trim(),
            passwords = passwords.mapNotNull { it.value },
        )
    }

    private enum class Kind { USERNAME, PASSWORD, TEXT }

    private data class Field(val id: AutofillId, val kind: Kind, val value: String?, val order: Int)

    private fun walk(node: ViewNode, visit: (ViewNode) -> Unit) {
        visit(node)
        for (i in 0 until node.childCount) walk(node.getChildAt(i), visit)
    }

    private fun classify(node: ViewNode, order: Int): Field? {
        val id = node.autofillId ?: return null
        if (node.autofillType != View.AUTOFILL_TYPE_TEXT) return null
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P &&
            (node.importantForAutofill == View.IMPORTANT_FOR_AUTOFILL_NO ||
                node.importantForAutofill == View.IMPORTANT_FOR_AUTOFILL_NO_EXCLUDE_DESCENDANTS)
        ) return null
        if (node.visibility != View.VISIBLE) return null

        val kind = kindByHints(node) ?: kindByHtml(node) ?: kindByInputType(node) ?: kindByText(node) ?: Kind.TEXT
        return Field(id, kind, node.autofillValue?.takeIf { it.isText }?.textValue?.toString(), order)
    }

    private fun kindByHints(node: ViewNode): Kind? {
        val hints = node.autofillHints?.map { it.lowercase() } ?: return null
        return when {
            hints.any { "password" in it } -> Kind.PASSWORD
            hints.any { it == View.AUTOFILL_HINT_USERNAME.lowercase() || it == View.AUTOFILL_HINT_EMAIL_ADDRESS.lowercase() || "username" in it || "email" in it } -> Kind.USERNAME
            else -> null
        }
    }

    private fun kindByHtml(node: ViewNode): Kind? {
        val attrs = node.htmlInfo?.attributes?.associate { it.first.lowercase() to it.second.orEmpty().lowercase() } ?: return null
        val type = attrs["type"]
        val autocomplete = attrs["autocomplete"].orEmpty()
        return when {
            type == "password" || "password" in autocomplete -> Kind.PASSWORD
            type == "email" || "username" in autocomplete || "email" in autocomplete -> Kind.USERNAME
            type == "text" || type == "tel" -> matchWords(attrs["name"] + " " + attrs["id"])
            else -> null
        }
    }

    private fun kindByInputType(node: ViewNode): Kind? {
        val type = node.inputType
        if (type and InputType.TYPE_MASK_CLASS != InputType.TYPE_CLASS_TEXT) return null
        return when (type and InputType.TYPE_MASK_VARIATION) {
            InputType.TYPE_TEXT_VARIATION_PASSWORD,
            InputType.TYPE_TEXT_VARIATION_WEB_PASSWORD,
            InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD -> Kind.PASSWORD
            InputType.TYPE_TEXT_VARIATION_EMAIL_ADDRESS,
            InputType.TYPE_TEXT_VARIATION_WEB_EMAIL_ADDRESS -> Kind.USERNAME
            else -> null
        }
    }

    private fun kindByText(node: ViewNode): Kind? =
        matchWords(listOfNotNull(node.idEntry, node.hint, node.contentDescription?.toString()).joinToString(" "))

    private fun matchWords(text: String): Kind? {
        val value = text.lowercase()
        return when {
            PASSWORD_WORDS.any { it in value } -> Kind.PASSWORD
            USERNAME_WORDS.any { it in value } -> Kind.USERNAME
            else -> null
        }
    }
}
