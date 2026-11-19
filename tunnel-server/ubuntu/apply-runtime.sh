#!/usr/bin/env bash
# Применяет на хосте конфигурацию, которую панель sync выгрузила в VPN_RUNTIME_DIR.
#
# Что делает:
#   1. Процессы Argon4: на каждый argon-server.env и services/argon-*.env поднимает экземпляр
#      argon-tunnel@<имя>.service (служба Argon4, транзит, обёртка Argon4 службы, слепой пропуск
#      узла на цепочке). Перезапускает только изменившиеся, лишние останавливает.
#   2. DNAT/MASQUERADE: выполняет port-forward.sh (пробросы портов и DNAT-пропуски).
#   3. Firewall: открывает в цепочке iptables SYNC_IN все порты из раздела "ports"
#      desired-state.json — ровно то, что показывает таблица «Порты узла» в админке.
#   4. Пишет runtime-status.json, который админка показывает как статус применения.
#
# Запуск: вручную (sudo VPN_RUNTIME_DIR=... ./apply-runtime.sh), из systemd-юнита
# sync-vpn-apply.path при каждом изменении desired-state.json, либо как VPN_RUNTIME_APPLY_CMD,
# если панель работает прямо на хосте под root.
set -euo pipefail

RUNTIME_DIR="${VPN_RUNTIME_DIR:-${1:-}}"
INSTANCES_DIR="${ARGON_INSTANCES_DIR:-/etc/argon-tunnel/instances}"
SYSTEMCTL="${SYSTEMCTL:-systemctl}"
IPTABLES="${IPTABLES:-iptables}"

if [[ -z "${RUNTIME_DIR}" || ! -f "${RUNTIME_DIR}/desired-state.json" ]]; then
    echo "Не найден desired-state.json: задайте VPN_RUNTIME_DIR (каталог runtime панели sync)" >&2
    exit 1
fi

errors=()
log() { echo "==> $*"; }

# --- 1. Процессы Argon4 -------------------------------------------------------
mkdir -p "${INSTANCES_DIR}"
chmod 0700 "${INSTANCES_DIR}"

wanted=""   # имена нужных экземпляров, по одному в строке
add_instance() {
    local src="$1" name="$2"
    wanted+="${name}"$'\n'
    local dst="${INSTANCES_DIR}/${name}.env"
    if [[ -f "${dst}" ]] && cmp -s "${src}" "${dst}"; then
        "${SYSTEMCTL}" is-active --quiet "argon-tunnel@${name}" || "${SYSTEMCTL}" start "argon-tunnel@${name}" || errors+=("не запустился argon-tunnel@${name}")
        return
    fi
    install -m 0600 "${src}" "${dst}"
    log "argon-tunnel@${name}: конфигурация изменилась, перезапуск"
    "${SYSTEMCTL}" enable --quiet "argon-tunnel@${name}" || true
    "${SYSTEMCTL}" restart "argon-tunnel@${name}" || errors+=("не перезапустился argon-tunnel@${name}")
}

if [[ -f "${RUNTIME_DIR}/argon-server.env" ]]; then
    add_instance "${RUNTIME_DIR}/argon-server.env" "main"
fi
shopt -s nullglob
for env in "${RUNTIME_DIR}"/services/argon-*.env; do
    add_instance "${env}" "$(basename "${env}" .env)"
done
shopt -u nullglob

for existing in "${INSTANCES_DIR}"/*.env; do
    [[ -e "${existing}" ]] || continue
    name="$(basename "${existing}" .env)"
    if ! grep -qxF "${name}" <<< "${wanted}"; then
        log "argon-tunnel@${name}: больше не нужен, остановка"
        "${SYSTEMCTL}" disable --now --quiet "argon-tunnel@${name}" || true
        rm -f "${existing}"
    fi
done

# --- 2. DNAT / MASQUERADE -----------------------------------------------------
if [[ -x "${RUNTIME_DIR}/port-forward.sh" || -f "${RUNTIME_DIR}/port-forward.sh" ]]; then
    bash "${RUNTIME_DIR}/port-forward.sh" || errors+=("ошибка port-forward.sh")
fi

# --- 3. Firewall: открыть порты узла -----------------------------------------
ports="$(python3 - "${RUNTIME_DIR}/desired-state.json" <<'PY'
import json, sys
state = json.load(open(sys.argv[1], encoding="utf-8"))
if state.get("vpnModuleEnabled") is False:
    sys.exit(0)
for p in state.get("ports") or []:
    start, end = int(p["port"]), int(p.get("portEnd") or p["port"])
    rng = str(start) if start == end else f"{start}:{end}"
    protos = ["tcp", "udp"] if p.get("transport") == "both" else [p.get("transport", "udp")]
    for proto in protos:
        print(f"{proto} {rng}")
PY
)" || { errors+=("не удалось прочитать ports из desired-state.json"); ports=""; }

if command -v "${IPTABLES}" >/dev/null 2>&1; then
    "${IPTABLES}" -N SYNC_IN 2>/dev/null || true
    "${IPTABLES}" -F SYNC_IN
    "${IPTABLES}" -C INPUT -j SYNC_IN 2>/dev/null || "${IPTABLES}" -I INPUT 1 -j SYNC_IN
    opened=0
    while read -r proto rng; do
        [[ -n "${proto}" ]] || continue
        "${IPTABLES}" -A SYNC_IN -p "${proto}" --dport "${rng}" -j ACCEPT
        opened=$((opened + 1))
    done <<< "${ports}"
    log "firewall: открыто правил ${opened}"
else
    errors+=("iptables не установлен — порты в firewall не открыты")
fi

# --- 4. Статус для админки ---------------------------------------------------
status="applied"
message="Конфигурация узла применена на хосте (экземпляров Argon4: $(grep -c . <<< "${wanted}" || true))."
if (( ${#errors[@]} > 0 )); then
    status="error"
    message="$(printf '%s; ' "${errors[@]}")"
fi
python3 - "${RUNTIME_DIR}/runtime-status.json" "${status}" "${message}" <<'PY'
import json, sys, datetime
path, status, message = sys.argv[1:4]
json.dump({
    "status": status,
    "message": message,
    "appliedAt": datetime.datetime.now(datetime.timezone.utc).isoformat(),
}, open(path, "w", encoding="utf-8"), ensure_ascii=False, indent=2)
PY

echo "${message}"
[[ "${status}" == "applied" ]]
