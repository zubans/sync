import Foundation

public enum OpenVPNConfig {
    /// Строки вне inline-блоков (<ca>…</ca> и т.п.) без комментариев.
    private static func directives(_ config: String) -> [(index: Int, words: [String])] {
        var result: [(Int, [String])] = []
        var inBlock: String?
        for (i, raw) in config.components(separatedBy: .newlines).enumerated() {
            let line = raw.trimmingCharacters(in: .whitespaces)
            if let block = inBlock {
                if line.lowercased() == "</\(block)>" { inBlock = nil }
                continue
            }
            if line.hasPrefix("<"), line.hasSuffix(">"), !line.hasPrefix("</") {
                inBlock = String(line.dropFirst().dropLast()).lowercased()
                continue
            }
            if line.isEmpty || line.hasPrefix("#") || line.hasPrefix(";") { continue }
            result.append((i, line.split(whereSeparator: { $0 == " " || $0 == "\t" }).map(String.init)))
        }
        return result
    }

    public static func firstRemote(_ config: String) -> (host: String, port: Int, proto: String?)? {
        let lines = directives(config)
        let defaultPort = lines.first { $0.words.first == "port" }.flatMap { Int($0.words.dropFirst().first ?? "") } ?? 1194
        guard let remote = lines.first(where: { $0.words.first == "remote" && $0.words.count >= 2 }) else { return nil }
        let w = remote.words
        let port = w.count >= 3 ? Int(w[2]) ?? defaultPort : defaultPort
        return (w[1], port, w.count >= 4 ? w[3] : nil)
    }

    /// Оставляет один remote с уже разрешённым IP: сервер должен совпасть с тем, до которого проложен маршрут.
    public static func pinRemote(_ config: String, ip: String) throws -> String {
        guard let remote = firstRemote(config) else { throw ConfigError("в конфиге OpenVPN нет строки remote") }
        let lower = config.lowercased()
        if lower.contains("<connection>") {
            throw ConfigError("блоки <connection> не поддерживаются — оставьте в .ovpn обычные строки remote")
        }
        let drop = Set(directives(config)
            .filter { ["remote", "remote-random", "remote-random-hostname"].contains($0.words.first ?? "") }
            .map(\.index))
        var lines = config.components(separatedBy: .newlines).enumerated()
            .filter { !drop.contains($0.offset) }
            .map(\.element)
        var pinned = "remote \(ip) \(remote.port)"
        if let proto = remote.proto { pinned += " \(proto)" }
        lines.insert(pinned, at: 0)
        return lines.joined(separator: "\n")
    }
}

/// Конфиг AmneziaWG в формате wg-quick: [Interface] + [Peer].
public struct AWGConfig: Sendable {
    public var addresses: [String] = []
    public var mtu: Int?
    public var endpoint: Endpoint
    private var lines: [String]

    /// Ключи wg-quick, которые не понимает `awg setconf`.
    static let quickKeys: Set<String> = ["address", "dns", "mtu", "table", "preup", "postup", "predown", "postdown", "saveconfig"]

    public static func parse(_ text: String) throws -> AWGConfig {
        var addresses: [String] = []
        var mtu: Int?
        var endpoint: Endpoint?
        var section = ""
        var kept: [String] = []
        for raw in text.components(separatedBy: .newlines) {
            let line = raw.trimmingCharacters(in: .whitespaces)
            if line.hasPrefix("[") {
                section = line.lowercased()
                kept.append(line)
                continue
            }
            guard let eq = line.firstIndex(of: "="), !line.hasPrefix("#") else {
                kept.append(raw)
                continue
            }
            let key = line[..<eq].trimmingCharacters(in: .whitespaces).lowercased()
            let value = line[line.index(after: eq)...].trimmingCharacters(in: .whitespaces)
            if section == "[interface]", quickKeys.contains(key) {
                if key == "address" {
                    addresses += value.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }
                } else if key == "mtu" {
                    mtu = Int(value)
                }
                continue
            }
            if section == "[peer]", key == "endpoint", endpoint == nil {
                endpoint = try Endpoint.parseHostPort(value)
            }
            kept.append(raw)
        }
        guard let endpoint else { throw ConfigError("в конфиге AmneziaWG нет Endpoint") }
        guard endpoint.port != nil else { throw ConfigError("в Endpoint AmneziaWG нет порта") }
        return AWGConfig(addresses: addresses, mtu: mtu, endpoint: endpoint, lines: kept)
    }

    public var ipv4Address: String? {
        addresses.first { !$0.contains(":") }.map { String($0.split(separator: "/")[0]) }
    }

    /// Текст для `awg setconf`: без ключей wg-quick, с Endpoint на разрешённый IP.
    public func setconfText(endpointIP: String) -> String {
        var section = ""
        return lines.map { raw in
            let line = raw.trimmingCharacters(in: .whitespaces)
            if line.hasPrefix("[") { section = line.lowercased() }
            if section == "[peer]", let eq = line.firstIndex(of: "="),
               line[..<eq].trimmingCharacters(in: .whitespaces).lowercased() == "endpoint" {
                let host = endpointIP.contains(":") ? "[\(endpointIP)]" : endpointIP
                return "Endpoint = \(host):\(endpoint.port ?? 51820)"
            }
            return raw
        }.joined(separator: "\n")
    }
}
