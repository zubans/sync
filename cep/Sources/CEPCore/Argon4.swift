import Foundation

/// JSON, который cep-engine передаёт встроенному Go-помощнику через root-файл 0600.
public struct Argon4Configuration: Codable, Equatable, Sendable {
    public var server: String
    public var password: String
    public var clientID: String
    public var clientSecret: String
    public var readyFile: String
    public var mode: String?
    public var target: String?
    public var proto: String?
    public var listen: String?
    public var protocolVersion: Int?
    public var paddingProfile: String?
    public var firstPacketProfile: String?
    public var pathMTU: Int?

    enum CodingKeys: String, CodingKey {
        case server, password, mode, target, proto, listen
        case clientID = "client_id"
        case clientSecret = "client_secret"
        case readyFile = "ready_file"
        case protocolVersion = "protocol_version"
        case paddingProfile = "padding_profile"
        case firstPacketProfile = "first_packet_profile"
        case pathMTU = "path_mtu"
    }

    public init(
        profile: Profile,
        resolvedIP: String,
        readyFile: String,
        underlay: Bool,
        mode: String? = nil,
        target: String? = nil,
        proto: String? = nil,
        listen: String? = nil,
        portOverride: Int? = nil
    ) throws {
        if let portOverride {
            server = "\(resolvedIP):\(portOverride)"
        } else if underlay {
            server = "\(resolvedIP):51820"
        } else {
            let endpoint = try Endpoint.argon4(profile.server)
            server = "\(resolvedIP):\(endpoint.port ?? 51820)"
        }
        let secret = underlay ? profile.argonPassword : profile.password
        guard !secret.isEmpty else { throw ConfigError("Argon4: не задан мастер-пароль") }
        password = secret
        let id = profile.clientID.trimmingCharacters(in: .whitespacesAndNewlines)
        clientID = id.isEmpty ? profile.id.uuidString : id
        clientSecret = profile.clientSecret
        self.readyFile = readyFile
        self.mode = mode
        self.target = target
        self.proto = proto
        self.listen = listen
        self.protocolVersion = profile.argonProtocolVersion
        self.paddingProfile = profile.argonPaddingProfile
        self.firstPacketProfile = profile.argonFirstPacketProfile
        self.pathMTU = profile.argonPathMTU
    }
}

/// Результат handshake и настройки TUN или локального L4-транзита, опубликованный cep-argon4.
public struct Argon4Ready: Decodable, Equatable, Sendable {
    public var mode: String
    public var listen: String
    public var port: Int
    public var target: String
    public var interface: String
    public var address: String
    public var serverIP: String
    public var mtu: Int
    public var dns: [String]
    public var routes: [String]

    enum CodingKeys: String, CodingKey {
        case mode, listen, port, target, interface, address, mtu, dns, routes
        case serverIP = "server_ip"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        mode = try c.decodeIfPresent(String.self, forKey: .mode) ?? "tunnel"
        listen = try c.decodeIfPresent(String.self, forKey: .listen) ?? ""
        port = try c.decodeIfPresent(Int.self, forKey: .port) ?? 0
        target = try c.decodeIfPresent(String.self, forKey: .target) ?? ""
        interface = try c.decodeIfPresent(String.self, forKey: .interface) ?? ""
        address = try c.decodeIfPresent(String.self, forKey: .address) ?? ""
        serverIP = try c.decodeIfPresent(String.self, forKey: .serverIP) ?? ""
        mtu = try c.decodeIfPresent(Int.self, forKey: .mtu) ?? 1380
        dns = try c.decodeIfPresent([String].self, forKey: .dns) ?? []
        routes = try c.decodeIfPresent([String].self, forKey: .routes) ?? []
    }
}

public enum ArgonTransportProto {
    /// Определяет L4-протокол ("tcp", "udp" или "") для передачи в Argon4 transit / auto.
    public static func of(_ profile: Profile) -> String {
        switch profile.proto {
        case .vless, .openconnect, .sstp:
            return "tcp"
        case .hysteria2, .hysteria, .amneziawg, .argon4:
            return "udp"
        case .openvpn:
            if let proto = OpenVPNConfig.firstRemote(profile.config)?.proto?.lowercased() {
                if proto.hasPrefix("tcp") { return "tcp" }
                if proto.hasPrefix("udp") { return "udp" }
            }
            return ""
        case .transit:
            return ""
        }
    }
}
