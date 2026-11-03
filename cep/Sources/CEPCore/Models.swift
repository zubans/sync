import Foundation

public enum VPNProtocol: String, Codable, CaseIterable, Identifiable, Sendable {
    case vless
    case hysteria2
    case hysteria
    case amneziawg
    case openvpn
    case openconnect
    case argon4
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
        case .argon4: [.cepArgon4]
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
    /// Оборачивать подключение к серверу узла в туннель Argon4.
    public var useArgon: Bool = false
    /// Адрес сервера Argon4 («host:51820»).
    public var argonServer: String = ""
    /// Мастер-пароль Argon2id.
    public var argonPassword: String = ""

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
        argonServer = try c.decodeIfPresent(String.self, forKey: .argonServer) ?? ""
        argonPassword = try c.decodeIfPresent(String.self, forKey: .argonPassword) ?? ""
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
        for hop in plan.hops {
            do {
                _ = try Endpoint.of(hop)
                _ = try RouteList.parse(hop.routes)
                if hop.proto == .argon4 && hop.password.isEmpty {
                    throw ConfigError("Argon4: не задан мастер-пароль")
                }
                if hop.useArgon {
                    if hop.proto == .argon4 {
                        throw ConfigError("Argon4-надстройка не нужна внутри профиля Argon4")
                    }
                    if hop.argonPassword.isEmpty {
                        throw ConfigError("Argon4-надстройка: не задан мастер-пароль")
                    }
                    _ = try Endpoint.argon4(hop.argonServer)
                }
            } catch {
                result.append("«\(hop.name)»: \(error.localizedDescription)")
            }
        }
        return result
    }
}
