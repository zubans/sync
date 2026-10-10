package com.example.contactsync.launcher

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import java.util.concurrent.TimeUnit
import kotlin.concurrent.thread

/**
 * Автозапуск при загрузке: после включения планшета один раз выполняем от root скрипт владельца
 * `/data/local/boot.sh` (openconnect, свои звуки интерфейса и т.п.). В прошивке нет своего места
 * для таких скриптов (Magisk, service.d), а системный раздел мы не меняем.
 *
 * Скрипта нет — ничего не делаем. Root выдаёт суперпользователь при первом запуске.
 * Скрипт лежит в каталоге, доступном только root: иначе его мог бы подменить кто угодно.
 */
object BootScript {
    const val PATH = "/data/local/boot.sh"
    const val LOG = "/data/local/boot.log"
    private const val TIMEOUT_SECONDS = 120L

    /** Команда для `su -c`: дата в журнал, затем скрипт, если он есть. */
    fun command(path: String = PATH, log: String = LOG): String =
        "[ -f $path ] || exit 0; echo \"== \$(date) boot\" >> $log; sh $path >> $log 2>&1"

    fun run() {
        val process = ProcessBuilder("su", "-c", command()).redirectErrorStream(true).start()
        process.inputStream.bufferedReader().use { it.readText() }
        if (!process.waitFor(TIMEOUT_SECONDS, TimeUnit.SECONDS)) process.destroy()
    }
}

class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        if (intent.action != Intent.ACTION_BOOT_COMPLETED) return
        val pending = goAsync()
        thread(name = "boot-script") {
            try {
                BootScript.run()
            } catch (e: Exception) {
                // su нет или root не дали — грузимся как обычно, без скрипта.
            } finally {
                pending.finish()
            }
        }
    }
}
