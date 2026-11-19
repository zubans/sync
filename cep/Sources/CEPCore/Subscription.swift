import CryptoKit
import Foundation

/// Подписка провайдера: адрес, по которому панель (Remnawave, Marzban и т.п.) отдаёт список серверов.
/// Профиль выбирает из неё один сервер (`Profile.subscriptionID` + `subscriptionServer`), а при обновлении
/// подписки берёт его свежую ссылку: ключи и адреса у провайдера меняются, а выбор пользователя остаётся.
public struct Subscription: Codable, Identifiable, Hashable, Sendable {
    public var id = UUID()
    public var name: String
    public var url: String
    /// HWID вместо идентификатора этого устройства: чтобы не занимать новое место в лимите устройств
    /// подписки, можно вписать HWID уже зарегистрированного устройства. Пусто — свой HWID устройства.
    public var hwid = ""
    /// Серверы из последнего удачного обновления.
    public var servers: [SubscriptionServer] = []
    public var updatedAt: Date?
    /// Трафик и срок действия из заголовка subscription-userinfo — как их показывать пользователю.
    public var info: String?

    public init(name: String, url: String) {
        self.name = name
        self.url = url
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(UUID.self, forKey: .id)
        name = try c.decodeIfPresent(String.self, forKey: .name) ?? ""
        url = try c.decodeIfPresent(String.self, forKey: .url) ?? ""
        hwid = try c.decodeIfPresent(String.self, forKey: .hwid) ?? ""
        servers = try c.decodeIfPresent([SubscriptionServer].self, forKey: .servers) ?? []
        updatedAt = try c.decodeIfPresent(Date.self, forKey: .updatedAt)
        info = try c.decodeIfPresent(String.self, forKey: .info)
    }
}

/// Сервер из подписки: имя (метка #… ссылки) и сама ссылка.
public struct SubscriptionServer: Codable, Hashable, Sendable, Identifiable {
    public var name: String
    public var uri: String
    public var id: String { name + "\n" + uri }

    public init(name: String, uri: String) {
        self.name = name
        self.uri = uri
    }

    /// Протокол узла для этой ссылки или nil, если CEP её не умеет (trojan, ss, vmess…).
    public var proto: VPNProtocol? { SubscriptionParser.proto(ofLink: uri) }

    /// host:port — запасной ключ, если провайдер переименовал сервер.
    public var address: String? {
        guard let link = try? ProxyLink.parse(uri) else { return nil }
        return "\(link.host):\(link.port)"
    }
}

public enum SubscriptionParser {
    /// Панели выбирают формат ответа по User-Agent; с этим отдают простой список ссылок (base64 или текстом).
    public static let userAgent = "v2rayN/7.10.0"

    /// Серверы из тела ответа: base64 (обычный или url-safe, с паддингом или без) либо текст по ссылке в строке.
    /// Строки без схемы пропускаются; повторяющиеся имена получают номер, чтобы выбор сервера был однозначным.
    public static func parse(_ body: Data) -> [SubscriptionServer] {
        let raw = String(decoding: body, as: UTF8.self).trimmingCharacters(in: .whitespacesAndNewlines)
        let text = raw.contains("://") ? raw : (decodeBase64(raw) ?? raw)
        var result: [SubscriptionServer] = []
        for line in text.split(whereSeparator: \.isNewline) {
            let uri = line.trimmingCharacters(in: .whitespaces)
            guard let schemeEnd = uri.range(of: "://"), !uri[..<schemeEnd.lowerBound].isEmpty else { continue }
            var name = label(of: uri)
            if name.isEmpty { name = (try? ProxyLink.parse(uri)).map { "\($0.host):\($0.port)" } ?? uri }
            var unique = name
            var n = 2
            while result.contains(where: { $0.name == unique }) {
                unique = "\(name) (\(n))"
                n += 1
            }
            result.append(SubscriptionServer(name: unique, uri: uri))
        }
        return result
    }

    /// Метка ссылки (#…), без пробелов по краям: провайдеры оставляют их в именах.
    static func label(of uri: String) -> String {
        guard let hash = uri.firstIndex(of: "#") else { return "" }
        let fragment = String(uri[uri.index(after: hash)...])
        return (fragment.removingPercentEncoding ?? fragment).trimmingCharacters(in: .whitespaces)
    }

    static func decodeBase64(_ s: String) -> String? {
        var b = s.filter { !$0.isWhitespace }
            .replacingOccurrences(of: "-", with: "+")
            .replacingOccurrences(of: "_", with: "/")
        while b.count % 4 != 0 { b += "=" }
        guard let data = Data(base64Encoded: b), let text = String(data: data, encoding: .utf8) else { return nil }
        return text
    }

    public static func proto(ofLink uri: String) -> VPNProtocol? {
        switch uri.split(separator: ":").first?.lowercased() {
        case "vless": .vless
        case "hy2", "hysteria2": .hysteria2
        case "hysteria": .hysteria
        default: nil
        }
    }

    /// Имя подписки из заголовка profile-title (бывает «base64:…»).
    public static func title(_ header: String?) -> String? {
        guard let h = header?.trimmingCharacters(in: .whitespaces), !h.isEmpty else { return nil }
        if h.hasPrefix("base64:") { return decodeBase64(String(h.dropFirst(7)))?.trimmingCharacters(in: .whitespaces) }
        return h
    }

    /// «upload=0; download=255029556191; total=0; expire=1795851502» → «использовано 237.5 ГБ из ∞, до 27.11.2026».
    public static func userInfo(_ header: String?) -> String? {
        guard let header, !header.isEmpty else { return nil }
        var v: [String: Int64] = [:]
        for part in header.split(separator: ";") {
            let kv = part.split(separator: "=", maxSplits: 1).map { $0.trimmingCharacters(in: .whitespaces) }
            if kv.count == 2, let n = Int64(kv[1]) { v[kv[0]] = n }
        }
        guard !v.isEmpty else { return nil }
        let used = (v["upload"] ?? 0) + (v["download"] ?? 0)
        let total = v["total"] ?? 0
        let gb = { (b: Int64) in String(format: "%.1f ГБ", Double(b) / 1_073_741_824) }
        var s = "использовано \(gb(used)) из \(total > 0 ? gb(total) : "∞")"
        if let expire = v["expire"], expire > 0 {
            let f = DateFormatter()
            f.dateFormat = "dd.MM.yyyy"
            s += ", до \(f.string(from: Date(timeIntervalSince1970: TimeInterval(expire))))"
        }
        return s
    }

    /// HWID устройства для панели: постоянный, но не раскрывает сам идентификатор (sha256, 32 hex).
    public static func hwid(seed: String) -> String {
        SHA256.hash(data: Data("cep-hwid:\(seed)".utf8)).map { String(format: "%02x", $0) }.joined().prefix(32).description
    }

    /// Сервер подписки для профиля: по имени, а если провайдер переименовал — по старому адресу host:port.
    public static func match(_ servers: [SubscriptionServer], name: String, previousURI: String) -> SubscriptionServer? {
        if let s = servers.first(where: { $0.name == name }) { return s }
        guard let old = try? ProxyLink.parse(previousURI) else { return nil }
        return servers.first { $0.address == "\(old.host):\(old.port)" }
    }
}
