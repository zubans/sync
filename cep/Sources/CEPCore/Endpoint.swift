import Foundation

public struct ConfigError: LocalizedError, Sendable {
    public let message: String
    public init(_ message: String) { self.message = message }
    public var errorDescription: String? { message }
}

/// Адрес сервера узла: до него движок прокладывает маршрут через предыдущий узел.
public struct Endpoint: Equatable, Sendable {
    public var host: String
    public var port: Int?

    public static func of(_ p: Profile) throws -> Endpoint {
        switch p.proto {
        case .vless, .hysteria2, .hysteria:
            let link = try ProxyLink.parse(p.uri)
            return Endpoint(host: link.host, port: link.port)
        case .openvpn:
            guard let remote = OpenVPNConfig.firstRemote(p.config) else {
                throw ConfigError("в конфиге OpenVPN нет строки remote")
            }
            return Endpoint(host: remote.host, port: remote.port)
        case .amneziawg:
            let conf = try AWGConfig.parse(p.config)
            return conf.endpoint
        case .openconnect, .sstp:
            return try parseHostPort(p.server)
        case .argon4:
            return try argon4(p.server)
        }
    }

    /// UDP-адрес Argon4: host, host:port, udp://host:port или argon4://host:port.
    public static func argon4(_ raw: String) throws -> Endpoint {
        let trimmed = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        if let scheme = trimmed.split(separator: ":", maxSplits: 1).first?.lowercased(),
           trimmed.contains("://"), scheme != "udp", scheme != "argon4" {
            throw ConfigError("Argon4: поддерживаются только udp:// и argon4://")
        }
        if trimmed.contains("@") {
            throw ConfigError("Argon4: адрес сервера не должен содержать учётные данные")
        }
        let endpoint = try parseHostPort(trimmed)
        let port = endpoint.port ?? 51820
        guard (1...65535).contains(port) else {
            throw ConfigError("Argon4: порт должен быть от 1 до 65535")
        }
        return Endpoint(host: endpoint.host, port: port)
    }

    /// host, host:port, [v6]:port, https://host:port/path
    public static func parseHostPort(_ raw: String) throws -> Endpoint {
        var s = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        if let r = s.range(of: "://") {
            s = String(s[r.upperBound...])
        }
        if let slash = s.firstIndex(of: "/") {
            s = String(s[..<slash])
        }
        guard !s.isEmpty else { throw ConfigError("не указан сервер") }
        if s.hasPrefix("["), let close = s.firstIndex(of: "]") {
            let host = String(s[s.index(after: s.startIndex)..<close])
            let rest = s[s.index(after: close)...]
            let port = rest.hasPrefix(":") ? Int(rest.dropFirst()) : nil
            return Endpoint(host: host, port: port)
        }
        let parts = s.split(separator: ":", omittingEmptySubsequences: false)
        if parts.count == 2 {
            guard let port = Int(parts[1]) else { throw ConfigError("неверный порт в «\(raw)»") }
            return Endpoint(host: String(parts[0]), port: port)
        }
        return Endpoint(host: s, port: nil)
    }
}
