import Foundation

/// JSON, который cep-engine передаёт встроенному Go-помощнику через root-файл 0600.
public struct Argon4Configuration: Codable, Equatable, Sendable {
    public var server: String
    public var password: String
    public var clientID: String
    public var clientSecret: String
    public var readyFile: String

    enum CodingKeys: String, CodingKey {
        case server, password
        case clientID = "client_id"
        case clientSecret = "client_secret"
        case readyFile = "ready_file"
    }

    public init(profile: Profile, resolvedIP: String, readyFile: String, underlay: Bool) throws {
        let endpoint = try Endpoint.argon4(underlay ? profile.argonServer : profile.server)
        let secret = underlay ? profile.argonPassword : profile.password
        guard !secret.isEmpty else { throw ConfigError("Argon4: не задан мастер-пароль") }
        server = "\(resolvedIP):\(endpoint.port ?? 51820)"
        password = secret
        let id = profile.clientID.trimmingCharacters(in: .whitespacesAndNewlines)
        clientID = id.isEmpty ? profile.id.uuidString : id
        clientSecret = profile.clientSecret
        self.readyFile = readyFile
    }
}

/// Результат handshake и настройки TUN, опубликованный cep-argon4.
public struct Argon4Ready: Decodable, Equatable, Sendable {
    public var interface: String
    public var address: String
    public var serverIP: String
    public var mtu: Int
    public var dns: [String]
    public var routes: [String]

    enum CodingKeys: String, CodingKey {
        case interface, address, mtu, dns, routes
        case serverIP = "server_ip"
    }

    public init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        interface = try c.decode(String.self, forKey: .interface)
        address = try c.decode(String.self, forKey: .address)
        serverIP = try c.decode(String.self, forKey: .serverIP)
        mtu = try c.decode(Int.self, forKey: .mtu)
        dns = try c.decodeIfPresent([String].self, forKey: .dns) ?? []
        routes = try c.decodeIfPresent([String].self, forKey: .routes) ?? []
    }
}
