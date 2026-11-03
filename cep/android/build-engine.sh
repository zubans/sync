#!/bin/bash
# Собирает движок (engine/, Go + sing-box) в app/libs/cepcore.aar через gomobile.
# Нужны Go, Android SDK с NDK и gomobile: go install golang.org/x/mobile/cmd/gomobile@latest golang.org/x/mobile/cmd/gobind@latest
set -euo pipefail
cd "$(dirname "$0")"

export ANDROID_HOME="${ANDROID_HOME:-/opt/homebrew/share/android-commandlinetools}"
if [ -z "${ANDROID_NDK_HOME:-}" ]; then
    ANDROID_NDK_HOME="$(ls -d "$ANDROID_HOME"/ndk/* | sort -V | tail -1)"
    export ANDROID_NDK_HOME
fi
export PATH="$PATH:$(go env GOPATH)/bin"

# max-page-size=16384 — выравнивание под страницы памяти 16 КБ (Android 15+), иначе система предупреждает при запуске
TAGS="with_gvisor,with_quic,with_utls,with_openconnect,with_openvpn"
mkdir -p app/libs
cd engine
gomobile bind \
    -target=android/arm64,android/amd64 \
    -androidapi 26 \
    -tags "$TAGS" \
    -trimpath -ldflags "-s -w -buildid= -extldflags=-Wl,-z,max-page-size=16384" \
    -o ../app/libs/cepcore.aar .
