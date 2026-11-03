#!/usr/bin/env bash
set -euo pipefail

# Make вызывает этот скрипт каждый раз, но установка начинается только при отсутствии программы.
if [[ "$(uname -s)" != "Linux" ]]; then
    exit 0
fi

PREFIX="${CEP_INSTALL_PREFIX:-/usr/local/bin}"
SING_BOX_VERSION="${SING_BOX_VERSION:-1.14.3}"
XRAY_VERSION="${XRAY_VERSION:-26.3.27}"
UPDATE_TOOLS="${CEP_UPDATE_TOOLS:-0}"
workdir=""

cleanup() {
    if [[ -n "${workdir}" ]]; then
        rm -rf -- "${workdir}"
    fi
}
trap cleanup EXIT

run_root() {
    if [[ "${EUID}" -eq 0 ]]; then
        "$@"
    elif command -v sudo >/dev/null 2>&1; then
        sudo "$@"
    else
        echo "Для установки зависимостей нужен root или sudo" >&2
        exit 1
    fi
}

packages=()
command -v go >/dev/null 2>&1 || packages+=(golang-go)
command -v ip >/dev/null 2>&1 || packages+=(iproute2)
command -v openconnect >/dev/null 2>&1 || packages+=(openconnect)
command -v openvpn >/dev/null 2>&1 || packages+=(openvpn)
command -v resolvectl >/dev/null 2>&1 || packages+=(systemd)
if ! command -v xray >/dev/null 2>&1 || [[ "${UPDATE_TOOLS}" == "1" ]]; then
    command -v curl >/dev/null 2>&1 || packages+=(curl)
    command -v unzip >/dev/null 2>&1 || packages+=(unzip)
    packages+=(ca-certificates)
fi
if ! command -v sing-box >/dev/null 2>&1 || [[ "${UPDATE_TOOLS}" == "1" ]]; then
    command -v curl >/dev/null 2>&1 || packages+=(curl)
    packages+=(ca-certificates)
fi

if ((${#packages[@]})); then
    # apt-get update вызывается один раз и только когда действительно есть что устанавливать.
    run_root apt-get update
    run_root env DEBIAN_FRONTEND=noninteractive \
        apt-get install -y --no-install-recommends "${packages[@]}"
fi

architecture() {
    case "$(uname -m)" in
        x86_64) echo "amd64" ;;
        aarch64|arm64) echo "arm64" ;;
        armv7l) echo "armv7" ;;
        *) echo "Неподдерживаемая архитектура: $(uname -m)" >&2; exit 1 ;;
    esac
}

install_sing_box() {
    local arch version archive url binary
    arch="$(architecture)"
    version="${SING_BOX_VERSION#v}"
    archive="sing-box-${version}-linux-${arch}.tar.gz"
    url="https://github.com/SagerNet/sing-box/releases/download/v${version}/${archive}"
    workdir="$(mktemp -d)"
    echo "Установка sing-box ${version} для linux/${arch}"
    curl -fL --retry 3 --connect-timeout 15 "${url}" -o "${workdir}/${archive}"
    tar -xzf "${workdir}/${archive}" -C "${workdir}"
    binary="${workdir}/sing-box-${version}-linux-${arch}/sing-box"
    run_root install -m 0755 "${binary}" "${PREFIX}/sing-box"
    rm -rf -- "${workdir}"
    workdir=""
}

install_xray() {
    local arch version asset url
    arch="$(architecture)"
    version="${XRAY_VERSION#v}"
    case "${arch}" in
        amd64) asset="Xray-linux-64.zip" ;;
        arm64) asset="Xray-linux-arm64-v8a.zip" ;;
        armv7) asset="Xray-linux-arm32-v7a.zip" ;;
    esac
    url="https://github.com/XTLS/Xray-core/releases/download/v${version}/${asset}"
    workdir="$(mktemp -d)"
    echo "Установка Xray ${version} для linux/${arch}"
    curl -fL --retry 3 --connect-timeout 15 "${url}" -o "${workdir}/${asset}"
    unzip -q "${workdir}/${asset}" -d "${workdir}/xray"
    run_root install -m 0755 "${workdir}/xray/xray" "${PREFIX}/xray"
    rm -rf -- "${workdir}"
    workdir=""
}

if ! command -v sing-box >/dev/null 2>&1 || ! command -v xray >/dev/null 2>&1 || [[ "${UPDATE_TOOLS}" == "1" ]]; then
    run_root mkdir -p "${PREFIX}"
fi
if ! command -v sing-box >/dev/null 2>&1 || [[ "${UPDATE_TOOLS}" == "1" ]]; then
    install_sing_box
fi
if ! command -v xray >/dev/null 2>&1 || [[ "${UPDATE_TOOLS}" == "1" ]]; then
    install_xray
fi

for tool in ip resolvectl openconnect openvpn sing-box xray; do
    if ! command -v "${tool}" >/dev/null 2>&1; then
        echo "После установки не найден ${tool}; проверьте PATH=${PATH}" >&2
        exit 1
    fi
done
