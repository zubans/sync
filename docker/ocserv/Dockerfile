# syntax=docker/dockerfile:1

# OpenConnect VPN-сервер (ocserv), собирается из исходников.
# Версия задаётся ARG OCSERV_VERSION (по умолчанию 1.3.0).
# Необязательные фичи (http-parser, pcl/lz4-сжатие, oath, radcli, protobuf, gssapi) отключены,
# чтобы образ был проще и не зависел от отсутствующих в bookworm runtime-пакетов.

ARG OCSERV_VERSION=1.3.0

FROM debian:bookworm-slim AS build
ARG OCSERV_VERSION
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl xz-utils \
        build-essential autoconf automake libtool pkg-config gawk gperf \
        libgnutls28-dev libev-dev libseccomp-dev libreadline-dev \
        libnl-route-3-dev libtalloc-dev ipcalc-ng \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /src
COPY patch-tlslib-secmod-socket.patch /src/ocserv-patch-tlslib-secmod-socket.patch
RUN curl -fsSL "https://www.infradead.org/ocserv/download/ocserv-${OCSERV_VERSION}.tar.xz" -o ocserv.tar.xz \
    && tar xf ocserv.tar.xz \
    && cd "ocserv-${OCSERV_VERSION}" \
    && patch -p1 < /src/ocserv-patch-tlslib-secmod-socket.patch \
    && ./configure --prefix=/usr --sysconfdir=/etc --localstatedir=/var \
        --disable-lz4 --without-pcl --without-http-parser \
        --without-oath --without-radcli --without-protobuf \
        --without-gssapi --without-maxmind --without-geoip \
    && make -j"$(nproc)" \
    && make install

FROM debian:bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgnutls30 libev4 libseccomp2 libreadline8 \
        libnl-route-3-200 libtalloc2 \
        iptables iproute2 openssl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=build /usr/sbin/ocserv /usr/sbin/ocserv
# ocserv-worker — отдельный бинарь, который main-процесс exec'ит для обработки каждого
# подключения. Без него worker падает сразу после fork и клиент получает обрыв TLS-рукопожатия.
COPY --from=build /usr/sbin/ocserv-worker /usr/sbin/ocserv-worker
COPY --from=build /usr/bin/ocpasswd /usr/bin/ocpasswd
COPY --from=build /usr/bin/occtl /usr/bin/occtl

COPY ocserv.conf /etc/ocserv/ocserv.conf
COPY entrypoint.sh /entrypoint.sh

RUN useradd -r -s /usr/sbin/nologin ocserv \
    && mkdir -p /etc/ocserv/certs /run/ocserv \
    && chmod 0755 /entrypoint.sh

EXPOSE 443/tcp 443/udp
ENTRYPOINT ["/entrypoint.sh"]