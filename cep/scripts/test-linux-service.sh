#!/usr/bin/env bash
set -euo pipefail

# Изолированная проверка service-скрипта: настоящие systemd, sudo и /etc не используются.
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
TEST_DIR="$(mktemp -d "${ROOT}/cep/build/service-test.XXXXXX")"
trap 'rm -rf -- "${TEST_DIR}"' EXIT
mkdir -p "${TEST_DIR}/bin" "${TEST_DIR}/build" "${TEST_DIR}/etc"

cat > "${TEST_DIR}/bin/uname" <<'EOF'
#!/bin/sh
printf '%s\n' Linux
EOF
cat > "${TEST_DIR}/bin/sudo" <<'EOF'
#!/bin/sh
exec "$@"
EOF
cat > "${TEST_DIR}/bin/systemctl" <<'EOF'
#!/bin/sh
printf '%s\n' "$*" >> "${CEP_TEST_LOG}"
exit 0
EOF
cat > "${TEST_DIR}/bin/journalctl" <<'EOF'
#!/bin/sh
exit 0
EOF
cat > "${TEST_DIR}/bin/sleep" <<'EOF'
#!/bin/sh
exit 0
EOF
cat > "${TEST_DIR}/build/cep-cli" <<'EOF'
#!/bin/sh
exit 0
EOF
cp "${TEST_DIR}/build/cep-cli" "${TEST_DIR}/build/cep-argon4"
chmod +x "${TEST_DIR}/bin/"* "${TEST_DIR}/build/"*

printf '%s\n' '{"profiles":[],"chains":[]}' > "${TEST_DIR}/etc/profiles.json"
printf '%s\n' '123e4567-e89b-42d3-a456-426614174000' > "${TEST_DIR}/etc/chain-id"
chmod 0600 "${TEST_DIR}/etc/profiles.json" "${TEST_DIR}/etc/chain-id"
: > "${TEST_DIR}/systemctl.log"

PATH="${TEST_DIR}/bin:/usr/bin:/bin" \
CEP_TEST_LOG="${TEST_DIR}/systemctl.log" \
CEP_CONFIGURE=0 \
CEP_BUILD_DIR="${TEST_DIR}/build" \
CEP_SERVICE_INSTALL_DIR="${TEST_DIR}/opt" \
CEP_SERVICE_CONFIG_DIR="${TEST_DIR}/etc" \
CEP_SERVICE_UNIT_PATH="${TEST_DIR}/cep-chain.service" \
    bash "${ROOT}/cep/scripts/linux-service.sh" install

unit="${TEST_DIR}/cep-chain.service"
grep -q 'ExecStart=.*/cep-cli up -profiles .*/profiles.json -chain 123e4567-e89b-42d3-a456-426614174000' "${unit}"
grep -q '^daemon-reload$' "${TEST_DIR}/systemctl.log"
grep -q '^enable cep-chain.service$' "${TEST_DIR}/systemctl.log"
grep -q '^start cep-chain.service$' "${TEST_DIR}/systemctl.log"
grep -q '^is-active --quiet cep-chain.service$' "${TEST_DIR}/systemctl.log"
test -x "${TEST_DIR}/opt/cep-cli"
test -x "${TEST_DIR}/opt/cep-argon4"

if [[ "$(uname -s)" == "Darwin" ]]; then
    if bash "${ROOT}/cep/scripts/linux-service.sh" status > "${TEST_DIR}/darwin.log" 2>&1; then
        echo "На macOS service-скрипт должен отказываться работать" >&2
        exit 1
    fi
    grep -q 'только на Linux' "${TEST_DIR}/darwin.log"
fi

echo "Изолированный тест установщика CEP пройден."
