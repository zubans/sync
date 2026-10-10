package com.example.contactsync.launcher

import android.accessibilityservice.AccessibilityService
import android.graphics.PixelFormat
import android.graphics.drawable.GradientDrawable
import android.view.Gravity
import android.view.View
import android.view.WindowManager
import android.view.accessibility.AccessibilityEvent
import android.view.accessibility.AccessibilityWindowInfo
import android.widget.ImageButton
import android.widget.LinearLayout
import com.example.contactsync.R
import kotlinx.coroutines.MainScope
import kotlinx.coroutines.cancel
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.launch

/**
 * Своя панель навигации. Системная навигация переключена на жесты, и панель задач Launcher3
 * свёрнута в полоску; вместо неё служба рисует тёмную капсулу «назад / домой / недавние»
 * в правом нижнем углу поверх приложений. На главном экране те же кнопки стоят в ряду дока,
 * поэтому капсула там не нужна. Прячется, когда открыты клавиатура или шторка.
 */
class NavBarService : AccessibilityService() {

    private var bar: View? = null
    private val scope = MainScope()

    override fun onServiceConnected() {
        instance = this
        val view = createBar()
        getSystemService(WindowManager::class.java).addView(view, layoutParams())
        bar = view
        scope.launch { launcherVisible.collect { update() } }
    }

    override fun onAccessibilityEvent(event: AccessibilityEvent) = update()

    override fun onInterrupt() {}

    override fun onDestroy() {
        bar?.let { getSystemService(WindowManager::class.java).removeView(it) }
        bar = null
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
    private fun overlaysShown(): Boolean {
        val screenHeight = resources.displayMetrics.heightPixels
        val rect = android.graphics.Rect()
        return windows.any { window ->
            when (window.type) {
                AccessibilityWindowInfo.TYPE_INPUT_METHOD -> true
                AccessibilityWindowInfo.TYPE_SYSTEM -> {
                    window.getBoundsInScreen(rect)
                    rect.height() > screenHeight / 2
                }
                else -> false
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

        val isEnabled: Boolean get() = instance != null

        /** Нажать системную кнопку; false — служба выключена в настройках специальных возможностей. */
        fun perform(action: Int): Boolean = instance?.performGlobalAction(action) ?: false
    }
}
