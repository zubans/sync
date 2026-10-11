package com.example.contactsync.files

import android.Manifest
import android.content.ActivityNotFoundException
import android.content.ClipData
import android.content.Context
import android.content.Intent
import android.graphics.Color
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.provider.Settings
import android.webkit.MimeTypeMap
import androidx.activity.ComponentActivity
import androidx.activity.SystemBarStyle
import androidx.activity.compose.setContent
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.activity.viewModels
import androidx.compose.material3.MaterialTheme
import androidx.compose.runtime.getValue
import androidx.core.content.FileProvider
import androidx.lifecycle.compose.collectAsStateWithLifecycle
import com.example.contactsync.launcher.LauncherColors
import java.io.File

/** Отдельное приложение «Файлы»: файловый менеджер в стиле главного экрана планшета. */
class FilesActivity : ComponentActivity() {

    private val vm: FilesViewModel by viewModels()

    private val legacyPermission = registerForActivityResult(ActivityResultContracts.RequestMultiplePermissions()) {
        vm.onResume(hasStorageAccess(this))
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        enableEdgeToEdge(SystemBarStyle.dark(Color.TRANSPARENT), SystemBarStyle.dark(Color.TRANSPARENT))
        setContent {
            MaterialTheme(colorScheme = LauncherColors) {
                val state by vm.state.collectAsStateWithLifecycle()
                FilesScreen(state, vm, onRequestAccess = ::requestAccess, onExit = ::finish)
            }
        }
    }

    override fun onResume() {
        super.onResume()
        // Доступ выдаётся на экране настроек — проверяем его каждый раз при возврате.
        vm.onResume(hasStorageAccess(this))
    }

    /**
     * Android 11+: «Доступ ко всем файлам» выдаётся только в настройках, диалога для него нет.
     * Android 8–10: обычное разрешение на память.
     */
    private fun requestAccess() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            val app = Intent(Settings.ACTION_MANAGE_APP_ALL_FILES_ACCESS_PERMISSION, Uri.parse("package:$packageName"))
            try {
                startActivity(app)
            } catch (e: ActivityNotFoundException) {
                startActivity(Intent(Settings.ACTION_MANAGE_ALL_FILES_ACCESS_PERMISSION))
            }
        } else {
            legacyPermission.launch(arrayOf(Manifest.permission.READ_EXTERNAL_STORAGE, Manifest.permission.WRITE_EXTERNAL_STORAGE))
        }
    }
}

private fun Context.uriFor(path: String): Uri = FileProvider.getUriForFile(this, "$packageName.files", File(path))

internal fun mimeOf(entry: FileEntry): String =
    MimeTypeMap.getSingleton().getMimeTypeFromExtension(entry.extension)
        ?: if (entry.extension == "apk") "application/vnd.android.package-archive" else "*/*"

/**
 * Открывает файл в подходящем приложении. [choose] — всегда спросить, чем открыть
 * («Открыть с помощью»), иначе — приложением по умолчанию. `false` — открыть нечем.
 */
internal fun Context.openFile(entry: FileEntry, choose: Boolean = false): Boolean {
    val view = Intent(Intent.ACTION_VIEW)
        .setDataAndType(uriFor(entry.path), mimeOf(entry))
        .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION or Intent.FLAG_GRANT_WRITE_URI_PERMISSION)
    return try {
        startActivity(if (choose) Intent.createChooser(view, "Открыть с помощью") else view)
        true
    } catch (e: ActivityNotFoundException) {
        false
    } catch (e: IllegalArgumentException) {
        // FileProvider не отдаёт файлы вне описанных корней (например, на неизвестном носителе).
        false
    }
}

internal fun Context.shareFiles(entries: List<FileEntry>) {
    val files = entries.filter { !it.isDir }
    if (files.isEmpty()) return
    val uris = files.map { uriFor(it.path) }
    val type = files.map(::mimeOf).distinct().singleOrNull() ?: "*/*"
    val send = if (uris.size == 1) {
        Intent(Intent.ACTION_SEND).putExtra(Intent.EXTRA_STREAM, uris[0])
    } else {
        Intent(Intent.ACTION_SEND_MULTIPLE).putParcelableArrayListExtra(Intent.EXTRA_STREAM, ArrayList(uris))
    }
    send.setType(type).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
    // ClipData нужен, чтобы право на чтение получило и выбранное в шторке приложение.
    send.clipData = ClipData.newRawUri(null, uris[0]).apply { uris.drop(1).forEach { addItem(ClipData.Item(it)) } }
    startActivity(Intent.createChooser(send, "Поделиться"))
}
