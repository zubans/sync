#!/usr/bin/env bash
set -euo pipefail

ACTION="${1:-}"
INSTALL_DIR="${CEP_SERVICE_INSTALL_DIR:-/opt/cep}"
CONFIG_DIR="${CEP_SERVICE_CONFIG_DIR:-/etc/cep}"
PROFILES="${CONFIG_DIR}/profiles.json"
CHAIN_FILE="${CONFIG_DIR}/chain-id"
UNIT_PATH="${CEP_SERVICE_UNIT_PATH:-/etc/systemd/system/cep-chain.service}"
BUILD_DIR="${CEP_BUILD_DIR:-}"
CLI="${INSTALL_DIR}/cep-cli"
temporary_unit=""

cleanup() {
    if [[ -n "${temporary_unit}" ]]; then
        rm -f -- "${temporary_unit}"
    fi
}
trap cleanup EXIT

require_linux() {
    if [[ "$(uname -s)" != "Linux" ]]; then
        echo "Управление cep-chain.service поддерживается только на Linux" >&2
        exit 1
    fi
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "Не найден systemctl: нужна Linux-система с systemd" >&2
        exit 1
    fi
}

run_root() {
    if [[ "${EUID}" -eq 0 ]]; then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$@"
    else
        echo "Для управления системным сервисом нужен root или sudo" >&2
        exit 1
    fi
}

read_chain_id() {
    if ! run_root test -s "${CHAIN_FILE}"; then
        return 1
    fi
    local chain_id
    chain_id="$(run_root cat "${CHAIN_FILE}" | tr -d '[:space:]')"
    if [[ ! "${chain_id}" =~ ^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$ ]]; then
        echo "В ${CHAIN_FILE} должен быть UUID цепочки; повторите make configure-cep-linux" >&2
        return 1
    fi
    printf '%s\n' "${chain_id}"
}

configure_profiles() {
    echo "==> Настройка профилей и цепочки"
    run_root "${CLI}" wizard -profiles "${PROFILES}"
    run_root "${CLI}" select -profiles "${PROFILES}" -output "${CHAIN_FILE}"
    local chain_id
    chain_id="$(read_chain_id)"
    run_root chmod 0600 "${PROFILES}" "${CHAIN_FILE}"
    echo "==> Проверка синтаксиса и прямой доступности узлов"
    run_root "${CLI}" check -profiles "${PROFILES}" -chain "${chain_id}"
}

write_unit() {
    local chain_id
    chain_id="$(read_chain_id)"
    temporary_unit="$(mktemp)"
    cat > "${temporary_unit}" <<EOF
[Unit]
Description=CEP VPN chain
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
Environment=CEP_ARGON4=${INSTALL_DIR}/cep-argon4
ExecStart=${CLI} up -profiles ${PROFILES} -chain ${chain_id}
Restart=on-failure
RestartSec=5s
TimeoutStopSec=45s

[Install]
WantedBy=multi-user.target
EOF
    run_root install -m 0644 "${temporary_unit}" "${UNIT_PATH}"
    run_root systemctl daemon-reload
}

start_service() {
    run_root systemctl start cep-chain.service
    sleep 3
    if ! run_root systemctl is-active --quiet cep-chain.service; then
        echo "Сервис не удержался в активном состоянии. Последние сообщения:" >&2
        run_root systemctl status cep-chain.service --no-pager -l || true
        run_root journalctl -u cep-chain.service -n 60 --no-pager || true
        return 1
    fi
    echo "Сервис cep-chain.service активен."
}

case "${ACTION}" in
    install)
        require_linux
        if [[ -z "${BUILD_DIR}" || ! -x "${BUILD_DIR}/cep-cli" || ! -x "${BUILD_DIR}/cep-argon4" ]]; then
            echo "Не найдены собранные бинарники в CEP_BUILD_DIR=${BUILD_DIR:-не задан}; запускайте через make install-cep-linux" >&2
            exit 1
        fi
        run_root install -d -m 0755 "${INSTALL_DIR}"
        run_root install -d -m 0700 "${CONFIG_DIR}"
        run_root install -m 0755 "${BUILD_DIR}/cep-cli" "${CLI}"
        run_root install -m 0755 "${BUILD_DIR}/cep-argon4" "${INSTALL_DIR}/cep-argon4"
        if [[ "${CEP_CONFIGURE:-1}" == "1" ]]; then
            configure_profiles
        fi
        if run_root test -s "${PROFILES}" && run_root test -s "${CHAIN_FILE}"; then
            write_unit
            run_root systemctl enable cep-chain.service
            start_service
        else
            echo "Бинарники установлены, но цепочка не настроена и сервис не запущен."
            echo "Выполните: make configure-cep-linux && make cep-service-start"
        fi
        ;;
    configure)
        require_linux
        if [[ ! -x "${CLI}" ]]; then
            echo "Сначала выполните make install-cep-linux" >&2
            exit 1
        fi
        configure_profiles
        if [[ -f "${UNIT_PATH}" ]]; then
            write_unit
            if run_root systemctl is-active --quiet cep-chain.service; then
                run_root systemctl restart cep-chain.service
                start_service
            else
                echo "Настройка сохранена. Запуск: make cep-service-start"
            fi
        else
            write_unit
            run_root systemctl enable cep-chain.service
            start_service
        fi
        ;;
    start)
        require_linux
        read_chain_id >/dev/null
        start_service
        ;;
    stop)
        require_linux
        run_root systemctl stop cep-chain.service
        echo "Сервис остановлен."
        ;;
    restart)
        require_linux
        read_chain_id >/dev/null
        run_root systemctl restart cep-chain.service
        start_service
        ;;
    status)
        require_linux
        systemctl status cep-chain.service --no-pager -l
        ;;
    logs)
        require_linux
        run_root journalctl -u cep-chain.service -n 100 -f
        ;;
    *)
        echo "Использование: $0 {install|configure|start|stop|restart|status|logs}" >&2
        exit 2
        ;;
esac
