#!/usr/bin/env bash
set -euo pipefail

# Установщик серверной части Argon4 Tunnel на Ubuntu.
# Собирает бинарник (или копирует готовый), создаёт конфиг /etc/argon-tunnel/server.env
# и регистрирует systemd-сервис argon-tunnel.service.
#
# Если задан SYNC_VPN_RUNTIME_DIR (каталог runtime панели sync на этом хосте, например
# /opt/sync/server/var/vpn-runtime), дополнительно ставит скрипт применения sync-vpn-apply,
# шаблон argon-tunnel@.service и юнит sync-vpn-apply.path: узел будет сам поднимать процессы
# Argon4, DNAT и открывать порты при каждом сохранении в админке.

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите скрипт с правами root: sudo $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BIN_PATH="/usr/local/bin/argon-tunnel-server"
GO_IMAGE="${GO_IMAGE:-golang:1.27-alpine}"

# Если бинарник уже собран в tunnel-server (например, через docker в make build-tunnel), копируем его.
# Иначе пробуем собрать локально установленным go, а при его отсутствии — в docker-контейнере.
if [[ -f "${SCRIPT_DIR}/argon-tunnel-server" ]]; then
    echo "==> Используем ранее собранный бинарник ${SCRIPT_DIR}/argon-tunnel-server..."
    cp "${SCRIPT_DIR}/argon-tunnel-server" "${BIN_PATH}"
elif command -v go >/dev/null 2>&1; then
    echo "==> Сборка argon-tunnel-server через локальный go в ${SCRIPT_DIR}..."
    (
        cd "${SCRIPT_DIR}"
        CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o "${BIN_PATH}" ./cmd/argon-tunnel-server
    )
elif command -v docker >/dev/null 2>&1; then
    echo "==> Сборка argon-tunnel-server в Docker (${GO_IMAGE})..."
    docker run --rm -v "${SCRIPT_DIR}:/src" -w /src -e CGO_ENABLED=0 "${GO_IMAGE}" \
        go build -trimpath -ldflags="-s -w" -o /src/argon-tunnel-server ./cmd/argon-tunnel-server
    cp "${SCRIPT_DIR}/argon-tunnel-server" "${BIN_PATH}"
else
    echo "Ошибка: не найден ни локальный 'go', ни 'docker' для сборки ${BIN_PATH}" >&2
    exit 1
fi
chmod 0755 "${BIN_PATH}"

mkdir -p /etc/argon-tunnel
if [[ ! -f /etc/argon-tunnel/server.env ]]; then
    cp "${SCRIPT_DIR}/ubuntu/server.env.example" /etc/argon-tunnel/server.env
    chmod 0600 /etc/argon-tunnel/server.env
    echo "==> Создан /etc/argon-tunnel/server.env — укажите в нём ARGON_MASTER_PASSWORD и интерфейсы."
fi

cp "${SCRIPT_DIR}/ubuntu/argon-tunnel.service" /etc/systemd/system/argon-tunnel.service
systemctl daemon-reload
systemctl enable argon-tunnel.service

cp "${SCRIPT_DIR}/ubuntu/argon-tunnel@.service" /etc/systemd/system/argon-tunnel@.service
install -m 0755 "${SCRIPT_DIR}/ubuntu/apply-runtime.sh" /usr/local/sbin/sync-vpn-apply

if [[ -n "${SYNC_VPN_RUNTIME_DIR:-}" ]]; then
    for unit in sync-vpn-apply.service sync-vpn-apply.path; do
        sed "s#@VPN_RUNTIME_DIR@#${SYNC_VPN_RUNTIME_DIR}#g" "${SCRIPT_DIR}/ubuntu/${unit}" > "/etc/systemd/system/${unit}"
    done
    systemctl daemon-reload
    # Узлом управляет админка: одиночный argon-tunnel.service не нужен, иначе он займёт порт 51820.
    systemctl disable --now argon-tunnel.service 2>/dev/null || true
    systemctl enable --now sync-vpn-apply.path
    echo "==> Узел управляется панелью sync: изменения из ${SYNC_VPN_RUNTIME_DIR} применяются автоматически."
    echo "    Применить вручную: sudo VPN_RUNTIME_DIR=${SYNC_VPN_RUNTIME_DIR} sync-vpn-apply"
    exit 0
fi

systemctl daemon-reload
echo "==> Готово. Отредактируйте /etc/argon-tunnel/server.env и запустите: sudo systemctl restart argon-tunnel"
