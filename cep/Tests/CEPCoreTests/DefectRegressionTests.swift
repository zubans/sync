import Darwin
import Foundation
@testable import CEPCore
import XCTest

final class DefectRegressionTests: XCTestCase {
    private func bindLoopbackUDP() throws -> (fd: Int32, port: Int) {
        let fd = Darwin.socket(AF_INET, SOCK_DGRAM, IPPROTO_UDP)
        guard fd >= 0 else {
            throw NSError(domain: NSPOSIXErrorDomain, code: Int(errno))
        }
        var addr = sockaddr_in()
        addr.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        addr.sin_family = sa_family_t(AF_INET)
        addr.sin_port = 0
        addr.sin_addr = in_addr(s_addr: inet_addr("127.0.0.1"))

        let bindRes = withUnsafePointer(to: &addr) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                Darwin.bind(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size))
            }
        }
        guard bindRes == 0 else {
            let err = errno
            Darwin.close(fd)
            throw NSError(domain: NSPOSIXErrorDomain, code: Int(err))
        }

        var bound = sockaddr_in()
        var len = socklen_t(MemoryLayout<sockaddr_in>.size)
        let nameRes = withUnsafeMutablePointer(to: &bound) {
            $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                Darwin.getsockname(fd, $0, &len)
            }
        }
        guard nameRes == 0 else {
            let err = errno
            Darwin.close(fd)
            throw NSError(domain: NSPOSIXErrorDomain, code: Int(err))
        }
        return (fd, Int(UInt16(bigEndian: bound.sin_port)))
    }

    func testUDPProbeRejectsSilentBlackholeAndDetects1280Bottleneck() throws {
        // 1. Тихий UDP-сокет (blackhole) не должен возвращать ложный 1500 MTU.
        let silent = try bindLoopbackUDP()
        defer { Darwin.close(silent.fd) }

        let silentRes = MTUProbe.probeEndpointSync(
            host: "127.0.0.1",
            port: silent.port,
            proto: "amneziawg",
            transport: "udp",
            timeoutMs: 800
        )
        XCTAssertFalse(silentRes.ok, "Тихий UDP-сокет не должен считаться успешным измерением")
        XCTAssertEqual(silentRes.optimalPathMTU, 0)

        let silentTransit = MTUProbe.probeEndpointSync(
            host: "127.0.0.1",
            port: silent.port,
            proto: "transit",
            transport: "udp",
            timeoutMs: 800
        )
        XCTAssertFalse(silentTransit.ok)
        XCTAssertEqual(silentTransit.optimalPathMTU, 0)

        // 2. UDP echo-сервер с порогом payload 1252 Б (+28 Б IPv4/UDP = ровно 1280 Б Path MTU).
        let echo = try bindLoopbackUDP()
        var tv = timeval(tv_sec: 0, tv_usec: 100_000)
        Darwin.setsockopt(echo.fd, SOL_SOCKET, SO_RCVTIMEO, &tv, socklen_t(MemoryLayout<timeval>.size))

        let stopped = ManagedStopFlag()
        let echoGroup = DispatchGroup()
        echoGroup.enter()
        DispatchQueue.global(qos: .userInitiated).async {
            defer { echoGroup.leave() }
            var buf = [UInt8](repeating: 0, count: 65535)
            while !stopped.isStopped {
                var peer = sockaddr_storage()
                var peerLen = socklen_t(MemoryLayout<sockaddr_storage>.size)
                let n = withUnsafeMutablePointer(to: &peer) {
                    $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                        Darwin.recvfrom(echo.fd, &buf, buf.count, 0, $0, &peerLen)
                    }
                }
                if n <= 0 { continue }
                if n > 1252 { continue }
                _ = buf.withUnsafeBytes { raw in
                    withUnsafePointer(to: &peer) {
                        $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                            Darwin.sendto(echo.fd, raw.baseAddress, n, 0, $0, peerLen)
                        }
                    }
                }
            }
        }
        defer {
            stopped.stop()
            Darwin.close(echo.fd)
            echoGroup.wait()
        }

        let res = MTUProbe.probeEndpointSync(
            host: "127.0.0.1",
            port: echo.port,
            proto: "amneziawg",
            transport: "udp",
            timeoutMs: 2000
        )
        XCTAssertTrue(res.ok, res.error ?? res.message)
        XCTAssertEqual(res.optimalPathMTU, 1280)
        XCTAssertEqual(res.innerTunMTU, 1200)
    }

    func testNextTargetResolvesAuthenticatedTransitHop() {
        var t1 = Profile(name: "Transit 1", proto: .transit)
        t1.server = "198.51.100.1:51820"
        t1.password = "secret"

        var n2 = Profile(name: "Exit VLESS", proto: .vless)
        n2.uri = "vless://11111111-2222-3333-4444-555555555555@exit.example.net:8443?type=tcp#exit"

        var chain = Chain(name: "Transit Chain")
        chain.hops = [t1.id, n2.id]

        XCTAssertEqual(MTUProbe.nextTarget(for: t1.id, chain: chain, profiles: [t1, n2]), "exit.example.net:8443")

        n2.useArgon = true
        XCTAssertEqual(MTUProbe.nextTarget(for: t1.id, chain: chain, profiles: [t1, n2]), "exit.example.net:51820")
        XCTAssertNil(MTUProbe.nextTarget(for: n2.id, chain: chain, profiles: [t1, n2]))
    }

    func testHysteria2UserpassPreservesUserColonPassword() throws {
        let link = try ProxyLink.parse("hysteria2://alice:s3cr%40t@vpn.example.com:8443?sni=vpn.example.com#Hy2")
        XCTAssertEqual(link.userInfo, "alice:s3cr@t")
        let out = try SingBoxConfig.outbound(for: link, server: "203.0.113.5")
        XCTAssertEqual(out["type"] as? String, "hysteria2")
        XCTAssertEqual(out["password"] as? String, "alice:s3cr@t")
    }
}

private final class ManagedStopFlag: @unchecked Sendable {
    private let lock = NSLock()
    private var flag = false

    var isStopped: Bool {
        lock.lock()
        defer { lock.unlock() }
        return flag
    }

    func stop() {
        lock.lock()
        flag = true
        lock.unlock()
    }
}
