@testable import CEPCore
import XCTest

final class Argon4Tests: XCTestCase {
    func testEndpointDefaultsAndValidation() throws {
        XCTAssertEqual(try Endpoint.argon4("vpn.example.com"), Endpoint(host: "vpn.example.com", port: 51820))
        XCTAssertEqual(try Endpoint.argon4("argon4://vpn.example.com:4443"), Endpoint(host: "vpn.example.com", port: 4443))
        XCTAssertEqual(try Endpoint.argon4("udp://192.0.2.10"), Endpoint(host: "192.0.2.10", port: 51820))
        XCTAssertThrowsError(try Endpoint.argon4("https://vpn.example.com"))
        XCTAssertThrowsError(try Endpoint.argon4("vpn.example.com:70000"))
        XCTAssertThrowsError(try Endpoint.argon4("user@vpn.example.com"))
    }

    func testStandaloneConfigurationUsesProfileIDAndSnakeCase() throws {
        var profile = Profile(name: "Argon", proto: .argon4)
        profile.server = "vpn.example.com"
        profile.password = "master-secret"
        profile.clientSecret = "owner-secret"
        let configuration = try Argon4Configuration(
            profile: profile, resolvedIP: "192.0.2.10", readyFile: "/var/run/cep/ready.json", underlay: false
        )
        XCTAssertEqual(configuration.server, "192.0.2.10:51820")
        XCTAssertEqual(configuration.password, "master-secret")
        XCTAssertEqual(configuration.clientID, profile.id.uuidString)
        XCTAssertEqual(configuration.clientSecret, "owner-secret")

        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: JSONEncoder().encode(configuration)) as? [String: Any])
        XCTAssertEqual(object["client_id"] as? String, profile.id.uuidString)
        XCTAssertEqual(object["client_secret"] as? String, "owner-secret")
        XCTAssertEqual(object["ready_file"] as? String, "/var/run/cep/ready.json")
        XCTAssertNil(object["clientID"])
    }

    func testUnderlayUsesNodeIPAndDefaultPortWithoutArgonServer() throws {
        var profile = Profile(name: "Wrapped", proto: .hysteria2)
        profile.uri = "hy2://pw@192.0.2.20:443"
        profile.useArgon = true
        profile.argonPassword = "argon-secret"
        profile.clientID = "owner-1"
        let configuration = try Argon4Configuration(
            profile: profile,
            resolvedIP: "192.0.2.20",
            readyFile: "ready",
            underlay: true,
            mode: "auto",
            target: "192.0.2.20:443",
            proto: "udp",
            listen: "127.0.0.1:0"
        )
        XCTAssertEqual(configuration.server, "192.0.2.20:51820")
        XCTAssertEqual(configuration.password, "argon-secret")
        XCTAssertEqual(configuration.clientID, "owner-1")
        XCTAssertEqual(configuration.mode, "auto")
        XCTAssertEqual(configuration.target, "192.0.2.20:443")
        XCTAssertEqual(configuration.proto, "udp")
    }

    func testChainValidationRequiresSecretsAndValidatesTransitRules() {
        var chain = Chain(name: "test")
        var standalone = Profile(name: "Argon", proto: .argon4)
        standalone.server = "192.0.2.10"
        chain.hops = [standalone.id]
        XCTAssertTrue(ChainValidation.problems(Plan(chain: chain, profiles: [standalone])).contains {
            $0.contains("мастер-пароль")
        })

        standalone.password = "secret"
        standalone.useArgon = true
        standalone.argonPassword = "another-secret"
        XCTAssertTrue(ChainValidation.problems(Plan(chain: chain, profiles: [standalone])).contains {
            $0.contains("не нужна")
        })

        // Транзитный шлюз не может быть последним узлом.
        var transit = Profile(name: "Relay", proto: .transit)
        transit.server = "relay.example.com"
        var lastChain = Chain(name: "bad-last")
        lastChain.hops = [transit.id]
        XCTAssertTrue(ChainValidation.problems(Plan(chain: lastChain, profiles: [transit])).contains {
            $0.contains("последним узлом")
        })

        // Слепой транзит (без пароля) требует Argon4 у следующего узла.
        var vless = Profile(name: "Exit", proto: .vless)
        vless.uri = "vless://11111111-1111-1111-1111-111111111111@192.0.2.99:443?security=tls"
        var blindChain = Chain(name: "blind-bad")
        blindChain.hops = [transit.id, vless.id]
        XCTAssertTrue(ChainValidation.problems(Plan(chain: blindChain, profiles: [transit, vless])).contains {
            $0.contains("должен использовать Argon4")
        })

        // С useArgon на следующем узле слепой транзит валиден.
        vless.useArgon = true
        vless.argonPassword = "node-password"
        XCTAssertTrue(ChainValidation.problems(Plan(chain: blindChain, profiles: [transit, vless])).isEmpty)

        // Транзит с паролем валиден перед обычным VLESS.
        vless.useArgon = false
        vless.argonPassword = ""
        transit.password = "transit-password"
        XCTAssertTrue(ChainValidation.problems(Plan(chain: blindChain, profiles: [transit, vless])).isEmpty)
    }

    func testReadyAllowsMissingOptionalRoutesAndDecodesTransitReady() throws {
        let json = #"{"interface":"utun9","address":"10.44.0.2","server_ip":"10.44.0.1","mtu":1380}"#
        let ready = try JSONDecoder().decode(Argon4Ready.self, from: Data(json.utf8))
        XCTAssertEqual(ready.mode, "tunnel")
        XCTAssertEqual(ready.interface, "utun9")
        XCTAssertEqual(ready.address, "10.44.0.2")
        XCTAssertTrue(ready.dns.isEmpty)
        XCTAssertTrue(ready.routes.isEmpty)

        let transitJson = #"{"mode":"transit","listen":"127.0.0.1:54321","port":54321,"target":"192.0.2.99:443","mtu":1380}"#
        let transitReady = try JSONDecoder().decode(Argon4Ready.self, from: Data(transitJson.utf8))
        XCTAssertEqual(transitReady.mode, "transit")
        XCTAssertEqual(transitReady.port, 54321)
        XCTAssertEqual(transitReady.target, "192.0.2.99:443")
    }

    func testProxyAndOpenVPNPortOverridesPreserveSNI() throws {
        let link = try ProxyLink.parse("vless://11111111-1111-1111-1111-111111111111@vpn.example.com:8443?security=tls")
        let xray = try XrayConfig.make(link: link, server: "127.0.0.1", portOverride: 51234, socksPort: 20800)
        let outbounds = try XCTUnwrap(xray["outbounds"] as? [[String: Any]])
        let settings = try XCTUnwrap(outbounds[0]["settings"] as? [String: Any])
        let vnext = try XCTUnwrap(settings["vnext"] as? [[String: Any]])
        XCTAssertEqual(vnext[0]["address"] as? String, "127.0.0.1")
        XCTAssertEqual(vnext[0]["port"] as? Int, 51234)
        let stream = try XCTUnwrap(outbounds[0]["streamSettings"] as? [String: Any])
        let tls = try XCTUnwrap(stream["tlsSettings"] as? [String: Any])
        XCTAssertEqual(tls["serverName"] as? String, "vpn.example.com")

        let ovpn = "client\ndev tun\nremote vpn.example.com 1194 tcp\n"
        let pinned = try OpenVPNConfig.pinRemote(ovpn, ip: "127.0.0.1", portOverride: 55555)
        XCTAssertTrue(pinned.hasPrefix("remote 127.0.0.1 55555 tcp"))
        XCTAssertEqual(Endpoint.rewritePort("https://vpn.example.com/group", port: 51234), "https://vpn.example.com:51234/group")
    }

    func testArgonProfileDecodesAndroidFields() throws {
        let json = #"{"id":"\#(UUID().uuidString)","name":"Argon","proto":"argon4","server":"192.0.2.1","password":"secret","clientID":"client","clientSecret":"owner"}"#
        let profile = try JSONDecoder().decode(Profile.self, from: Data(json.utf8))
        XCTAssertEqual(profile.proto, .argon4)
        XCTAssertEqual(profile.clientID, "client")
        XCTAssertEqual(profile.clientSecret, "owner")
        XCTAssertFalse(profile.useArgon)
        XCTAssertNil(profile.argonProtocolVersion)
        XCTAssertNil(profile.argonPaddingProfile)
        XCTAssertNil(profile.argonFirstPacketProfile)
        XCTAssertNil(profile.argonPathMTU)
    }

    func testArgonV5PaddingAndVersionFieldsEncodeInConfiguration() throws {
        var profile = Profile(name: "Argon v5", proto: .argon4)
        profile.server = "vpn.example.com"
        profile.password = "master-secret"
        profile.argonProtocolVersion = 5
        profile.argonPaddingProfile = "wide"
        profile.argonFirstPacketProfile = "compact"
        profile.argonPathMTU = 1420
        profile.argonHandshakeMaxAttempts = 6
        profile.argonHandshakeInitialTimeoutMs = 900
        profile.argonHandshakeMaxTimeoutMs = 3600
        profile.argonKeepaliveMinIdleSec = 16
        profile.argonKeepaliveMaxIdleSec = 26
        let configuration = try Argon4Configuration(
            profile: profile,
            resolvedIP: "192.0.2.10",
            readyFile: "/tmp/ready.json",
            underlay: false
        )
        let data = try JSONEncoder().encode(configuration)
        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
        XCTAssertEqual(object["protocol_version"] as? Int, 5)
        XCTAssertEqual(object["padding_profile"] as? String, "wide")
        XCTAssertEqual(object["first_packet_profile"] as? String, "compact")
        XCTAssertEqual(object["path_mtu"] as? Int, 1420)
        XCTAssertEqual(object["handshake_max_attempts"] as? Int, 6)
        XCTAssertEqual(object["handshake_initial_timeout_ms"] as? Int, 900)
        XCTAssertEqual(object["handshake_max_timeout_ms"] as? Int, 3600)
        XCTAssertEqual(object["keepalive_min_idle_sec"] as? Int, 16)
        XCTAssertEqual(object["keepalive_max_idle_sec"] as? Int, 26)
    }

    func testBehaviorProfileDefaultsAndChainValidation() throws {
        var argon = Profile(name: "Argon", proto: .argon4)
        argon.server = "1.2.3.4:51820"
        argon.password = "secret"
        XCTAssertNil(argon.argonBehaviorProfile)
        XCTAssertFalse(argon.argonLowPowerMode)

        argon.argonBehaviorProfile = "video-call"
        argon.argonLowPowerMode = true
        argon.argonVideoCallMaxDurationSec = 1800

        let cfg = try Argon4Configuration(profile: argon, resolvedIP: "1.2.3.4", readyFile: "/tmp/ready.json", underlay: false)
        XCTAssertEqual(cfg.behaviorProfile, "video-call")
        XCTAssertEqual(cfg.lowPowerMode, true)
        XCTAssertEqual(cfg.videoCallMaxDurationSec, 1800)

        let jsonData = try JSONEncoder().encode(cfg)
        let jsonObj = try XCTUnwrap(JSONSerialization.jsonObject(with: jsonData) as? [String: Any])
        XCTAssertEqual(jsonObj["behavior_profile"] as? String, "video-call")
        XCTAssertEqual(jsonObj["low_power_mode"] as? Bool, true)
        XCTAssertEqual(jsonObj["video_call_max_duration_sec"] as? Int, 1800)

        // Проверка совместимости в ChainValidation
        var chain = Chain(name: "test")
        var badArgon = argon
        badArgon.argonProtocolVersion = 4
        chain.hops = [badArgon.id]
        XCTAssertFalse(ChainValidation.problems(Plan(chain: chain, profiles: [badArgon])).isEmpty)

        var transit = Profile(name: "Transit", proto: .transit)
        transit.server = "2.2.2.2:51820"
        transit.password = "secret"
        transit.argonBehaviorProfile = "video-call"

        var tcpNext = Profile(name: "VLESS", proto: .vless)
        tcpNext.uri = "vless://11111111-1111-1111-1111-111111111111@3.3.3.3:443#v"

        var udpNext = Profile(name: "HY2", proto: .hysteria2)
        udpNext.uri = "hysteria2://pass@3.3.3.3:443#h"

        var tcpChain = Chain(name: "tcp")
        tcpChain.hops = [transit.id, tcpNext.id]
        XCTAssertFalse(ChainValidation.problems(Plan(chain: tcpChain, profiles: [transit, tcpNext])).isEmpty)

        var udpChain = Chain(name: "udp")
        udpChain.hops = [transit.id, udpNext.id]
        XCTAssertTrue(ChainValidation.problems(Plan(chain: udpChain, profiles: [transit, udpNext])).isEmpty)
    }
}
