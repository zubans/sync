import Foundation

/// Внутренние сети узла: что прислал сервер (split-include, DNS, домен) и что задано в профиле.
public struct InnerNetwork: Equatable, Sendable {
    public var routes: [String] = []
    public var dns: [String] = []
    public var domains: [String] = []

    public init(routes: [String] = [], dns: [String] = [], domains: [String] = []) {
        self.routes = routes
        self.dns = dns
        self.domains = domains
    }

    public var isEmpty: Bool { routes.isEmpty && dns.isEmpty && domains.isEmpty }

    /// Маршрут по умолчанию от сервера не берём: куда идёт весь трафик, решает цепочка.
    mutating func add(route: String) {
        if route.hasSuffix("/0") || routes.contains(route) { return }
        routes.append(route)
    }
}

public enum RouteList {
    /// Сети из поля профиля: через запятую, пробел или с новой строки; адрес без маски — /32.
    public static func parse(_ text: String) throws -> [String] {
        var result: [String] = []
        for item in text.split(whereSeparator: { ",; \n\t".contains($0) }) {
            let s = String(item)
            let parts = s.split(separator: "/", omittingEmptySubsequences: false)
            guard parts.count <= 2, isIPv4(String(parts[0])) else {
                throw ConfigError("«\(s)» — не IPv4-сеть; пример: 192.168.1.119 или 10.0.0.0/8")
            }
            let len = parts.count == 2 ? Int(parts[1]) : 32
            guard let len, (1...32).contains(len) else {
                throw ConfigError("«\(s)»: маска должна быть от /1 до /32")
            }
            let cidr = "\(parts[0])/\(len)"
            if !result.contains(cidr) { result.append(cidr) }
        }
        return result
    }

    /// 255.255.255.0 → 24; неканоническая маска — nil.
    public static func maskLength(_ mask: String) -> Int? {
        var a = in_addr()
        guard inet_pton(AF_INET, mask, &a) == 1 else { return nil }
        let bits = UInt32(bigEndian: a.s_addr)
        let len = bits.nonzeroBitCount
        let canonical: UInt32 = len == 0 ? 0 : ~UInt32(0) << (32 - len)
        return bits == canonical ? len : nil
    }

    /// Файл, который пишет скрипт OpenConnect: строки INC=, DNS=, DOMAIN=.
    public static func fromOpenConnect(_ text: String) -> InnerNetwork {
        var net = InnerNetwork()
        for line in text.split(separator: "\n") {
            guard let eq = line.firstIndex(of: "=") else { continue }
            let key = line[..<eq]
            let value = line[line.index(after: eq)...].trimmingCharacters(in: .whitespaces)
            switch key {
            case "INC":
                if (try? parse(value)) != nil { net.add(route: value) }
            case "DNS":
                net.dns += value.split(separator: " ").map(String.init).filter(isIPv4)
            case "DOMAIN":
                // ocserv кладёт в default-domain свой адрес (OC_DOMAIN=94.103.9.172) — это не домен для split DNS
                net.domains += value.split(whereSeparator: { $0 == " " || $0 == "," }).map(String.init)
                    .filter { !isIPv4($0) && $0.contains(where: \.isLetter) }
            default:
                break
            }
        }
        return net
    }

    /// Сети, полученные от сервера Argon4. Полный туннель включает движок отдельно после всей цепочки.
    public static func fromArgon4(routes: [String]) -> InnerNetwork {
        var net = InnerNetwork()
        for route in routes where (try? parse(route)) != nil {
            net.add(route: route)
        }
        return net
    }

    /// PUSH_REPLY из журнала OpenVPN (verb 3) и строки route из .ovpn.
    public static func fromOpenVPN(log: String, config: String) -> InnerNetwork {
        var net = InnerNetwork()
        var directives: [String] = config.components(separatedBy: .newlines)
            .map { $0.trimmingCharacters(in: .whitespaces) }
            .filter { $0.hasPrefix("route ") }
        if let start = log.range(of: "PUSH_REPLY,", options: .backwards) {
            let tail = log[start.upperBound...]
            let reply = tail.prefix { $0 != "'" && $0 != "\n" }
            directives += reply.split(separator: ",").map { $0.trimmingCharacters(in: .whitespaces) }
        }
        for d in directives {
            let w = d.split(separator: " ").map(String.init)
            if w.first == "route", w.count >= 2, isIPv4(w[1]) {
                let len = w.count >= 3 ? maskLength(w[2]) : 32
                if let len, len > 0 { net.add(route: "\(w[1])/\(len)") }
            } else if w.count >= 3, w[0] == "dhcp-option" {
                if w[1] == "DNS", isIPv4(w[2]) { net.dns.append(w[2]) }
                if w[1] == "DOMAIN" || w[1] == "DOMAIN-SEARCH" { net.domains.append(w[2]) }
            }
        }
        return net
    }

    static func isIPv4(_ s: String) -> Bool {
        var a = in_addr()
        return inet_pton(AF_INET, s, &a) == 1
    }
}
