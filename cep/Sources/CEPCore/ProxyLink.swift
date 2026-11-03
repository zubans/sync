import Foundation

/// Разобранная ссылка vless:// hysteria2:// hy2:// hysteria://
public struct ProxyLink: Sendable {
    public var scheme: String
    public var userInfo: String
    public var host: String
    public var port: Int
    public var query: [String: String]
    public var label: String

    public static func parse(_ raw: String) throws -> ProxyLink {
        let s = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !s.isEmpty else { throw ConfigError("не указана ссылка") }
        guard let c = URLComponents(string: s), let scheme = c.scheme?.lowercased() else {
            throw ConfigError("не удалось разобрать ссылку (перескок портов вида 443,2000-3000 не поддерживается)")
        }
        guard ["vless", "hysteria2", "hy2", "hysteria"].contains(scheme) else {
            throw ConfigError("неизвестная схема \(scheme)://")
        }
        guard let host = c.host, !host.isEmpty else { throw ConfigError("в ссылке нет адреса сервера") }
        guard let port = c.port else { throw ConfigError("в ссылке нет порта") }

        var user = c.user ?? ""
        if let password = c.password {
            user += ":" + password
        }
        var query: [String: String] = [:]
        for item in c.queryItems ?? [] {
            query[item.name] = item.value ?? ""
        }
        return ProxyLink(
            scheme: scheme == "hy2" ? "hysteria2" : scheme,
            userInfo: user,
            host: host.trimmingCharacters(in: CharacterSet(charactersIn: "[]")),
            port: port,
            query: query,
            label: c.fragment ?? ""
        )
    }

    func q(_ key: String) -> String? {
        guard let v = query[key], !v.isEmpty else { return nil }
        return v
    }

    func flag(_ key: String) -> Bool {
        ["1", "true", "yes"].contains(query[key]?.lowercased() ?? "")
    }

    /// SNI по умолчанию — имя хоста из ссылки, даже когда подключаемся по уже разрешённому IP.
    var sni: String? { q("sni") ?? q("peer") ?? (IPAddress.isLiteral(host) ? nil : host) }
}

enum IPAddress {
    static func isLiteral(_ s: String) -> Bool {
        var v4 = in_addr()
        var v6 = in6_addr()
        return inet_pton(AF_INET, s, &v4) == 1 || inet_pton(AF_INET6, s, &v6) == 1
    }
}
