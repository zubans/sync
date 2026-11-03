#!/bin/sh
# Образ build/CEP.dmg для установки на другой Mac: внутри CEP.app и ярлык на /Applications.
set -eu
cd "$(dirname "$0")/.."

./scripts/build-app.sh
STAGE=build/dmg
rm -rf "$STAGE" build/CEP.dmg
mkdir -p "$STAGE"
cp -R build/CEP.app "$STAGE/"
ln -s /Applications "$STAGE/Applications"
hdiutil create -volname CEP -srcfolder "$STAGE" -ov -format UDZO build/CEP.dmg >/dev/null
rm -rf "$STAGE"
echo "Готово: build/CEP.dmg"
