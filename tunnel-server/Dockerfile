# syntax=docker/dockerfile:1

FROM golang:1.23-alpine AS builder
WORKDIR /src
COPY go.mod ./
COPY cmd ./cmd
COPY internal ./internal
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/argon-tunnel-server ./cmd/argon-tunnel-server \
    && CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/argon-tunnel-client ./cmd/argon-tunnel-client

FROM ubuntu:24.04
RUN apt-get update && apt-get install -y --no-install-recommends \
        iproute2 \
        iptables \
        iputils-ping \
        curl \
        ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY --from=builder /out/argon-tunnel-server /usr/local/bin/argon-tunnel-server
COPY --from=builder /out/argon-tunnel-client /usr/local/bin/argon-tunnel-client
ENTRYPOINT ["/usr/local/bin/argon-tunnel-server"]
