#!/system/bin/sh
# Включение и выключение VPN с главного экрана. Приложению root не выдаётся, поэтому оно пишет
# желаемое состояние (on/off) в свой файл vpn-wanted, а эта служба под root применяет его через
# occtl.sh и раз в 2 с обновляет время изменения vpn-watch — так приложение видит, что управление работает.
# Ставится в /data/local/oc/vpnctl.sh, запускается из /data/local/boot.sh.
# В цикле только встроенные команды оболочки и sleep: внешние cat, date и т.п. каждые 2 с
# держали около 5% ядра.
D=/data/local/oc
A=/data/data/com.example.contactsync/files
if [ -f $D/vpnctl.pid ] && kill -0 "$(cat $D/vpnctl.pid)" 2>/dev/null; then echo "уже запущен"; exit 0; fi
echo $$ > $D/vpnctl.pid
last=
ready=
while :; do
  w=
  [ -f $A/vpn-wanted ] && read -r w < $A/vpn-wanted
  if [ -n "$w" ] && [ "$w" != "$last" ]; then
    echo "$(date '+%F %T') $w" >> $D/vpnctl.log
    case "$w" in
      on) $D/occtl.sh start ;;
      off) $D/occtl.sh stop ;;
    esac >> $D/vpnctl.log 2>&1
    last=$w
  fi
  if [ -d $A ]; then
    # Файл создаётся один раз читаемым для приложения, дальше только обновляется время изменения.
    [ -n "$ready" ] || { : > $A/vpn-watch && chmod 644 $A/vpn-watch && ready=1; }
    : > $A/vpn-watch
  fi
  sleep 2
done
