#!/bin/bash
# Собирает движок CEP (Go + sing-box) в android/app/libs/cepcore.aar через gomobile.
# Нужны Go, Android SDK с NDK и gomobile/gobind (go install golang.org/x/mobile/cmd/gomobile@latest golang.org/x/mobile/cmd/gobind@latest).
# Локально — make cep-engine, на сервере — внутри docker/android-builder (make release).
set -euo pipefail
cd "$(dirname "$0")"

if [ -z "${ANDROID_HOME:-}" ]; then
    ANDROID_HOME="$(sed -n 's/^sdk.dir=//p' ../local.properties 2>/dev/null)"
    export ANDROID_HOME
fi
if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    ANDROID_NDK_HOME="$(ls -d "$ANDROID_HOME"/ndk/* | sort -V | tail -1)"
    export ANDROID_NDK_HOME
fi
export PATH="$PATH:$(go env GOPATH)/bin"

# Протоколы sing-box, нужные цепочкам: QUIC (Hysteria), uTLS и Reality (VLESS), OpenConnect, OpenVPN, gVisor (TUN).
TAGS="with_gvisor,with_quic,with_utls,with_openconnect,with_openvpn"
mkdir -p ../app/libs
# Только arm64: телефоны и планшеты семьи — arm64, а движок весит ~50 МБ на архитектуру.
# max-page-size=16384 — выравнивание под страницы памяти 16 КБ (Android 15+), иначе система предупреждает при запуске.
gomobile bind \
    -target=android/arm64 \
    -androidapi 26 \
    -tags "$TAGS" \
    -trimpath -ldflags "-s -w -buildid= -extldflags=-Wl,-z,max-page-size=16384" \
    -o ../app/libs/cepcore.aar .
rm -f ../app/libs/cepcore-sources.jar
