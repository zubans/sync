package com.example.contactsync.files

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.media.ExifInterface
import android.media.MediaMetadataRetriever
import android.media.ThumbnailUtils
import android.os.Build
import android.provider.MediaStore
import android.util.LruCache
import android.util.Size
import androidx.compose.foundation.Canvas
import androidx.compose.foundation.Image
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.offset
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.Icon
import androidx.compose.material3.Text
import androidx.compose.runtime.Composable
import androidx.compose.runtime.produceState
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size as GeometrySize
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.ImageBitmap
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.SolidColor
import androidx.compose.ui.graphics.asImageBitmap
import androidx.compose.ui.graphics.vector.ImageVector
import androidx.compose.ui.graphics.vector.addPathNodes
import androidx.compose.ui.layout.ContentScale
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.platform.LocalDensity
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import androidx.core.graphics.drawable.toBitmap
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.ExperimentalCoroutinesApi
import kotlinx.coroutines.withContext
import java.io.File

/** Цвет вида файла: значок, плашка расширения и акцент в превью. */
internal val FileKind.color: Color
    get() = when (this) {
        FileKind.FOLDER -> Color(0xFF8AB4F8)
        FileKind.IMAGE -> Color(0xFF4CC38A)
        FileKind.VIDEO -> Color(0xFFB98AF8)
        FileKind.AUDIO -> Color(0xFFF28FB0)
        FileKind.PDF -> Color(0xFFF2726F)
        FileKind.DOCUMENT -> Color(0xFF5E9CF2)
        FileKind.SHEET -> Color(0xFF3FB27F)
        FileKind.SLIDES -> Color(0xFFEF9F27)
        FileKind.TEXT -> Color(0xFFB4BCC8)
        FileKind.CODE -> Color(0xFF5ED3D3)
        FileKind.ARCHIVE -> Color(0xFFD9B26A)
        FileKind.APK -> Color(0xFF9BD45A)
        FileKind.OTHER -> Color(0xFF8B93A1)
    }

/**
 * Значок файла: превью картинки, кадр видео, обложка трека, иконка APK — или нарисованная
 * плашка: папка либо «лист» с расширением в цвете вида файла.
 */
@Composable
internal fun FileIcon(entry: FileEntry, size: Dp, modifier: Modifier = Modifier) {
    val context = LocalContext.current
    val px = with(LocalDensity.current) { size.roundToPx() }
    val thumb = if (Thumbnails.supports(entry.kind)) {
        produceState(Thumbnails.cached(entry, px), entry.path, entry.modified, px) {
            if (value == null) value = Thumbnails.load(context, entry, px)
        }.value
    } else {
        null
    }
    Box(modifier.size(size), contentAlignment = Alignment.Center) {
        when {
            thumb != null -> Image(
                thumb,
                contentDescription = null,
                contentScale = if (entry.kind == FileKind.APK) ContentScale.Fit else ContentScale.Crop,
                modifier = Modifier.fillMaxSize().clip(RoundedCornerShape(size * 0.18f)),
            )
            entry.isDir -> FolderGlyph(Modifier.fillMaxSize())
            else -> SheetGlyph(entry, size)
        }
    }
}

@Composable
private fun FolderGlyph(modifier: Modifier) {
    val color = FileKind.FOLDER.color
    Canvas(modifier) {
        val w = this.size.width
        val h = this.size.height
        val top = h * 0.2f
        val tab = Path().apply {
            moveTo(w * 0.06f, top + h * 0.06f)
            quadraticTo(w * 0.06f, top, w * 0.12f, top)
            lineTo(w * 0.38f, top)
            lineTo(w * 0.46f, top + h * 0.09f)
            lineTo(w * 0.88f, top + h * 0.09f)
            quadraticTo(w * 0.94f, top + h * 0.09f, w * 0.94f, top + h * 0.15f)
            lineTo(w * 0.94f, top + h * 0.3f)
            lineTo(w * 0.06f, top + h * 0.3f)
            close()
        }
        drawPath(tab, color.copy(alpha = 0.55f))
        drawRoundRect(
            color,
            topLeft = Offset(w * 0.06f, top + h * 0.16f),
            size = GeometrySize(w * 0.88f, h * 0.56f),
            cornerRadius = CornerRadius(w * 0.07f),
        )
        drawRoundRect(
            Color.White.copy(alpha = 0.18f),
            topLeft = Offset(w * 0.06f, top + h * 0.16f),
            size = GeometrySize(w * 0.88f, h * 0.06f),
            cornerRadius = CornerRadius(w * 0.03f),
        )
    }
}

@Composable
private fun SheetGlyph(entry: FileEntry, size: Dp) {
    val color = entry.kind.color
    Box(Modifier.size(size), contentAlignment = Alignment.Center) {
        Canvas(Modifier.fillMaxSize()) {
            val w = this.size.width
            val h = this.size.height
            val left = w * 0.16f
            val right = w * 0.84f
            val fold = w * 0.2f
            val sheet = Path().apply {
                moveTo(left + w * 0.06f, h * 0.06f)
                lineTo(right - fold, h * 0.06f)
                lineTo(right, h * 0.06f + fold)
                lineTo(right, h * 0.88f)
                quadraticTo(right, h * 0.94f, right - w * 0.06f, h * 0.94f)
                lineTo(left + w * 0.06f, h * 0.94f)
                quadraticTo(left, h * 0.94f, left, h * 0.88f)
                lineTo(left, h * 0.12f)
                quadraticTo(left, h * 0.06f, left + w * 0.06f, h * 0.06f)
                close()
            }
            drawPath(sheet, color.copy(alpha = 0.16f))
            val corner = Path().apply {
                moveTo(right - fold, h * 0.06f)
                lineTo(right - fold, h * 0.06f + fold * 0.8f)
                quadraticTo(right - fold, h * 0.06f + fold, right - fold * 0.8f, h * 0.06f + fold)
                lineTo(right, h * 0.06f + fold)
                close()
            }
            drawPath(corner, color.copy(alpha = 0.45f))
        }
        val icon = kindGlyph(entry.kind)
        if (icon != null && size >= 40.dp) {
            Icon(icon, null, tint = color, modifier = Modifier.size(size * 0.34f).align(Alignment.Center))
        }
        val label = entry.extension.take(4).uppercase().ifEmpty { "•" }
        Text(
            label,
            color = Color(0xFF0E1014),
            fontSize = (size.value * 0.17f).coerceIn(7f, 13f).sp,
            fontWeight = FontWeight.Bold,
            maxLines = 1,
            modifier = Modifier
                .align(Alignment.BottomCenter)
                .offset(y = -size * 0.12f)
                .clip(RoundedCornerShape(3.dp))
                .background(color)
                .padding(horizontal = 3.dp),
        )
    }
}

private fun kindGlyph(kind: FileKind): ImageVector? = when (kind) {
    FileKind.IMAGE -> Glyphs.Image
    FileKind.VIDEO -> Glyphs.Movie
    FileKind.AUDIO -> Glyphs.Music
    FileKind.APK -> Glyphs.Android
    FileKind.ARCHIVE -> Glyphs.Archive
    else -> null
}

/** Превью файлов в памяти: декодируются в фоне, уменьшенными до размера значка. */
internal object Thumbnails {

    private val cache = object : LruCache<String, ImageBitmap>(48 * 1024 * 1024) {
        override fun sizeOf(key: String, value: ImageBitmap) = value.width * value.height * 4
    }

    // Не больше четырёх декодирований сразу: при быстрой прокрутке папки с фото иначе забивается память.
    @OptIn(ExperimentalCoroutinesApi::class)
    private val decoder = Dispatchers.IO.limitedParallelism(4)

    fun supports(kind: FileKind) = kind == FileKind.IMAGE || kind == FileKind.VIDEO || kind == FileKind.AUDIO || kind == FileKind.APK

    fun cached(entry: FileEntry, px: Int): ImageBitmap? = cache.get(key(entry, px))

    suspend fun load(context: Context, entry: FileEntry, px: Int): ImageBitmap? = withContext(decoder) {
        val bitmap = runCatching {
            when (entry.kind) {
                FileKind.IMAGE -> decodeImage(entry.path, px)
                FileKind.VIDEO -> videoFrame(entry.path, px)
                FileKind.AUDIO -> albumArt(entry.path, px)
                FileKind.APK -> apkIcon(context, entry.path, px)
                else -> null
            }
        }.getOrNull() ?: return@withContext null
        bitmap.asImageBitmap().also { cache.put(key(entry, px), it) }
    }

    private fun key(entry: FileEntry, px: Int) = "${entry.path}:${entry.modified}:$px"

    /** Картинка с прореживанием до размера значка и с поворотом по EXIF (фото с камеры). */
    fun decodeImage(path: String, px: Int): Bitmap? {
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(path, bounds)
        if (bounds.outWidth <= 0) return null
        var sample = 1
        while (bounds.outWidth / (sample * 2) >= px && bounds.outHeight / (sample * 2) >= px) sample *= 2
        val bitmap = BitmapFactory.decodeFile(path, BitmapFactory.Options().apply { inSampleSize = sample }) ?: return null
        val rotation = runCatching { ExifInterface(path).rotationDegrees }.getOrDefault(0)
        if (rotation == 0) return bitmap
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, Matrix().apply { postRotate(rotation.toFloat()) }, true)
    }

    private fun videoFrame(path: String, px: Int): Bitmap? =
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            ThumbnailUtils.createVideoThumbnail(File(path), Size(px, px), null)
        } else {
            @Suppress("DEPRECATION")
            ThumbnailUtils.createVideoThumbnail(path, MediaStore.Images.Thumbnails.MINI_KIND)
        }

    private fun albumArt(path: String, px: Int): Bitmap? {
        val retriever = MediaMetadataRetriever()
        return try {
            retriever.setDataSource(path)
            val bytes = retriever.embeddedPicture ?: return null
            val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, bounds)
            var sample = 1
            while (bounds.outWidth / (sample * 2) >= px) sample *= 2
            BitmapFactory.decodeByteArray(bytes, 0, bytes.size, BitmapFactory.Options().apply { inSampleSize = sample })
        } finally {
            retriever.release()
        }
    }

    /** Иконка APK: PackageManager читает её из архива, если указать путь к нему как к источнику. */
    private fun apkIcon(context: Context, path: String, px: Int): Bitmap? {
        val pm = context.packageManager
        val info = pm.getPackageArchiveInfo(path, 0)?.applicationInfo ?: return null
        info.sourceDir = path
        info.publicSourceDir = path
        return info.loadIcon(pm).toBitmap(px, px)
    }

    private val ExifInterface.rotationDegrees: Int
        get() = when (getAttributeInt(ExifInterface.TAG_ORIENTATION, ExifInterface.ORIENTATION_NORMAL)) {
            ExifInterface.ORIENTATION_ROTATE_90 -> 90
            ExifInterface.ORIENTATION_ROTATE_180 -> 180
            ExifInterface.ORIENTATION_ROTATE_270 -> 270
            else -> 0
        }
}

/**
 * Значки, которых нет в базовом наборе Material (`material-icons-core`). Контуры — из Material
 * Icons (Apache 2.0); подключать `material-icons-extended` ради десятка значков — лишние мегабайты в APK.
 */
internal object Glyphs {
    val Folder = glyph("M10,4H4c-1.1,0 -1.99,0.9 -1.99,2L2,18c0,1.1 0.9,2 2,2h16c1.1,0 2,-0.9 2,-2V8c0,-1.1 -0.9,-2 -2,-2h-8l-2,-2z")
    val NewFolder = glyph("M20,6h-8l-2,-2L4,4c-1.11,0 -1.99,0.89 -1.99,2L2,18c0,1.11 0.89,2 2,2h16c1.11,0 2,-0.89 2,-2L22,8c0,-1.11 -0.89,-2 -2,-2zM19,14h-3v3h-2v-3h-3v-2h3L14,9h2v3h3v2z")
    val Grid = glyph("M3,3v8h8V3H3zM9,9H5V5h4V9zM3,13v8h8v-8H3zM9,19H5v-4h4V19zM13,3v8h8V3H13zM19,9h-4V5h4V9zM13,13v8h8v-8H13zM19,19h-4v-4h4V19z")
    val Rows = glyph("M3,4h18v4H3zM3,10h18v4H3zM3,16h18v4H3z")
    val Copy = glyph("M16,1H4C2.9,1 2,1.9 2,3v14h2V3h12V1zM19,5H8C6.9,5 6,5.9 6,7v14c0,1.1 0.9,2 2,2h11c1.1,0 2,-0.9 2,-2V7C21,5.9 20.1,5 19,5zM19,21H8V7h11V21z")
    val Cut = glyph("M9.64,7.64c0.23,-0.5 0.36,-1.05 0.36,-1.64 0,-2.21 -1.79,-4 -4,-4S2,3.79 2,6s1.79,4 4,4c0.59,0 1.14,-0.13 1.64,-0.36L10,12l-2.36,2.36C7.14,14.13 6.59,14 6,14c-2.21,0 -4,1.79 -4,4s1.79,4 4,4 4,-1.79 4,-4c0,-0.59 -0.13,-1.14 -0.36,-1.64L12,14l7,7h3v-1L9.64,7.64zM6,8c-1.1,0 -2,-0.89 -2,-2s0.9,-2 2,-2 2,0.89 2,2 -0.9,2 -2,2zM6,20c-1.1,0 -2,-0.89 -2,-2s0.9,-2 2,-2 2,0.89 2,2 -0.9,2 -2,2zM19,3l-6,6 2,2 7,-7L22,3z")
    val Paste = glyph("M19,2h-4.18C14.4,0.84 13.3,0 12,0c-1.3,0 -2.4,0.84 -2.82,2L5,2c-1.1,0 -2,0.9 -2,2v16c0,1.1 0.9,2 2,2h14c1.1,0 2,-0.9 2,-2L21,4c0,-1.1 -0.9,-2 -2,-2zM12,2c0.55,0 1,0.45 1,1s-0.45,1 -1,1 -1,-0.45 -1,-1 0.45,-1 1,-1zM19,20L5,20L5,4h2v3h10L17,4h2v16z")
    val Sort = glyph("M3,18h6v-2H3v2zM3,6v2h18V6H3zM3,13h12v-2H3v2z")
    val Phone = glyph("M16,1H8C6.34,1 5,2.34 5,4v16c0,1.66 1.34,3 3,3h8c1.66,0 3,-1.34 3,-3V4c0,-1.66 -1.34,-3 -3,-3zM14,21h-4v-1h4v1zM17.25,18H6.75V4h10.5v14z")
    val SdCard = glyph("M18,2h-8L4.02,8 4,20c0,1.1 0.9,2 2,2h12c1.1,0 2,-0.9 2,-2V4c0,-1.1 -0.9,-2 -2,-2zM12,8h-2V4h2v4zM15,8h-2V4h2v4zM18,8h-2V4h2v4z")
    val Download = glyph("M19,9h-4V3H9v6H5l7,7 7,-7zM5,18v2h14v-2H5z")
    val Camera = glyph("M9,2L7.17,4H4c-1.1,0 -2,0.9 -2,2v12c0,1.1 0.9,2 2,2h16c1.1,0 2,-0.9 2,-2V6c0,-1.1 -0.9,-2 -2,-2h-3.17L15,2H9zM12,17c-2.76,0 -5,-2.24 -5,-5s2.24,-5 5,-5 5,2.24 5,5 -2.24,5 -5,5zM12,9a3,3 0,1 0,0 6a3,3 0,1 0,0 -6z")
    val Image = glyph("M21,19V5c0,-1.1 -0.9,-2 -2,-2H5c-1.1,0 -2,0.9 -2,2v14c0,1.1 0.9,2 2,2h14c1.1,0 2,-0.9 2,-2zM8.5,13.5l2.5,3.01L14.5,12l4.5,6H5l3.5,-4.5z")
    val Movie = glyph("M18,4l2,4h-3l-2,-4h-2l2,4h-3l-2,-4H8l2,4H7L5,4H4c-1.1,0 -1.99,0.9 -1.99,2L2,18c0,1.1 0.9,2 2,2h16c1.1,0 2,-0.9 2,-2V4h-4z")
    val Music = glyph("M12,3v10.55c-0.59,-0.34 -1.27,-0.55 -2,-0.55 -2.21,0 -4,1.79 -4,4s1.79,4 4,4 4,-1.79 4,-4V7h4V3h-6z")
    val Document = glyph("M14,2H6c-1.1,0 -1.99,0.9 -1.99,2L4,20c0,1.1 0.89,2 1.99,2H18c1.1,0 2,-0.9 2,-2V8l-6,-6zM16,18H8v-2h8v2zM16,14H8v-2h8v2zM13,9V3.5L18.5,9H13z")
    val Android = glyph("M17.6,9.48l1.84,-3.18c0.16,-0.31 0.04,-0.69 -0.26,-0.85 -0.29,-0.15 -0.65,-0.06 -0.83,0.22l-1.88,3.24c-2.86,-1.21 -6.08,-1.21 -8.94,0L5.65,5.67c-0.19,-0.29 -0.58,-0.38 -0.87,-0.2 -0.28,0.18 -0.37,0.54 -0.22,0.83L6.4,9.48C3.3,11.25 1.28,14.44 1,18h22c-0.28,-3.56 -2.3,-6.75 -5.4,-8.52zM7,15.25c-0.69,0 -1.25,-0.56 -1.25,-1.25s0.56,-1.25 1.25,-1.25 1.25,0.56 1.25,1.25 -0.56,1.25 -1.25,1.25zM17,15.25c-0.69,0 -1.25,-0.56 -1.25,-1.25s0.56,-1.25 1.25,-1.25 1.25,0.56 1.25,1.25 -0.56,1.25 -1.25,1.25z")
    val Archive = glyph("M20.54,5.23l-1.39,-1.68C18.88,3.21 18.47,3 18,3H6c-0.47,0 -0.88,0.21 -1.16,0.55L3.46,5.23C3.17,5.57 3,6.02 3,6.5V19c0,1.1 0.9,2 2,2h14c1.1,0 2,-0.9 2,-2V6.5c0,-0.48 -0.17,-0.93 -0.46,-1.27zM12,17.5L6.5,12H10v-2h4v2h3.5L12,17.5zM5.12,5l0.81,-1h12l0.94,1H5.12z")
    val Eye = glyph("M12,4.5C7,4.5 2.73,7.61 1,12c1.73,4.39 6,7.5 11,7.5s9.27,-3.11 11,-7.5c-1.73,-4.39 -6,-7.5 -11,-7.5zM12,17c-2.76,0 -5,-2.24 -5,-5s2.24,-5 5,-5 5,2.24 5,5 -2.24,5 -5,5zM12,9c-1.66,0 -3,1.34 -3,3s1.34,3 3,3 3,-1.34 3,-3 -1.34,-3 -3,-3z")
    val SelectAll = glyph("M3,5h2V3c-1.1,0 -2,0.9 -2,2zM3,13h2v-2H3v2zM7,21h2v-2H7v2zM3,9h2V7H3v2zM13,3h-2v2h2V3zM19,3v2h2c0,-1.1 -0.9,-2 -2,-2zM5,21v-2H3c0,1.1 0.9,2 2,2zM3,17h2v-2H3v2zM9,3H7v2h2V3zM11,21h2v-2h-2v2zM19,13h2v-2h-2v2zM19,21c1.1,0 2,-0.9 2,-2h-2v2zM19,9h2V7h-2v2zM19,17h2v-2h-2v2zM15,21h2v-2h-2v2zM15,5h2V3h-2v2zM7,17h10V7H7v10zM9,9h6v6H9V9z")

    private fun glyph(path: String): ImageVector =
        ImageVector.Builder(defaultWidth = 24.dp, defaultHeight = 24.dp, viewportWidth = 24f, viewportHeight = 24f)
            .addPath(addPathNodes(path), fill = SolidColor(Color.Black))
            .build()
}
