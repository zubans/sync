@testable import CEPCore
import XCTest

final class ProxyLinkTests: XCTestCase {
    func testVLESSRealityXHTTP() throws {
        let link = try ProxyLink.parse(
            "vless://11111111-2222-3333-4444-555555555555@nl.example.com:4430?type=xhttp&security=reality&sni=yandex.net&fp=chrome&pbk=PUBKEY&sid=ab12&path=%2Fvlx&mode=auto#NL%20xhttp"
        )
        XCTAssertEqual(link.host, "nl.example.com")
        XCTAssertEqual(link.port, 4430)
        XCTAssertEqual(link.label, "NL xhttp")

        let cfg = try XrayConfig.make(link: link, server: "94.103.9.172", socksPort: 20800)
        let out = try XCTUnwrap((cfg["outbounds"] as? [[String: Any]])?.first)
        let stream = try XCTUnwrap(out["streamSettings"] as? [String: Any])
        XCTAssertEqual(stream["network"] as? String, "xhttp")
        XCTAssertEqual((stream["xhttpSettings"] as? [String: Any])?["path"] as? String, "/vlx")
        let reality = try XCTUnwrap(stream["realitySettings"] as? [String: Any])
        XCTAssertEqual(reality["serverName"] as? String, "yandex.net")
        XCTAssertEqual(reality["publicKey"] as? String, "PUBKEY")
        let vnext = try XCTUnwrap(((out["settings"] as? [String: Any])?["vnext"] as? [[String: Any]])?.first)
        XCTAssertEqual(vnext["address"] as? String, "94.103.9.172")
        XCTAssertNoThrow(try JSON.data(cfg))
    }

    func testVLESSDefaultSNIIsHostname() throws {
        let link = try ProxyLink.parse("vless://uuid@vpn.example.com:443?security=tls&type=ws&path=/ws&host=cdn.example.com")
        let cfg = try XrayConfig.make(link: link, server: "1.2.3.4", socksPort: 1)
        let stream = try XCTUnwrap(((cfg["outbounds"] as? [[String: Any]])?.first)?["streamSettings"] as? [String: Any])
        XCTAssertEqual((stream["tlsSettings"] as? [String: Any])?["serverName"] as? String, "vpn.example.com")
        XCTAssertEqual((stream["wsSettings"] as? [String: Any])?["host"] as? String, "cdn.example.com")
    }

    func testHysteria2() throws {
        let link = try ProxyLink.parse("hy2://user:p%40ss@94.103.9.172:443/?sni=nl.example.com&obfs=salamander&obfs-password=secret&insecure=1#NL")
        XCTAssertEqual(link.scheme, "hysteria2")
        XCTAssertEqual(link.userInfo, "user:p@ss")
        let out = try SingBoxConfig.outbound(for: link, server: "94.103.9.172")
        XCTAssertEqual(out["type"] as? String, "hysteria2")
        XCTAssertEqual(out["password"] as? String, "user:p@ss")
        XCTAssertEqual((out["obfs"] as? [String: Any])?["type"] as? String, "salamander")
        let tls = try XCTUnwrap(out["tls"] as? [String: Any])
        XCTAssertEqual(tls["server_name"] as? String, "nl.example.com")
        XCTAssertEqual(tls["insecure"] as? Bool, true)

        let cfg = SingBoxConfig.make(tunAddress: "172.19.0.1/30", outbound: out)
        let tun = try XCTUnwrap((cfg["inbounds"] as? [[String: Any]])?.first)
        XCTAssertEqual(tun["auto_route"] as? Bool, false)
    }

    func testRejectsUnknownScheme() {
        XCTAssertThrowsError(try ProxyLink.parse("ss://abc@host:1"))
    }
}

final class TunnelConfigTests: XCTestCase {
    let ovpn = """
    client
    dev tun
    proto udp
    # remote commented.example.com 1
    remote vpn.example.com 1194 udp
    remote backup.example.com 443 tcp
    remote-random
    <ca>
    remote not-a-directive
    </ca>
    """

    func testOpenVPNRemote() throws {
        let r = try XCTUnwrap(OpenVPNConfig.firstRemote(ovpn))
        XCTAssertEqual(r.host, "vpn.example.com")
        XCTAssertEqual(r.port, 1194)

        let pinned = try OpenVPNConfig.pinRemote(ovpn, ip: "5.6.7.8")
        XCTAssertTrue(pinned.hasPrefix("remote 5.6.7.8 1194 udp\n"))
        XCTAssertFalse(pinned.contains("backup.example.com"))
        XCTAssertFalse(pinned.contains("remote-random"))
        XCTAssertTrue(pinned.contains("remote not-a-directive"), "inline-блоки не трогаем")
    }

    func testAWG() throws {
        let conf = """
        [Interface]
        PrivateKey = AAAA
        Address = 10.8.0.2/32, fd00::2/128
        DNS = 1.1.1.1
        MTU = 1280
        Jc = 4
        H1 = 123

        [Peer]
        PublicKey = BBBB
        AllowedIPs = 0.0.0.0/0
        Endpoint = awg.example.com:51820
        """
        let c = try AWGConfig.parse(conf)
        XCTAssertEqual(c.endpoint, Endpoint(host: "awg.example.com", port: 51820))
        XCTAssertEqual(c.ipv4Address, "10.8.0.2")
        XCTAssertEqual(c.mtu, 1280)
        let text = c.setconfText(endpointIP: "9.9.9.9")
        XCTAssertTrue(text.contains("Endpoint = 9.9.9.9:51820"))
        XCTAssertTrue(text.contains("Jc = 4"))
        XCTAssertFalse(text.contains("Address"))
        XCTAssertFalse(text.contains("DNS"))
    }

    func testHostPort() throws {
        XCTAssertEqual(try Endpoint.parseHostPort("https://vpn.example.com:4443/group"), Endpoint(host: "vpn.example.com", port: 4443))
        XCTAssertEqual(try Endpoint.parseHostPort("vpn.example.com"), Endpoint(host: "vpn.example.com", port: nil))
        XCTAssertEqual(try Endpoint.parseHostPort("[2001:db8::1]:443"), Endpoint(host: "2001:db8::1", port: 443))
    }
}

final class ChainTests: XCTestCase {
    func testUnknownHopIDsAreDropped() {
        var proxy = Profile(name: "hy", proto: .hysteria2)
        proxy.uri = "hy2://pw@1.2.3.4:443"
        var chain = Chain(name: "c")
        chain.hops = [UUID(), proxy.id]
        let plan = Plan(chain: chain, profiles: [proxy])
        XCTAssertEqual(plan.hops.map(\.id), [proxy.id])
        XCTAssertTrue(ChainValidation.problems(plan).isEmpty)
    }

    func testProfileDecodesWithMissingFields() throws {
        let json = #"{"id":"\#(UUID().uuidString)","name":"x","proto":"sstp"}"#
        let p = try JSONDecoder().decode(Profile.self, from: Data(json.utf8))
        XCTAssertEqual(p.proto, .sstp)
        XCTAssertFalse(p.ignoreCertErrors)
    }

    func testScutilParse() {
        let out = """
        <dictionary> {
          DomainName : lan
          ServerAddresses : <array> {
            0 : 192.168.1.1
            1 : 8.8.8.8
          }
          Nested : <dictionary> {
            Inner : <array> {
              0 : x
            }
            Key : y
          }
        }
        """
        let d = ScutilDict.parse(out)
        XCTAssertEqual(d.scalars, ["DomainName": "lan"])
        XCTAssertEqual(d.arrays, ["ServerAddresses": ["192.168.1.1", "8.8.8.8"]])
    }
}

/// Сгенерированные конфиги проверяются настоящими sing-box и xray, если они установлены.
final class RealToolConfigTests: XCTestCase {
    private func check(_ tool: Tool, _ config: [String: Any], args: (String) -> [String]) throws {
        guard let path = tool.locate() else { throw XCTSkip("\(tool.rawValue) не установлен") }
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("cep-\(UUID().uuidString).json")
        try JSON.data(config).write(to: url)
        defer { try? FileManager.default.removeItem(at: url) }
        let r = Shell.run(path, args(url.path))
        XCTAssertEqual(r.status, 0, r.output)
    }

    func testSingBoxAcceptsHysteria2() throws {
        let link = try ProxyLink.parse("hy2://pw@1.2.3.4:443/?sni=example.com&obfs=salamander&obfs-password=x")
        let cfg = SingBoxConfig.make(tunAddress: "172.19.0.1/30", outbound: try SingBoxConfig.outbound(for: link, server: "1.2.3.4"))
        try check(.singBox, cfg) { ["check", "-c", $0] }
    }

    func testSingBoxAcceptsHysteriaV1() throws {
        let link = try ProxyLink.parse("hysteria://1.2.3.4:443?auth=x&peer=example.com&upmbps=10&downmbps=50")
        let cfg = SingBoxConfig.make(tunAddress: "172.19.0.1/30", outbound: try SingBoxConfig.outbound(for: link, server: "1.2.3.4"))
        try check(.singBox, cfg) { ["check", "-c", $0] }
    }

    func testSingBoxAcceptsSocks() throws {
        try check(.singBox, SingBoxConfig.make(tunAddress: "172.19.1.1/30", outbound: SingBoxConfig.socksOutbound(port: 20801))) { ["check", "-c", $0] }
    }

    func testXrayAcceptsVLESS() throws {
        for uri in [
            "vless://11111111-2222-3333-4444-555555555555@h.example.com:4430?type=xhttp&security=reality&sni=yandex.net&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=ab12&path=%2Fvlx",
            "vless://11111111-2222-3333-4444-555555555555@h.example.com:443?type=tcp&security=reality&flow=xtls-rprx-vision&sni=a.com&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc",
            "vless://11111111-2222-3333-4444-555555555555@h.example.com:443?type=ws&security=tls&path=/ws&host=cdn.example.com",
            "vless://11111111-2222-3333-4444-555555555555@h.example.com:443?type=grpc&security=tls&serviceName=g",
        ] {
            let cfg = try XrayConfig.make(link: try ProxyLink.parse(uri), server: "1.2.3.4", socksPort: 20800)
            try check(.xray, cfg) { ["run", "-test", "-c", $0] }
        }
    }
}

final class InnerNetworkTests: XCTestCase {
    func testProfileRoutes() throws {
        XCTAssertEqual(try RouteList.parse("192.168.1.119, 10.0.0.0/8\n172.16.0.0/12"),
                       ["192.168.1.119/32", "10.0.0.0/8", "172.16.0.0/12"])
        XCTAssertEqual(try RouteList.parse(""), [])
        XCTAssertThrowsError(try RouteList.parse("192.168.1.0/33"))
        XCTAssertThrowsError(try RouteList.parse("kb.local"))
    }

    func testOpenConnectFile() {
        let net = RouteList.fromOpenConnect("INC=192.168.100.0/24\nINC=0.0.0.0/0\nDNS=192.168.100.1 8.8.8.8\nDOMAIN=home.lan \n")
        XCTAssertEqual(net, InnerNetwork(routes: ["192.168.100.0/24"], dns: ["192.168.100.1", "8.8.8.8"], domains: ["home.lan"]))
    }

    func testOpenConnectServerAddressIsNotDomain() {
        let net = RouteList.fromOpenConnect("DNS=8.8.8.8 8.8.4.4\nDOMAIN=94.103.9.172 \n")
        XCTAssertEqual(net.domains, [])
    }

    func testOpenVPNPushReply() {
        let log = "2026-10-26 PUSH: Received control message: 'PUSH_REPLY,route 10.10.0.0 255.255.0.0,route-gateway 10.8.0.1,redirect-gateway def1,dhcp-option DNS 10.10.0.53,dhcp-option DOMAIN corp,ifconfig 10.8.0.2 255.255.255.0'"
        let net = RouteList.fromOpenVPN(log: log, config: "client\nroute 172.20.0.0 255.255.255.0\n")
        XCTAssertEqual(net.routes, ["172.20.0.0/24", "10.10.0.0/16"])
        XCTAssertEqual(net.dns, ["10.10.0.53"])
        XCTAssertEqual(net.domains, ["corp"])
    }

    func testArgon4DefaultRouteIsManagedByEngine() {
        let net = RouteList.fromArgon4(routes: ["0.0.0.0/0", "10.20.0.0/16", "10.20.0.0/16"])
        XCTAssertEqual(net.routes, ["10.20.0.0/16"])
    }

    func testMaskLength() {
        XCTAssertEqual(RouteList.maskLength("255.255.255.0"), 24)
        XCTAssertEqual(RouteList.maskLength("255.255.255.255"), 32)
        XCTAssertNil(RouteList.maskLength("255.0.255.0"))
    }
}
