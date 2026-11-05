#!/usr/bin/env bash
set -euo pipefail

# Точка входа OpenConnect VPN-сервера (ocserv).
# Настраивается переменными окружения (см. compose.yaml и docs/ocserv.md).
# При первом старте генерирует CA и серверный сертификат; они сохраняются в томе /etc/ocserv,
# чтобы клиентам не пришлось заново доверять CA при пересоздании контейнера.

CONF=/etc/ocserv/ocserv.conf
CERT_DIR=/etc/ocserv/certs
PASSWD=/etc/ocserv/ocpasswd

TCP_PORT="${OCSERV_TCP_PORT:-443}"
UDP_PORT="${OCSERV_UDP_PORT:-443}"
SUBNET="${OCSERV_SUBNET:-10.45.0.0}"
NETMASK="${OCSERV_NETMASK:-255.255.255.0}"
DNS="${OCSERV_DNS:-1.1.1.1,8.8.8.8}"
NO_ROUTES="${OCSERV_NO_ROUTES:-192.168.0.0/16,10.0.0.0/8,172.16.0.0/12,100.64.0.0/10}"
OUT_INTERFACE="${OCSERV_OUT_INTERFACE:-eth0}"
MAX_CLIENTS="${OCSERV_MAX_CLIENTS:-16}"
ORG="${OCSERV_ORG:-Contact Sync VPN}"
CN="${OCSERV_CN:-vpn.example.com}"
USERS="${OCSERV_USERS:-}"
# Проброс портов с сервера на подключённого клиента (например, Minecraft 25565/tcp, 19132/udp).
# OCSERV_FORWARD_TARGET — VPN-IP клиента (см. make ocserv-status); пусто — проброс выключен.
FORWARD_TARGET="${OCSERV_FORWARD_TARGET:-}"
FORWARD_TCP="${OCSERV_FORWARD_TCP:-25565}"
FORWARD_UDP="${OCSERV_FORWARD_UDP:-19132}"
LOG_LEVEL="${OCSERV_LOG_LEVEL:-3}"
# isolate-workers (seccomp-песочница) ломает рукопожатие в Docker (worker не может подключиться
# к сокету sec-mod). Для личного VPN в контейнере безопаснее выключить: OCSERV_ISOLATE_WORKERS=1 — включить.
ISOLATE_WORKERS="${OCSERV_ISOLATE_WORKERS:-0}"

# --- сертификаты (CA + серверный), если ещё нет ---
if [[ ! -f "$CERT_DIR/ca.pem" || ! -f "$CERT_DIR/server-cert.pem" || ! -f "$CERT_DIR/server-key.pem" ]]; then
    echo "==> Генерирую CA и серверный сертификат (CN=$CN)..."
    mkdir -p "$CERT_DIR"
    openssl genrsa -out "$CERT_DIR/ca-key.pem" 4096 2>/dev/null
    openssl req -x509 -new -key "$CERT_DIR/ca-key.pem" -sha256 -days 3650 \
        -subj "/O=$ORG/CN=$CN" -out "$CERT_DIR/ca.pem"
    openssl genrsa -out "$CERT_DIR/server-key.pem" 2048 2>/dev/null
    openssl req -new -key "$CERT_DIR/server-key.pem" \
        -subj "/O=$ORG/CN=$CN" -out "$CERT_DIR/server.csr"

    SAN="DNS:$CN"
    if [[ -n "${OCSERV_SERVER_IP:-}" ]]; then
        SAN="$SAN, IP:${OCSERV_SERVER_IP}"
    fi
    printf 'subjectAltName = %s\n' "$SAN" > "$CERT_DIR/server-ext.cnf"

    openssl x509 -req -in "$CERT_DIR/server.csr" -CA "$CERT_DIR/ca.pem" -CAkey "$CERT_DIR/ca-key.pem" \
        -CAcreateserial -out "$CERT_DIR/server-cert.pem" -days 3650 -sha256 \
        -extfile "$CERT_DIR/server-ext.cnf"
    rm -f "$CERT_DIR/server.csr" "$CERT_DIR/server-ext.cnf"
    chmod 600 "$CERT_DIR/server-key.pem"
fi

# --- пользователи: OCSERV_USERS="user1:pass1,user2:pass2" ---
if [[ -n "$USERS" ]]; then
    rm -f "$PASSWD"
    IFS=',' read -ra ENTRIES <<< "$USERS"
    for e in "${ENTRIES[@]}"; do
        u="${e%%:*}"; p="${e#*:}"
        echo "$p" | ocpasswd -c "$PASSWD" "$u"
    done
    chmod 600 "$PASSWD"
fi

# --- конфиг ocserv ---
cat > "$CONF" <<EOF
auth = "plain[passwd=$PASSWD]"
tcp-port = $TCP_PORT
udp-port = $UDP_PORT
run-as-user = ocserv
run-as-group = ocserv
socket-file = /run/ocserv.socket
server-cert = $CERT_DIR/server-cert.pem
server-key = $CERT_DIR/server-key.pem
ca-cert = $CERT_DIR/ca.pem
isolate-workers = $ISOLATE_WORKERS
max-clients = $MAX_CLIENTS
max-same-clients = 4
keepalive = 32400
dpd = 90
mobile-dpd = 1800
switch-to-tcp-timeout = 25
try-mtu-discovery = true
log-level = $LOG_LEVEL
tls-priorities = "NORMAL:%SERVER_PRECEDENCE:%COMPAT:-VERS-SSL3.0"
auth-timeout = 240
min-reauth-time = 300
max-ban-score = 50
ban-reset-time = 300
cookie-timeout = 300
deny-roaming = false
rekey-time = 172800
rekey-method = ssl
use-utmp = false
use-occtl = true
pid-file = /run/ocserv.pid
device = vpn
predictable-ips = true
ipv4-network = $SUBNET
ipv4-netmask = $NETMASK
EOF
IFS=',' read -ra DNS_LIST <<< "$DNS"
for d in "${DNS_LIST[@]}"; do echo "dns = $d" >> "$CONF"; done
# Без явного route ocserv маршрутизирует весь трафик клиента; no-route исключает локальные сети.
IFS=',' read -ra NR <<< "$NO_ROUTES"
for r in "${NR[@]}"; do echo "no-route = $r" >> "$CONF"; done

# --- маршрутизация / NAT (ip_forward включает compose через sysctls) ---
if [[ "${OCSERV_ENABLE_NAT:-1}" == "1" ]]; then
    echo "==> Включаю NAT через $OUT_INTERFACE..."
    iptables -t nat -C POSTROUTING -s "$SUBNET/$NETMASK" -o "$OUT_INTERFACE" -j MASQUERADE 2>/dev/null \
        || iptables -t nat -A POSTROUTING -s "$SUBNET/$NETMASK" -o "$OUT_INTERFACE" -j MASQUERADE
    iptables -C FORWARD -i vpn+ -o "$OUT_INTERFACE" -j ACCEPT 2>/dev/null \
        || iptables -A FORWARD -i vpn+ -o "$OUT_INTERFACE" -j ACCEPT
    iptables -C FORWARD -i "$OUT_INTERFACE" -o vpn+ -m state --state RELATED,ESTABLISHED -j ACCEPT 2>/dev/null \
        || iptables -A FORWARD -i "$OUT_INTERFACE" -o vpn+ -m state --state RELATED,ESTABLISHED -j ACCEPT
fi

# --- проброс портов на клиента (DNAT): порты, открытые на машине клиента, становятся доступны
# на внешнем адресе сервера. Целевой VPN-IP задаётся OCSERV_FORWARD_TARGET (см. make ocserv-status). ---
if [[ -n "$FORWARD_TARGET" ]]; then
    echo "==> Пробрасываю порты на клиента $FORWARD_TARGET (tcp: $FORWARD_TCP, udp: $FORWARD_UDP)..."
    # Трафик, DNAT-нутый в VPN-подсеть, должен приходить на клиента с доверенного туннельного IP
    # (10.45.0.1), иначе клиентский файрвол блокирует его как входящий с неизвестного адреса.
    iptables -t nat -C POSTROUTING -o vpn+ -j MASQUERADE 2>/dev/null \
        || iptables -t nat -A POSTROUTING -o vpn+ -j MASQUERADE
    IFS=',' read -ra TCP_PORTS <<< "$FORWARD_TCP"
    for p in "${TCP_PORTS[@]}"; do
        iptables -t nat -C PREROUTING -p tcp --dport "$p" -j DNAT --to-destination "$FORWARD_TARGET:$p" 2>/dev/null \
            || iptables -t nat -A PREROUTING -p tcp --dport "$p" -j DNAT --to-destination "$FORWARD_TARGET:$p"
        iptables -C FORWARD -i "$OUT_INTERFACE" -o vpn+ -p tcp --dport "$p" -j ACCEPT 2>/dev/null \
            || iptables -A FORWARD -i "$OUT_INTERFACE" -o vpn+ -p tcp --dport "$p" -j ACCEPT
    done
    IFS=',' read -ra UDP_PORTS <<< "$FORWARD_UDP"
    for p in "${UDP_PORTS[@]}"; do
        iptables -t nat -C PREROUTING -p udp --dport "$p" -j DNAT --to-destination "$FORWARD_TARGET:$p" 2>/dev/null \
            || iptables -t nat -A PREROUTING -p udp --dport "$p" -j DNAT --to-destination "$FORWARD_TARGET:$p"
        iptables -C FORWARD -i "$OUT_INTERFACE" -o vpn+ -p udp --dport "$p" -j ACCEPT 2>/dev/null \
            || iptables -A FORWARD -i "$OUT_INTERFACE" -o vpn+ -p udp --dport "$p" -j ACCEPT
    done
fi

echo "==> Запускаю ocserv (tcp/udp $TCP_PORT, сеть $SUBNET/$NETMASK)..."
ocserv -f -c "$CONF" &
OCSERV_PID=$!

wait "$OCSERV_PID"