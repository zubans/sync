#!/usr/bin/env bash
set -euo pipefail

# Установщик серверной части Argon4 Tunnel на Ubuntu.
# Собирает бинарник (или копирует готовый), создаёт конфиг /etc/argon-tunnel/server.env
# и регистрирует systemd-сервис argon-tunnel.service.

if [[ "${EUID}" -ne 0 ]]; then
    echo "Запустите скрипт с правами root: sudo $0" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> Сборка argon-tunnel-server..."
cd "${SCRIPT_DIR}"
go build -trimpath -ldflags="-s -w" -o /usr/local/bin/argon-tunnel-server ./cmd/argon-tunnel-server
chmod 0755 /usr/local/bin/argon-tunnel-server

mkdir -p /etc/argon-tunnel
if [[ ! -f /etc/argon-tunnel/server.env ]]; then
    cp "${SCRIPT_DIR}/ubuntu/server.env.example" /etc/argon-tunnel/server.env
    chmod 0600 /etc/argon-tunnel/server.env
    echo "==> Создан /etc/argon-tunnel/server.env — укажите в нём ARGON_MASTER_PASSWORD и интерфейсы."
fi

cp "${SCRIPT_DIR}/ubuntu/argon-tunnel.service" /etc/systemd/system/argon-tunnel.service
systemctl daemon-reload
systemctl enable argon-tunnel.service

echo "==> Готово. Отредактируйте /etc/argon-tunnel/server.env и запустите: sudo systemctl restart argon-tunnel"
