#!/system/bin/sh
# Сторож Wi-Fi: после выключения экрана Wi-Fi планшета иногда «висит» — подключён, но связи нет.
# Раз в 30 с проверяет, отвечает ли шлюз текущей сети (берётся из таблицы маршрутов wlan0, адрес
# не зашит — у разных роутеров он разный). Три неудачи подряд — Wi-Fi перезапускается, не чаще
# раза в 5 минут. Wi-Fi, выключенный пользователем, не трогает.
# Ставится в /data/local/wifiwatch.sh, запускается из /data/local/boot.sh. Журнал — /data/local/wifiwatch.log.
L=/data/local/wifiwatch.log
P=/data/local/wifiwatch.pid
# Номер из pid-файла после перезагрузки может достаться чужому процессу, поэтому сверяем и командную строку.
if [ -f $P ] && grep -q wifiwatch.sh "/proc/$(cat $P)/cmdline" 2>/dev/null; then echo "уже запущен"; exit 0; fi
echo $$ > $P
fails=0
last_restart=0
now=0
while :; do
  sleep 30
  now=$((now + 30))
  state=
  [ -f /sys/class/net/wlan0/operstate ] && read -r state < /sys/class/net/wlan0/operstate
  ok=
  if [ "$state" = up ]; then
    gw=$(ip -4 route show table wlan0 2>/dev/null | sed -n 's/^default via \([0-9.]*\).*/\1/p')
    [ -n "$gw" ] && ping -c 1 -W 3 -I wlan0 "$gw" > /dev/null 2>&1 && ok=1
  elif [ "$(cmd wifi status 2>/dev/null | head -1)" != "Wifi is enabled" ]; then
    # Wi-Fi выключен пользователем — это не сбой.
    ok=1
  fi
  if [ -n "$ok" ]; then fails=0; continue; fi
  fails=$((fails + 1))
  if [ $fails -ge 3 ] && [ $((now - last_restart)) -ge 300 ]; then
    echo "$(date '+%F %T') нет связи (wlan0: ${state:-нет}, шлюз: ${gw:-нет}) — перезапуск Wi-Fi" >> $L
    cmd wifi set-wifi-enabled disabled
    sleep 3
    cmd wifi set-wifi-enabled enabled
    last_restart=$now
    fails=0
  fi
done
