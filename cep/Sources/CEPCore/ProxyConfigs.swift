import Foundation

public enum JSON {
    public static func data(_ object: Any) throws -> Data {
        try JSONSerialization.data(withJSONObject: object, options: [.prettyPrinted, .sortedKeys, .withoutEscapingSlashes])
    }
}

/// Конфиг sing-box: TUN без автомаршрутов, весь трафик из него уходит в один outbound.
/// Маршруты в TUN прокладывает движок — так прокси-узел встаёт в цепочку как обычный интерфейс.
public enum SingBoxConfig {
    public static func make(tunAddress: String, outbound: [String: Any]) -> [String: Any] {
        var out = outbound
        out["tag"] = "proxy"
        return [
            "log": ["level": "info", "timestamp": true],
            "inbounds": [[
                "type": "tun",
                "tag": "tun-in",
                "address": [tunAddress],
                "mtu": 1400,
                "auto_route": false,
                "stack": "gvisor",
            ] as [String: Any]],
            "outbounds": [out],
            "route": [
                "final": "proxy",
                "auto_detect_interface": false,
            ],
        ]
    }

    /// Outbound sing-box для Hysteria 2 / Hysteria; server — уже разрешённый IP.
    public static func outbound(for link: ProxyLink, server: String) throws -> [String: Any] {
        var tls: [String: Any] = ["enabled": true]
        if let sni = link.sni { tls["server_name"] = sni }
        if link.flag("insecure") || link.flag("allowInsecure") { tls["insecure"] = true }

        switch link.scheme {
        case "hysteria2":
            if let alpn = link.q("alpn") { tls["alpn"] = alpn.split(separator: ",").map(String.init) }
            var out: [String: Any] = [
                "type": "hysteria2",
                "server": server,
                "server_port": link.port,
                "password": link.userInfo,
                "tls": tls,
            ]
            if let obfs = link.q("obfs"), obfs != "none" {
                out["obfs"] = ["type": obfs, "password": link.q("obfs-password") ?? ""]
            }
            if let up = link.q("upmbps").flatMap(Int.init) { out["up_mbps"] = up }
            if let down = link.q("downmbps").flatMap(Int.init) { out["down_mbps"] = down }
            return out

        case "hysteria":
            if let proto = link.q("protocol"), proto != "udp" {
                throw ConfigError("Hysteria v1: поддерживается только protocol=udp")
            }
            tls["alpn"] = [link.q("alpn") ?? "hysteria"]
            var out: [String: Any] = [
                "type": "hysteria",
                "server": server,
                "server_port": link.port,
                "up_mbps": link.q("upmbps").flatMap(Int.init) ?? 50,
                "down_mbps": link.q("downmbps").flatMap(Int.init) ?? 100,
                "tls": tls,
            ]
            if let auth = link.q("auth") { out["auth_str"] = auth }
            if let obfs = link.q("obfsParam") ?? link.q("obfs") { out["obfs"] = obfs }
            return out

        default:
            throw ConfigError("sing-box не обрабатывает \(link.scheme):// напрямую")
        }
    }

    /// TUN sing-box, который отдаёт трафик в локальный SOCKS xray.
    public static func socksOutbound(port: Int) -> [String: Any] {
        ["type": "socks", "server": "127.0.0.1", "server_port": port, "version": "5"]
    }
}

/// Конфиг xray для VLESS: эталонная реализация, умеет Reality, Vision, xhttp, ws, grpc.
/// Наружу xray отдаёт SOCKS на 127.0.0.1, в TUN его заворачивает sing-box.
public enum XrayConfig {
    public static func make(link: ProxyLink, server: String, socksPort: Int) throws -> [String: Any] {
        guard link.scheme == "vless" else { throw ConfigError("xray-конфиг строится только для VLESS") }
        guard !link.userInfo.isEmpty else { throw ConfigError("в ссылке VLESS нет UUID") }

        var user: [String: Any] = ["id": link.userInfo, "encryption": link.q("encryption") ?? "none"]
        if let flow = link.q("flow") { user["flow"] = flow }

        let network = link.q("type") ?? "tcp"
        let security = link.q("security") ?? "none"
        var stream: [String: Any] = ["network": network, "security": security]

        switch security {
        case "reality":
            guard let pbk = link.q("pbk") else { throw ConfigError("Reality: в ссылке нет pbk") }
            var reality: [String: Any] = [
                "publicKey": pbk,
                "fingerprint": link.q("fp") ?? "chrome",
            ]
            if let sni = link.sni { reality["serverName"] = sni }
            if let sid = link.q("sid") { reality["shortId"] = sid }
            if let spx = link.q("spx") { reality["spiderX"] = spx }
            stream["realitySettings"] = reality
        case "tls":
            var tls: [String: Any] = ["fingerprint": link.q("fp") ?? "chrome"]
            if let sni = link.sni { tls["serverName"] = sni }
            if let alpn = link.q("alpn") { tls["alpn"] = alpn.split(separator: ",").map(String.init) }
            if link.flag("allowInsecure") || link.flag("insecure") { tls["allowInsecure"] = true }
            stream["tlsSettings"] = tls
        case "none":
            break
        default:
            throw ConfigError("VLESS: неизвестный security=\(security)")
        }

        let path = link.q("path")
        let hostHeader = link.q("host")
        switch network {
        case "tcp", "raw":
            if link.q("headerType") == "http" {
                var request: [String: Any] = ["path": [path ?? "/"]]
                if let h = hostHeader { request["headers"] = ["Host": [h]] }
                stream["tcpSettings"] = ["header": ["type": "http", "request": request]]
            }
        case "ws":
            var ws: [String: Any] = ["path": path ?? "/"]
            if let h = hostHeader { ws["host"] = h }
            stream["wsSettings"] = ws
        case "httpupgrade":
            var hu: [String: Any] = ["path": path ?? "/"]
            if let h = hostHeader { hu["host"] = h }
            stream["httpupgradeSettings"] = hu
        case "grpc":
            var grpc: [String: Any] = ["serviceName": link.q("serviceName") ?? ""]
            if link.q("mode") == "multi" { grpc["multiMode"] = true }
            grpc["idle_timeout"] = 60
            grpc["health_check_timeout"] = 20
            grpc["permit_without_stream"] = true
            stream["grpcSettings"] = grpc
        case "xhttp", "splithttp":
            stream["network"] = "xhttp"
            var xhttp: [String: Any] = ["path": path ?? "/", "mode": link.q("mode") ?? "auto"]
            if let h = hostHeader { xhttp["host"] = h }
            if let extra = link.q("extra"),
               let data = extra.data(using: .utf8),
               let obj = try? JSONSerialization.jsonObject(with: data) {
                xhttp["extra"] = obj
            }
            stream["xhttpSettings"] = xhttp
        default:
            throw ConfigError("VLESS: транспорт type=\(network) не поддерживается")
        }

        stream["sockopt"] = [
            "tcpKeepAliveInterval": 15,
            "tcpNoDelay": true,
        ]

        return [
            "log": ["loglevel": "warning"],
            "inbounds": [[
                "listen": "127.0.0.1",
                "port": socksPort,
                "protocol": "socks",
                "settings": ["auth": "noauth", "udp": true],
            ] as [String: Any]],
            "outbounds": [[
                "protocol": "vless",
                "settings": ["vnext": [["address": server, "port": link.port, "users": [user]]]],
                "streamSettings": stream,
            ] as [String: Any]],
        ]
    }
}
