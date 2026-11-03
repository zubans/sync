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

    func testUnderlayUsesSeparateServerAndPassword() throws {
        var profile = Profile(name: "Wrapped", proto: .hysteria2)
        profile.uri = "hy2://pw@192.0.2.20:443"
        profile.useArgon = true
        profile.argonServer = "argon.example.com:4443"
        profile.argonPassword = "argon-secret"
        profile.clientID = "owner-1"
        let configuration = try Argon4Configuration(
            profile: profile, resolvedIP: "192.0.2.30", readyFile: "ready", underlay: true
        )
        XCTAssertEqual(configuration.server, "192.0.2.30:4443")
        XCTAssertEqual(configuration.password, "argon-secret")
        XCTAssertEqual(configuration.clientID, "owner-1")
    }

    func testChainValidationRequiresSecretsAndRejectsNestedArgon() {
        var chain = Chain(name: "test")
        var standalone = Profile(name: "Argon", proto: .argon4)
        standalone.server = "192.0.2.10"
        chain.hops = [standalone.id]
        XCTAssertTrue(ChainValidation.problems(Plan(chain: chain, profiles: [standalone])).contains {
            $0.contains("мастер-пароль")
        })

        standalone.password = "secret"
        standalone.useArgon = true
        standalone.argonServer = "192.0.2.20"
        standalone.argonPassword = "another-secret"
        XCTAssertTrue(ChainValidation.problems(Plan(chain: chain, profiles: [standalone])).contains {
            $0.contains("не нужна")
        })
    }

    func testReadyAllowsMissingOptionalRoutesAndDNS() throws {
        let json = #"{"interface":"utun9","address":"10.44.0.2","server_ip":"10.44.0.1","mtu":1380}"#
        let ready = try JSONDecoder().decode(Argon4Ready.self, from: Data(json.utf8))
        XCTAssertEqual(ready.interface, "utun9")
        XCTAssertEqual(ready.address, "10.44.0.2")
        XCTAssertTrue(ready.dns.isEmpty)
        XCTAssertTrue(ready.routes.isEmpty)
    }

    func testArgonProfileDecodesAndroidFields() throws {
        let json = #"{"id":"\#(UUID().uuidString)","name":"Argon","proto":"argon4","server":"192.0.2.1","password":"secret","clientID":"client","clientSecret":"owner"}"#
        let profile = try JSONDecoder().decode(Profile.self, from: Data(json.utf8))
        XCTAssertEqual(profile.proto, .argon4)
        XCTAssertEqual(profile.clientID, "client")
        XCTAssertEqual(profile.clientSecret, "owner")
        XCTAssertFalse(profile.useArgon)
    }
}
