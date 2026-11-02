package com.example.contactsync.browsing

import android.view.accessibility.AccessibilityNodeInfo

/**
 * Достаёт адрес из строки браузера по дереву специальных возможностей.
 *
 * Прямого доступа к истории браузеров у приложений нет (она в их закрытых базах), поэтому адрес
 * читается из адресной строки в момент, когда переход уже совершён. Для каждого браузера нужен свой
 * идентификатор поля адреса; при смене вёрстки браузера его придётся обновить.
 */
object BrowserHistory {

    /** Пакет браузера → идентификатор поля адреса. */
    private val URL_BARS = mapOf(
        "com.android.chrome" to "com.android.chrome:id/url_bar",
        "com.chrome.beta" to "com.chrome.beta:id/url_bar",
        "com.chrome.dev" to "com.chrome.dev:id/url_bar",
        "org.chromium.chrome" to "org.chromium.chrome:id/url_bar",
        "com.yandex.browser" to "com.yandex.browser:id/bro_omnibar_address_title_text",
        "com.mi.globalbrowser" to "com.mi.globalbrowser:id/url",
        "com.sec.android.app.sbrowser" to "com.sec.android.app.sbrowser:id/location_bar_edit_text",
        "org.mozilla.firefox" to "org.mozilla.firefox:id/mozac_browser_toolbar_url_view",
    )

    fun isBrowser(packageName: String?): Boolean = packageName in URL_BARS

    /**
     * Адрес из строки браузера или null. Поле в фокусе (пользователь набирает запрос) и значения,
     * не похожие на адрес, пропускаются — пишем только совершённые переходы.
     */
    fun urlFrom(root: AccessibilityNodeInfo?, packageName: String?): String? {
        val viewId = URL_BARS[packageName] ?: return null
        val node = root?.findAccessibilityNodeInfosByViewId(viewId)?.firstOrNull { it.isVisibleToUser } ?: return null
        if (node.isFocused) return null
        val text = node.text?.toString()?.trim().orEmpty()
        return text.takeIf { looksLikeUrl(it) }
    }

    /** Адрес без схемы браузеры показывают как «example.com/путь»; поисковый запрос — со пробелами. */
    private fun looksLikeUrl(text: String): Boolean {
        if (text.isEmpty() || text.length > MAX_URL || text.any { it.isWhitespace() }) return false
        if (text.startsWith("http://") || text.startsWith("https://")) return true
        // Домен: есть точка до первого слэша и слева от точки что-то есть.
        val host = text.substringBefore('/')
        val dot = host.indexOf('.')
        return dot in 1 until host.length - 1
    }

    private const val MAX_URL = 4000
}
