import Foundation

/// Подмена DNS основного сетевого сервиса через динамическое хранилище configd (State:).
/// Это слой DHCP: после перезагрузки или продления аренды он возвращается сам, настройки «Системных настроек» не трогаются.
public final class DNSOverride {
    private static let scutil = "/usr/sbin/scutil"
    private let key: String
    private let original: ScutilDict?
    private let servers: [String]

    public init?(servers: [String]) {
        guard !servers.isEmpty,
              let service = ScutilDict.parse(Self.show("State:/Network/Global/IPv4")).scalars["PrimaryService"]
        else { return nil }
        key = "State:/Network/Service/\(service)/DNS"
        let current = ScutilDict.parse(Self.show(key))
        original = current.isEmpty ? nil : current
        self.servers = servers
    }

    public func apply() {
        var dict = original ?? ScutilDict()
        dict.arrays["ServerAddresses"] = servers
        Self.set(key, dict)
        Self.flush()
    }

    /// DHCP мог перезаписать ключ — возвращаем свои серверы.
    public func reapplyIfChanged() {
        if ScutilDict.parse(Self.show(key)).arrays["ServerAddresses"] != servers {
            apply()
        }
    }

    public func restore() {
        if let original {
            Self.set(key, original)
        } else {
            Shell.run(Self.scutil, [], input: "remove \(key)\n")
        }
        Self.flush()
    }

    private static func show(_ key: String) -> String {
        Shell.run(scutil, [], input: "show \(key)\n").output
    }

    private static func set(_ key: String, _ dict: ScutilDict) {
        var script = "d.init\n"
        for (k, v) in dict.scalars { script += "d.add \(k) \(v)\n" }
        for (k, v) in dict.arrays { script += "d.add \(k) * \(v.joined(separator: " "))\n" }
        script += "set \(key)\n"
        Shell.run(scutil, [], input: script)
    }

    private static func flush() {
        Shell.run("/usr/bin/dscacheutil", ["-flushcache"])
        Shell.run("/usr/bin/killall", ["-HUP", "mDNSResponder"])
    }
}

/// Плоский словарь из вывода `scutil show`: скаляры и массивы строк, вложенные словари пропускаются.
public struct ScutilDict: Equatable {
    public var scalars: [String: String] = [:]
    public var arrays: [String: [String]] = [:]

    public var isEmpty: Bool { scalars.isEmpty && arrays.isEmpty }

    public static func parse(_ text: String) -> ScutilDict {
        var d = ScutilDict()
        var arrayKey: String?
        var depth = 0
        for raw in text.components(separatedBy: .newlines) {
            let line = raw.trimmingCharacters(in: .whitespaces)
            if line.hasPrefix("<dictionary>") { depth += 1; continue }
            if line == "}" {
                if arrayKey != nil { arrayKey = nil } else { depth -= 1 }
                continue
            }
            guard let sep = line.range(of: " : ") else { continue }
            let key = String(line[..<sep.lowerBound])
            let value = String(line[sep.upperBound...])
            if let ak = arrayKey {
                d.arrays[ak, default: []].append(value)
            } else if value == "<array> {" {
                if depth == 1 { arrayKey = key; d.arrays[key] = [] } else { arrayKey = "\u{0}skip" }
            } else if value == "<dictionary> {" {
                depth += 1
            } else if depth == 1 {
                d.scalars[key] = value
            }
        }
        d.arrays.removeValue(forKey: "\u{0}skip")
        return d
    }
}

/// Внутренний DNS узла только для его доменов (SupplementalMatchDomains): имена вида host.corp идут
/// к DNS за узлом, остальные запросы — к DNS цепочки.
public final class SplitDNS {
    private let key: String

    public init?(id: String, servers: [String], domains: [String]) {
        guard !servers.isEmpty, !domains.isEmpty else { return nil }
        key = "State:/Network/Service/cep-\(id)/DNS"
        let script = "d.init\n"
            + "d.add ServerAddresses * \(servers.joined(separator: " "))\n"
            + "d.add SupplementalMatchDomains * \(domains.joined(separator: " "))\n"
            + "set \(key)\n"
        Shell.run("/usr/sbin/scutil", [], input: script)
    }

    public func remove() {
        Shell.run("/usr/sbin/scutil", [], input: "remove \(key)\n")
    }
}
