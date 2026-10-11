#!/system/bin/sh
# Выполняется от root один раз после загрузки. Журнал — /data/local/boot.log.
/data/local/oc/occtl.sh start
# Служба включения и выключения VPN с главного экрана (см. vpnctl.sh).
nohup /data/local/oc/vpnctl.sh > /dev/null 2>&1 &
# Сторож Wi-Fi (см. wifiwatch.sh).
nohup /data/local/wifiwatch.sh > /dev/null 2>&1 &
# Запись созвона: согласие на захват звука приложений без окна (сбрасывается при обновлении системного APK).
appops set com.example.contactsync PROJECT_MEDIA allow
for f in /data/local/tmp/sounds/ui/*.ogg; do mount -o bind "$f" "/product/media/audio/ui/$(basename "$f")"; done
# Плавность: в GSI регулятор MediaTek не поднимает частоту GPU и не ускоряет активное приложение.
# GPU не ниже 698 МГц (ступень 4 из 16), активному приложению — ускорение планировщика.
echo 4 > /sys/kernel/ged/hal/custom_boost_gpu_freq
echo 20 > /dev/stune/top-app/schedtune.boost
echo 1 > /dev/stune/top-app/schedtune.prefer_idle
# Интерфейс рисуется через Vulkan: меньше работы процессора на кадр, чем с OpenGL.
# Свойство читается при старте приложения, поэтому стоит до перезапуска системной оболочки.
setprop debug.hwui.renderer skiavk
pkill -f com.android.systemui
