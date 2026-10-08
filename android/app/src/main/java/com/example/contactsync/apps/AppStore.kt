package com.example.contactsync.apps

import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.net.Uri

/**
 * Магазин приложений, из которого было установлено приложение: по нему восстановление
 * предлагает поставить приложение оттуда же, а не из архива.
 */
data class AppStore(
    val packageName: String,
    val name: String,
    /** Карточка приложения в самом магазине. */
    private val deepLink: (String) -> String = { "market://details?id=$it" },
    /** Карточка на сайте — если магазина на телефоне нет. */
    private val webPage: ((String) -> String)? = null,
) {
    /** Открывает карточку приложения. false — магазина нет на телефоне и сайта у него нет. */
    fun open(context: Context, appPackage: String): Boolean {
        val inStore = Intent(Intent.ACTION_VIEW, Uri.parse(deepLink(appPackage))).setPackage(packageName)
        val candidates = listOfNotNull(inStore, webPage?.let { Intent(Intent.ACTION_VIEW, Uri.parse(it(appPackage))) })
        for (intent in candidates) {
            try {
                context.startActivity(intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
                return true
            } catch (e: ActivityNotFoundException) {
                continue
            }
        }
        return false
    }

    companion object {
        const val PLAY = "com.android.vending"

        private val known = listOf(
            AppStore(PLAY, "Google Play", webPage = { "https://play.google.com/store/apps/details?id=$it" }),
            AppStore("ru.vk.store", "RuStore", deepLink = { "rustore://apps.rustore.ru/app/$it" }, webPage = { "https://www.rustore.ru/catalog/app/$it" }),
            AppStore("com.huawei.appmarket", "AppGallery", deepLink = { "appmarket://details?id=$it" }),
            AppStore("com.sec.android.app.samsungapps", "Galaxy Store", deepLink = { "samsungapps://ProductDetail/$it" }, webPage = { "https://galaxystore.samsung.com/detail/$it" }),
            AppStore("com.xiaomi.mipicks", "GetApps"),
            AppStore("com.xiaomi.market", "GetApps"),
            AppStore("com.amazon.venezia", "Amazon Appstore", deepLink = { "amzn://apps/android?p=$it" }, webPage = { "https://www.amazon.com/gp/mas/dl/android?p=$it" }),
            AppStore("org.fdroid.fdroid", "F-Droid", webPage = { "https://f-droid.org/packages/$it" }),
        ).associateBy { it.packageName }

        /** Магазин по источнику установки; null — поставлено не из известного магазина (браузер, adb, файл). */
        fun of(installer: String?): AppStore? = installer?.let { known[it] }
    }
}
