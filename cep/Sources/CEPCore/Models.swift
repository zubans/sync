import Foundation

public enum VPNProtocol: String, Codable, CaseIterable, Identifiable, Sendable {
    case vless
    case hysteria2
    case hysteria
    case amneziawg
    case openvpn
    case openconnect
    case argon4
    case transit
    case sstp

    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .vless: "VLESS"
        case .hysteria2: "Hysteria 2"
        case .hysteria: "Hysteria (v1)"
        case .amneziawg: "AmneziaWG"
        case .openvpn: "OpenVPN"
        case .openconnect: "OpenConnect"
        case .argon4: "Argon4"
        case .transit: "Транзитный шлюз"
        case .sstp: "SSTP"
        }
    }

    /// Протокол-прокси: поднимается через TUN sing-box, а не собственным интерфейсом.
    public var isProxy: Bool {
        switch self {
        case .vless, .hysteria2, .hysteria: true
        default: false
        }
    }

    public var requiredTools: [Tool] {
        switch self {
        case .vless: [.xray, .singBox]
        case .hysteria2, .hysteria: [.singBox]
        case .amneziawg: [.amneziawgGo, .awg]
        case .openvpn: [.openvpn]
        case .openconnect: [.openconnect]
        case .argon4, .transit: [.cepArgon4]
        case .sstp: [.sstpc]
        }
    }
}

public enum OpenConnectFlavor: String, Codable, CaseIterable, Identifiable, Sendable {
    case anyconnect, gp, nc, pulse, fortinet, array
    public var id: String { rawValue }

    public var title: String {
        switch self {
        case .anyconnect: "Cisco AnyConnect / ocserv"
        case .gp: "Palo Alto GlobalProtect"
        case .nc: "Juniper Network Connect"
        case .pulse: "Pulse Connect Secure"
        case .fortinet: "Fortinet"
        case .array: "Array Networks"
        }
    }
}

public struct Profile: Codable, Identifiable, Hashable, Sendable {
    public var id = UUID()
    public var name: String
    public var proto: VPNProtocol
    /// Ссылка vless:// hysteria2:// hy2:// hysteria://
    public var uri = ""
    /// Текст .ovpn или .conf AmneziaWG.
    public var config = ""
    /// host или host:port для Argon4, OpenConnect, SSTP.
    public var server = ""
    public var username = ""
    /// Для Argon4 — мастер-пароль протокола.
    public var password = ""
    /// Владелец Argon4-сессии; пустой clientID заменяется на id профиля.
    public var clientID = ""
    public var clientSecret = ""
    public var openconnectFlavor = OpenConnectFlavor.anyconnect
    /// pin-sha256:... или sha1 отпечаток сертификата сервера OpenConnect.
    public var serverCert = ""
    public var authGroup = ""
    public var ignoreCertErrors = false
    /// Сети, которые идут через этот узел, а не дальше по цепочке и не в локальную сеть: «192.168.1.119, 10.0.0.0/8».
    public var routes = ""
    /// Оборачивать подключение к серверу узла в туннель/транзит Argon4 (порт 51820 или предыдущий транзитный шлюз).
    public var useArgon: Bool = false
    /// Мастер-пароль Argon2id.
    public var argonPassword: String = ""
    /// Явная версия протокола Argon4 (по умолчанию nil -> v5).
    public var argonProtocolVersion: Int? = nil
    /// Профиль padding Argon4 ("balanced", "compact", "wide").
    public var argonPaddingProfile: String? = nil
    /// Отдельный профиль padding первого handshake-пакета Argon4.
    public var argonFirstPacketProfile: String? = nil
    /// Внешний path MTU для расчёта бюджета padding Argon4.
    public var argonPathMTU: Int? = nil
    /// Максимум попыток UDP-рукопожатия Argon4.
    public var argonHandshakeMaxAttempts: Int? = nil
    /// Начальный таймаут попытки handshake Argon4 (мс).
    public var argonHandshakeInitialTimeoutMs: Int? = nil
    /// Потолок таймаута одной попытки handshake Argon4 (мс).
    public var argonHandshakeMaxTimeoutMs: Int? = nil
    /// Минимальный простой перед отправкой keepalive Argon4 (с).
    public var argonKeepaliveMinIdleSec: Int? = nil
    /// Максимальный простой перед отправкой keepalive Argon4 (с).
    public var argonKeepaliveMaxIdleSec: Int? = nil
    /// Поведенческий профиль трафика Argon4 (nil/"" — выкл., "baseline", "video-call").
    public var argonBehaviorProfile: String? = nil
    /// Режим пониженного энергопотребления для профилей Argon4.
    public var argonLowPowerMode: Bool = false
    /// Лимит длительности профиля video-call в секундах (по умолчанию 7200).
    public var argonVideoCallMaxDurationSec: Int? = nil

    public init(name: String, proto: VPNProtocol) {
        self.name = name
        self.proto = proto
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(UUID.self, forKey: .id)
        name = try c.decode(String.self, forKey: .name)
        proto = try c.decode(VPNProtocol.self, forKey: .proto)
        uri = try c.decodeIfPresent(String.self, forKey: .uri) ?? ""
        config = try c.decodeIfPresent(String.self, forKey: .config) ?? ""
        server = try c.decodeIfPresent(String.self, forKey: .server) ?? ""
        username = try c.decodeIfPresent(String.self, forKey: .username) ?? ""
        password = try c.decodeIfPresent(String.self, forKey: .password) ?? ""
        clientID = try c.decodeIfPresent(String.self, forKey: .clientID) ?? ""
        clientSecret = try c.decodeIfPresent(String.self, forKey: .clientSecret) ?? ""
        openconnectFlavor = try c.decodeIfPresent(OpenConnectFlavor.self, forKey: .openconnectFlavor) ?? .anyconnect
        serverCert = try c.decodeIfPresent(String.self, forKey: .serverCert) ?? ""
        authGroup = try c.decodeIfPresent(String.self, forKey: .authGroup) ?? ""
        ignoreCertErrors = try c.decodeIfPresent(Bool.self, forKey: .ignoreCertErrors) ?? false
        routes = try c.decodeIfPresent(String.self, forKey: .routes) ?? ""
        useArgon = try c.decodeIfPresent(Bool.self, forKey: .useArgon) ?? false
        argonPassword = try c.decodeIfPresent(String.self, forKey: .argonPassword) ?? ""
        argonProtocolVersion = try c.decodeIfPresent(Int.self, forKey: .argonProtocolVersion)
        argonPaddingProfile = try c.decodeIfPresent(String.self, forKey: .argonPaddingProfile)
        argonFirstPacketProfile = try c.decodeIfPresent(String.self, forKey: .argonFirstPacketProfile)
        argonPathMTU = try c.decodeIfPresent(Int.self, forKey: .argonPathMTU)
        argonHandshakeMaxAttempts = try c.decodeIfPresent(Int.self, forKey: .argonHandshakeMaxAttempts)
        argonHandshakeInitialTimeoutMs = try c.decodeIfPresent(Int.self, forKey: .argonHandshakeInitialTimeoutMs)
        argonHandshakeMaxTimeoutMs = try c.decodeIfPresent(Int.self, forKey: .argonHandshakeMaxTimeoutMs)
        argonKeepaliveMinIdleSec = try c.decodeIfPresent(Int.self, forKey: .argonKeepaliveMinIdleSec)
        argonKeepaliveMaxIdleSec = try c.decodeIfPresent(Int.self, forKey: .argonKeepaliveMaxIdleSec)
        argonBehaviorProfile = try c.decodeIfPresent(String.self, forKey: .argonBehaviorProfile)
        argonLowPowerMode = try c.decodeIfPresent(Bool.self, forKey: .argonLowPowerMode) ?? false
        argonVideoCallMaxDurationSec = try c.decodeIfPresent(Int.self, forKey: .argonVideoCallMaxDurationSec)
    }
}

public struct Chain: Codable, Identifiable, Hashable, Sendable {
    public var id = UUID()
    public var name: String
    public var hops: [UUID] = []
    public var dns: [String] = ["1.1.1.1", "8.8.8.8"]
    public var blockIPv6 = true

    public init(name: String) {
        self.name = name
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id = try c.decode(UUID.self, forKey: .id)
        name = try c.decode(String.self, forKey: .name)
        hops = try c.decodeIfPresent([UUID].self, forKey: .hops) ?? []
        dns = try c.decodeIfPresent([String].self, forKey: .dns) ?? ["1.1.1.1", "8.8.8.8"]
        blockIPv6 = try c.decodeIfPresent(Bool.self, forKey: .blockIPv6) ?? true
    }
}

/// Задание для движка: цепочка с развёрнутыми профилями.
public struct Plan: Codable, Sendable {
    public var chainName: String
    public var hops: [Profile]
    public var dns: [String]
    public var blockIPv6: Bool

    public init(chain: Chain, profiles: [Profile]) {
        chainName = chain.name
        hops = chain.hops.compactMap { id in profiles.first { $0.id == id } }
        dns = chain.dns
        blockIPv6 = chain.blockIPv6
    }
}

public enum ChainValidation {
    /// Ошибки, из-за которых цепочку не запустить.
    public static func problems(_ plan: Plan) -> [String] {
        var result: [String] = []
        if plan.hops.isEmpty {
            result.append("В цепочке нет ни одного узла")
        }
        for (index, hop) in plan.hops.enumerated() {
            do {
                _ = try Endpoint.of(hop)
                _ = try RouteList.parse(hop.routes)
                if hop.proto == .argon4 && hop.password.isEmpty {
                    throw ConfigError("Argon4: не задан мастер-пароль")
                }
                if hop.useArgon {
                    if hop.proto == .argon4 || hop.proto == .transit {
                        throw ConfigError("Argon4-надстройка не нужна внутри профиля \(hop.proto.title)")
                    }
                    if hop.argonPassword.isEmpty {
                        throw ConfigError("Argon4-надстройка: не задан мастер-пароль")
                    }
                }
                if let behavior = hop.argonBehaviorProfile?.trimmingCharacters(in: .whitespacesAndNewlines), !behavior.isEmpty {
                    guard behavior == "baseline" || behavior == "video-call" else {
                        throw ConfigError("Неизвестный поведенческий профиль Argon4 «\(behavior)»")
                    }
                    if hop.argonProtocolVersion == 4 {
                        throw ConfigError("Поведенческие профили Argon4 требуют версию v5 (недоступны в v4)")
                    }
                }
                if hop.proto == .transit {
                    if index == plan.hops.count - 1 {
                        throw ConfigError("Транзитный шлюз «\(hop.name)» не может быть последним узлом цепочки")
                    }
                    if hop.password.isEmpty {
                        if let behavior = hop.argonBehaviorProfile?.trimmingCharacters(in: .whitespacesAndNewlines), !behavior.isEmpty {
                            throw ConfigError("Слепой транзитный шлюз не поддерживает поведенческие профили Argon4")
                        }
                        let next = plan.hops[index + 1]
                        if next.proto != .argon4 && !next.useArgon {
                            throw ConfigError("Для транзитного шлюза без пароля следующий узел должен использовать Argon4")
                        }
                    } else if hop.argonBehaviorProfile == "video-call" {
                        let next = plan.hops[index + 1]
                        let nextProto = next.useArgon ? "udp" : ArgonTransportProto.of(next)
                        if nextProto != "udp" {
                            throw ConfigError("Профиль «video-call» в транзитном шлюзе поддерживается только для UDP-целей")
                        }
                    }
                }
            } catch {
                result.append("«\(hop.name)»: \(error.localizedDescription)")
            }
        }
        return result
    }
}
