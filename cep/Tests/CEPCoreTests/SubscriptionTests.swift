@testable import CEPCore
import XCTest

final class SubscriptionTests: XCTestCase {
    private let links = """
    vless://11111111-2222-3333-4444-555555555555@a.example.com:443?type=xhttp&security=tls#%F0%9F%87%A9%F0%9F%87%AA%20Frankfurt%20
    vless://11111111-2222-3333-4444-555555555555@b.example.com:443?type=grpc&security=tls#USA
    trojan://secret@c.example.com:443#Trojan
    hy2://pw@d.example.com:8443
    vless://11111111-2222-3333-4444-555555555555@e.example.com:443?type=tcp#USA
    """

    func testParsesBase64WithAndWithoutPaddingAndPlainText() {
        let b64 = Data(links.utf8).base64EncodedString()
        let urlSafeNoPad = b64.replacingOccurrences(of: "+", with: "-").replacingOccurrences(of: "/", with: "_")
            .trimmingCharacters(in: CharacterSet(charactersIn: "="))
        for body in [b64, urlSafeNoPad, links] {
            let servers = SubscriptionParser.parse(Data(body.utf8))
            XCTAssertEqual(servers.map(\.name), ["🇩🇪 Frankfurt", "USA", "Trojan", "d.example.com:8443", "USA (2)"])
        }
    }

    func testProtocolsAndUnsupportedSchemes() {
        let servers = SubscriptionParser.parse(Data(links.utf8))
        XCTAssertEqual(servers.map(\.proto), [.vless, .vless, nil, .hysteria2, .vless])
        XCTAssertEqual(servers[1].address, "b.example.com:443")
    }

    func testMatchByNameThenByAddress() {
        let servers = SubscriptionParser.parse(Data(links.utf8))
        XCTAssertEqual(SubscriptionParser.match(servers, name: "USA", previousURI: "")?.address, "b.example.com:443")
        // провайдер переименовал сервер — находим по прежнему host:port
        let renamed = SubscriptionParser.match(servers, name: "Germany", previousURI: "vless://x@a.example.com:443?type=tcp#Germany")
        XCTAssertEqual(renamed?.name, "🇩🇪 Frankfurt")
        XCTAssertNil(SubscriptionParser.match(servers, name: "Gone", previousURI: "vless://x@z.example.com:1#Gone"))
    }

    func testHeaders() {
        XCTAssertEqual(SubscriptionParser.title("base64:" + Data("Моя подписка".utf8).base64EncodedString()), "Моя подписка")
        XCTAssertEqual(SubscriptionParser.title("Plain"), "Plain")
        let info = SubscriptionParser.userInfo("upload=0; download=1073741824; total=0; expire=0")
        XCTAssertEqual(info, "использовано 1.0 ГБ из ∞")
        XCTAssertNil(SubscriptionParser.userInfo(""))
    }

    func testHWIDIsStableAndHidesSeed() {
        let a = SubscriptionParser.hwid(seed: "ABC")
        XCTAssertEqual(a, SubscriptionParser.hwid(seed: "ABC"))
        XCTAssertNotEqual(a, SubscriptionParser.hwid(seed: "ABD"))
        XCTAssertEqual(a.count, 32)
        XCTAssertFalse(a.contains("ABC"))
    }

    func testProfileDecodesSubscriptionLinkAndOldFiles() throws {
        let id = UUID()
        let json = #"{"id":"\#(UUID().uuidString)","name":"x","proto":"vless","subscriptionID":"\#(id.uuidString)","subscriptionServer":"USA"}"#
        let p = try JSONDecoder().decode(Profile.self, from: Data(json.utf8))
        XCTAssertEqual(p.subscriptionID, id)
        XCTAssertEqual(p.subscriptionServer, "USA")
        let old = try JSONDecoder().decode(Profile.self, from: Data(#"{"id":"\#(UUID().uuidString)","name":"x","proto":"vless"}"#.utf8))
        XCTAssertNil(old.subscriptionID)
    }
}
