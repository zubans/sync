#!/bin/sh
# Установка внешних программ для всех протоколов.
#   brew: sing-box, xray, openvpn, openconnect, sstp-client
#   из исходников: amneziawg-go, awg (amnezia-vpn)
set -eu

PREFIX=$(brew --prefix)
brew install sing-box xray openvpn openconnect sstp-client

WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

if ! command -v go >/dev/null; then
    brew install go
fi

echo "== amneziawg-go"
git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-go "$WORK/amneziawg-go"
make -C "$WORK/amneziawg-go"
install -m 755 "$WORK/amneziawg-go/amneziawg-go" "$PREFIX/bin/amneziawg-go"

echo "== awg (amneziawg-tools)"
git clone --depth 1 https://github.com/amnezia-vpn/amneziawg-tools "$WORK/amneziawg-tools"
make -C "$WORK/amneziawg-tools/src"
if [ -x "$WORK/amneziawg-tools/src/awg" ]; then
    install -m 755 "$WORK/amneziawg-tools/src/awg" "$PREFIX/bin/awg"
else
    install -m 755 "$WORK/amneziawg-tools/src/wg" "$PREFIX/bin/awg"
fi

echo "Готово. Проверьте раздел «Компоненты» в CEP."
