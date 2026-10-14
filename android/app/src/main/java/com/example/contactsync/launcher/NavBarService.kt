package com.example.contactsync.launcher

import android.accessibilityservice.AccessibilityService
import android.net.Uri
import android.graphics.PixelFormat
import android.graphics.drawable.GradientDrawable
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityWindowInfo
import android.widget.ImageButton
import android.widget.LinearLayout
import com.example.contactsync.App
import com.example.contactsync.R
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Своя панель навигации. Системная навигация переключена на жесты, и панель задач Launcher3
 * свёрнута в полоску; вместо неё служба рисует тёмную капсулу «назад / домой / недавние»
 * в правом нижнем углу поверх приложений. На главном экране те же кнопки стоят в ряду дока,
 * поэтому капсула там не нужна. Прячется, когда открыты клавиатура или шторка.
 *
 * Ещё служба держит лампочку VPN в строке состояния — над всеми приложениями, как значки системы.
 * Значок уведомления для этого не годится: строка состояния красит его в один цвет.
 */
class NavBarService : AccessibilityService() {

    private var bar: View? = null
    private var lamp: View? = null
    private var placeJob: Job? = null
    private val scope = MainScope()

    override fun onServiceConnected() {
        instance = this
        val windowManager = getSystemService(WindowManager::class.java)
        val view = createBar()
        windowManager.addView(view, layoutParams())
        bar = view
        scope.launch { launcherVisible.collect { update() } }

        val dot = View(this).apply {
            background = GradientDrawable().apply { shape = GradientDrawable.OVAL }
            visibility = View.GONE
        }
        windowManager.addView(dot, lampLayoutParams())
        lamp = dot
        placeLampSoon()
        scope.launch { watchVpn(dot) }
    }

    /**
     * Цвет лампочки — как у лампочки на главном экране ([VpnLamp]): зелёная — туннель есть и KB отвечает,
     * жёлтая — туннель есть, KB нет, красная — туннеля нет, серая — VPN выключен из меню лампочки.
     */
    private suspend fun watchVpn(dot: View) {
        val control = VpnControl(filesDir)
        while (true) {
            val kb = Uri.parse((application as App).kbSession.baseUrl)
            val state = VpnLamp.state(VpnLamp.probe(kb.host.orEmpty(), kb.port.takeIf { it > 0 } ?: 80))
            val wanted = withContext(Dispatchers.IO) { control.wanted() }
            val color = when {
                wanted == false && state == VpnState.DOWN -> 0xFF8B93A1.toInt()
                state == VpnState.UP -> 0xFF4CC38A.toInt()
                state == VpnState.DEGRADED -> 0xFFEF9F27.toInt()
                else -> 0xFFF2726F.toInt()
            }
            (dot.background as GradientDrawable).setColor(color)
            dot.contentDescription = state.title
            placeLamp()
            delay(LAMP_TICK_MS)
        }
    }

    /**
     * Точка в строке состояния. Место задаётся в [placeLamp]; нажатия проходят сквозь точку — шторка
     * открывается как обычно.
     */
    private fun lampLayoutParams(): WindowManager.LayoutParams {
        val size = (10 * resources.displayMetrics.density).toInt()
        return WindowManager.LayoutParams(
            size,
            size,
            WindowManager.LayoutParams.TYPE_ACCESSIBILITY_OVERLAY,
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
                WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE or
                WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
                WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT,
        ).apply {
            gravity = Gravity.TOP or Gravity.START
            layoutInDisplayCutoutMode = WindowManager.LayoutParams.LAYOUT_IN_DISPLAY_CUTOUT_MODE_ALWAYS
        }
    }

    /**
     * Ставит точку левее системных значков (Wi-Fi, сеть, батарея, ключ и т.п.) и по центру строки.
     * Набор значков меняется, поэтому место берётся из окна системной оболочки, а не задаётся отступом.
     * Шторка или экран блокировки открыты, или значков не видно (строка скрыта) — точку прячем.
     */
    private fun placeLamp() {
        val dot = lamp ?: return
        val params = dot.layoutParams as? WindowManager.LayoutParams ?: return
        val icons = if (systemPanelShown()) null else systemIconsBounds()
        if (icons == null) {
            dot.visibility = View.GONE
            return
        }
        val x = icons.left - (LAMP_GAP_DP * resources.displayMetrics.density).toInt() - params.width
        val y = icons.centerY() - params.height / 2
        if (params.x != x || params.y != y) {
            params.x = x
            params.y = y
            getSystemService(WindowManager::class.java).updateViewLayout(dot, params)
        }
        dot.visibility = View.VISIBLE
    }

    private fun systemIconsBounds(): android.graphics.Rect? {
        val rect = android.graphics.Rect()
        for (window in windows) {
            if (window.type != AccessibilityWindowInfo.TYPE_SYSTEM) continue
            val node = window.root?.findAccessibilityNodeInfosByViewId(SYSTEM_ICONS_ID)?.firstOrNull { it.isVisibleToUser } ?: continue
            node.getBoundsInScreen(rect)
            if (!rect.isEmpty) return rect
        }
        return null
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent) {
        if (event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED) update()
        // Значки в строке состояния появляются и пропадают без смены окон — переставляем точку и по ним.
        if (event.eventType != AccessibilityEvent.TYPE_WINDOW_CONTENT_CHANGED || event.packageName == SYSTEM_UI) {
            placeLampSoon()
        }
    }

    /** Событий от системной оболочки бывает много подряд — переставляем точку один раз после них. */
    private fun placeLampSoon() {
        placeJob?.cancel()
        placeJob = scope.launch {
            delay(LAMP_PLACE_DELAY_MS)
            placeLamp()
        }
    }

    override fun onInterrupt() {}

    override fun onDestroy() {
        bar?.let { getSystemService(WindowManager::class.java).removeView(it) }
        lamp?.let { getSystemService(WindowManager::class.java).removeView(it) }
        bar = null
        lamp = null
        instance = null
        scope.cancel()
        super.onDestroy()
    }

    private fun update() {
        val view = bar ?: return
        val hide = launcherVisible.value || overlaysShown()
        view.visibility = if (hide) View.GONE else View.VISIBLE
    }

    /** Клавиатура или развёрнутое системное окно (шторка, экран блокировки) — кнопки им мешают. */
    private fun overlaysShown(): Boolean =
        windows.any { it.type == AccessibilityWindowInfo.TYPE_INPUT_METHOD } || systemPanelShown()

    /** Развёрнутое системное окно — шторка или экран блокировки. */
    private fun systemPanelShown(): Boolean {
        val screenHeight = resources.displayMetrics.heightPixels
        val rect = android.graphics.Rect()
        return windows.any { window ->
            window.type == AccessibilityWindowInfo.TYPE_SYSTEM && run {
                window.getBoundsInScreen(rect)
                rect.height() > screenHeight / 2
            }
        }
    }

    private fun createBar(): View {
        val density = resources.displayMetrics.density
        return LinearLayout(this).apply {
            orientation = LinearLayout.HORIZONTAL
            setPadding((12 * density).toInt(), 0, (12 * density).toInt(), 0)
            background = GradientDrawable().apply {
                setColor(0xF2171A20.toInt())
                cornerRadius = 18 * density
            }
            listOf(
                R.drawable.nav_back to GLOBAL_ACTION_BACK,
                R.drawable.nav_home to GLOBAL_ACTION_HOME,
                R.drawable.nav_recents to GLOBAL_ACTION_RECENTS,
            ).forEach { (icon, action) ->
                addView(
                    ImageButton(context).apply {
                        setImageResource(icon)
                        background = null
                        contentDescription = when (action) {
                            GLOBAL_ACTION_BACK -> "Назад"
                            GLOBAL_ACTION_HOME -> "Домой"
                            else -> "Недавние"
                        }
                        setOnClickListener { performGlobalAction(action) }
                    },
                    LinearLayout.LayoutParams((64 * density).toInt(), LinearLayout.LayoutParams.MATCH_PARENT),
                )
            }
        }
    }

    private fun layoutParams() = WindowManager.LayoutParams(
        WindowManager.LayoutParams.WRAP_CONTENT,
        (48 * resources.displayMetrics.density).toInt(),
        WindowManager.LayoutParams.TYPE_ACCESSIBILITY_OVERLAY,
        WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or
            WindowManager.LayoutParams.FLAG_LAYOUT_IN_SCREEN or
            WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
        PixelFormat.TRANSLUCENT,
    ).apply {
        gravity = Gravity.BOTTOM or Gravity.END
        x = (16 * resources.displayMetrics.density).toInt()
        y = (8 * resources.displayMetrics.density).toInt()
    }

    companion object {
        @Volatile
        private var instance: NavBarService? = null

        /** Главный экран на переднем плане — его док сам показывает кнопки. */
        val launcherVisible = MutableStateFlow(false)

        /** Лампочку VPN в строке состояния проверяем как на главном экране — раз в 10 секунд. */
        private const val LAMP_TICK_MS = 10_000L

        /** Зазор между лампочкой и системными значками. */
        private const val LAMP_GAP_DP = 6

        /** Пауза после событий системной оболочки, прежде чем переставить лампочку. */
        private const val LAMP_PLACE_DELAY_MS = 300L

        private const val SYSTEM_UI = "com.android.systemui"

        /** Блок значков справа в строке состояния: Wi-Fi, сеть, батарея и прочие. */
        private const val SYSTEM_ICONS_ID = "$SYSTEM_UI:id/system_icons"

        val isEnabled: Boolean get() = instance != null

        /** Нажать системную кнопку; false — служба выключена в настройках специальных возможностей. */
        fun perform(action: Int): Boolean = instance?.performGlobalAction(action) ?: false
    }
}
