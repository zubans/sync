#!/bin/sh
# Сборка build/CEP.app: приложение + движок cep-engine рядом с ним.
set -eu
cd "$(dirname "$0")/.."

swift build -c release
BIN=$(swift build -c release --show-bin-path)
(cd argon4 && CGO_ENABLED=0 go build -trimpath -ldflags='-s -w' -o "$BIN/cep-argon4" ./cmd/cep-argon4)
APP=build/CEP.app

rm -rf "$APP"
mkdir -p "$APP/Contents/MacOS" "$APP/Contents/Resources"
cp "$BIN/CEP" "$BIN/cep-engine" "$APP/Contents/MacOS/"

cat > "$APP/Contents/Info.plist" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleName</key><string>CEP</string>
    <key>CFBundleDisplayName</key><string>CEP — цепочки VPN</string>
    <key>CFBundleIdentifier</key><string>local.cep.app</string>
    <key>CFBundleExecutable</key><string>CEP</string>
    <key>CFBundlePackageType</key><string>APPL</string>
    <key>CFBundleShortVersionString</key><string>0.1.0</string>
    <key>CFBundleVersion</key><string>1</string>
    <key>LSMinimumSystemVersion</key><string>14.0</string>
    <key>NSHighResolutionCapable</key><true/>
    <key>NSAppleEventsUsageDescription</key><string>Запуск движка цепочки с правами администратора.</string>
</dict>
</plist>
PLIST

# VPN-программы и их библиотеки — внутрь .app; без этого нужен Homebrew на каждом Mac
python3 scripts/bundle-tools.py "$APP"
cp "$BIN/cep-argon4" "$APP/Contents/Helpers/"

codesign --force --sign - "$APP/Contents/Helpers/cep-argon4"
codesign --force --sign - "$APP/Contents/MacOS/cep-engine"
codesign --force --sign - "$APP"
echo "Готово: $APP"
