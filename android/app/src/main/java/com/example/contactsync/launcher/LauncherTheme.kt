package com.example.contactsync.launcher

import androidx.compose.material3.darkColorScheme
import androidx.compose.ui.graphics.Color
import java.util.Locale

/* Палитра главного экрана: тёмная, общая для дашборда, виджетов и экрана KB. */

internal val Background = Color(0xFF0E1014)
internal val CardColor = Color(0xFF171A20)
internal val CardRaised = Color(0xFF20242C)
internal val TextMain = Color(0xFFECEEF2)
internal val TextMuted = Color(0xFF8B93A1)
internal val Accent = Color(0xFF8AB4F8)
internal val Up = Color(0xFF4CC38A)
internal val Down = Color(0xFFF2726F)
internal val Amber = Color(0xFFEF9F27)
internal val YandexRed = Color(0xFFFC3F1D)

internal val RU = Locale.forLanguageTag("ru")

internal val LauncherColors = darkColorScheme(
    primary = Accent,
    background = Background,
    surface = CardColor,
    surfaceContainerHigh = CardRaised,
    onSurface = TextMain,
    onBackground = TextMain,
)
