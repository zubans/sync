import Darwin
import Foundation

/// Результат подбора оптимального внешнего MTU для узла цепочки.
public struct MTUProbeResult: Codable, Sendable, Equatable {
    public var ok: Bool
    public var proto: String
    public var server: String
    public var optimalPathMTU: Int
    public var innerTunMTU: Int
    public var effectiveOuter: Int?
    public var interfaceMTU: Int?
    public var tcpMSS: Int?
    public var rttMs: Int64
    public var method: String
    public var message: String
    public var precedingBudget: Int?
    public var supportsInBand: Bool?
    public var error: String?

    enum CodingKeys: String, CodingKey {
        case ok, proto, server, method, message, error
        case optimalPathMTU = "optimal_path_mtu"
        case innerTunMTU = "inner_tun_mtu"
        case effectiveOuter = "effective_outer_udp"
        case interfaceMTU = "interface_mtu"
        case tcpMSS = "tcp_mss"
        case rttMs = "rtt_ms"
        case precedingBudget = "preceding_budget"
        case supportsInBand = "supports_in_band"
    }

    public init(
        ok: Bool = false,
        proto: String = "",
        server: String = "",
        optimalPathMTU: Int = 0,
        innerTunMTU: Int = 0,
        effectiveOuter: Int? = nil,
        interfaceMTU: Int? = nil,
        tcpMSS: Int? = nil,
        rttMs: Int64 = 0,
        method: String = "",
        message: String = "",
        precedingBudget: Int? = nil,
        supportsInBand: Bool? = nil,
        error: String? = nil
    ) {
        self.ok = ok
        self.proto = proto
        self.server = server
        self.optimalPathMTU = optimalPathMTU
        self.innerTunMTU = innerTunMTU
        self.effectiveOuter = effectiveOuter
        self.interfaceMTU = interfaceMTU
        self.tcpMSS = tcpMSS
        self.rttMs = rttMs
        self.method = method
        self.message = message
        self.precedingBudget = precedingBudget
        self.supportsInBand = supportsInBand
        self.error = error
    }
}

/// Подбор оптимального внешнего MTU для узлов всех поддерживаемых протоколов (Darwin POSIX + опционально cep-cli для Argon4 PLPMTUD).
public enum MTUProbe {
    private static let darwinIPDontFrag: Int32 = 28
    private static let darwinIPv6DontFrag: Int32 = 62
    private static let darwinTCPMaxSeg: Int32 = 0x02
    private static let minPathMTU = 576
    private static let defaultPathMTU = 1500
    private static let maxPathMTU = 9000

    public static func precedingBudget(for profileID: UUID, chain: Chain?, profiles: [Profile]) -> Int {
        guard let chain else { return 0 }
        let byID = Dictionary(uniqueKeysWithValues: profiles.map { ($0.id, $0) })
        var prev = 0
        for (idx, hopID) in chain.hops.enumerated() {
            if hopID == profileID {
                return prev
            }
            guard let hop = byID[hopID] else { continue }
            if hop.proto == .transit || idx < chain.hops.count - 1 {
                let outer = hop.argonPathMTU ?? defaultPathMTU
                if prev > 0 {
                    prev = max(minPathMTU, min(outer, prev) - 64)
                } else {
                    prev = outer
                }
            } else {
                prev = 0
            }
        }
        return 0
    }

    public static func nextTarget(for profileID: UUID, chain: Chain?, profiles: [Profile]) -> String? {
        guard let chain else { return nil }
        guard let idx = chain.hops.firstIndex(of: profileID), idx + 1 < chain.hops.count else { return nil }
        let byID = Dictionary(uniqueKeysWithValues: profiles.map { ($0.id, $0) })
        guard let nextProfile = byID[chain.hops[idx + 1]],
              let ep = try? Endpoint.of(nextProfile)
        else { return nil }
        let port = nextProfile.useArgon && nextProfile.proto != .argon4 && nextProfile.proto != .transit
            ? 51820
            : (ep.port ?? defaultPort(for: nextProfile.proto))
        if ep.host.contains(":") && !ep.host.hasPrefix("[") {
            return "[\(ep.host)]:\(port)"
        }
        return "\(ep.host):\(port)"
    }

    public static func probe(profile: Profile, chain: Chain? = nil, profiles: [Profile] = []) async -> MTUProbeResult {
        await Task.detached(priority: .userInitiated) {
            probeSync(profile: profile, chain: chain, profiles: profiles)
        }.value
    }

    public static func probeSync(profile: Profile, chain: Chain? = nil, profiles: [Profile] = []) -> MTUProbeResult {
        let prevOuter = precedingBudget(for: profile.id, chain: chain, profiles: profiles)
        let budget = prevOuter > 0 ? max(minPathMTU, prevOuter - 64) : 0
        let targetAddr = nextTarget(for: profile.id, chain: chain, profiles: profiles)

        // Если установлен/вложен cep-cli и узел использует Argon4-хендшейк, получаем точный in-band PLPMTUD.
        if (profile.proto == .argon4 || profile.proto == .transit || profile.useArgon),
           let cliPath = Tool.cepCli.locate(),
           let cliResult = runCepCliProbe(cliPath: cliPath, profile: profile, precedingMTU: prevOuter, target: targetAddr)
        {
            return cliResult
        }

        do {
            let ep = try Endpoint.of(profile)
            let isArgonTarget = profile.useArgon && profile.proto != .argon4 && profile.proto != .transit
            let port = isArgonTarget ? 51820 : (ep.port ?? defaultPort(for: profile.proto))
            let transport: String
            if isArgonTarget {
                transport = "udp"
            } else if profile.proto == .transit {
                transport = (!profile.password.isEmpty || ArgonTransportProto.of(profile) != "tcp") ? "udp" : "tcp"
            } else {
                switch ArgonTransportProto.of(profile) {
                case "tcp": transport = "tcp"
                case "udp": transport = "udp"
                default: transport = "tcp"
                }
            }
            return probeEndpointSync(
                host: ep.host,
                port: port,
                proto: profile.proto.rawValue,
                transport: transport,
                precedingBudget: budget,
                timeoutMs: 3000
            )
        } catch {
            return MTUProbeResult(ok: false, proto: profile.proto.rawValue, error: error.localizedDescription)
        }
    }

    public static func probeEndpointSync(
        host: String,
        port: Int,
        proto: String,
        transport: String,
        precedingBudget: Int = 0,
        timeoutMs: Int = 2500
    ) -> MTUProbeResult {
        let serverStr = "\(host):\(port)"
        if transport == "udp" {
            return probeUDP(host: host, port: port, serverStr: serverStr, proto: proto, precedingBudget: precedingBudget)
        }
        return probeTCP(host: host, port: port, serverStr: serverStr, proto: proto, precedingBudget: precedingBudget, timeoutMs: timeoutMs)
    }

    private static func defaultPort(for proto: VPNProtocol) -> Int {
        switch proto {
        case .argon4, .transit, .amneziawg: return 51820
        case .openvpn: return 1194
        default: return 443
        }
    }

    private static func clampPathMTU(_ v: Int) -> Int {
        min(max(v, minPathMTU), maxPathMTU)
    }

    private static func probeTCP(host: String, port: Int, serverStr: String, proto: String, precedingBudget: Int, timeoutMs: Int) -> MTUProbeResult {
        guard let (fd, isIPv6, localIP, rttMs, errMsg) = connectSocket(host: host, port: port, sockType: SOCK_STREAM, timeoutMs: timeoutMs) else {
            return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: "Ошибка DNS/сокета \(serverStr)")
        }
        if fd < 0 {
            return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: errMsg ?? "Ошибка подключения к \(serverStr)")
        }
        defer { Darwin.close(fd) }

        let ifaceMTU = localIP.flatMap { interfaceMTU(forIP: $0) } ?? 0
        var mss: socklen_t = 0
        var optLen = socklen_t(MemoryLayout<socklen_t>.size)
        let gotMSS = Darwin.getsockopt(fd, IPPROTO_TCP, darwinTCPMaxSeg, &mss, &optLen) == 0 && mss >= 400 && mss <= socklen_t(maxPathMTU)
        let hdr = isIPv6 ? 60 : 40

        var optimal = defaultPathMTU
        var method = "tcp_interface_mtu"
        if gotMSS {
            let mssPath = clampPathMTU(Int(mss) + hdr)
            if mssPath < optimal { optimal = mssPath }
            method = "tcp_mss"
        }
        if ifaceMTU >= minPathMTU, ifaceMTU < optimal {
            optimal = ifaceMTU
        }
        if precedingBudget > 0, precedingBudget < optimal {
            optimal = precedingBudget
        }
        optimal = clampPathMTU(optimal)
        let inner = clampPathMTU(optimal - 64)
        let tcpMSSVal = gotMSS ? Int(mss) : nil
        return MTUProbeResult(
            ok: true,
            proto: proto,
            server: serverStr,
            optimalPathMTU: optimal,
            innerTunMTU: inner,
            effectiveOuter: optimal - hdr,
            interfaceMTU: ifaceMTU > 0 ? ifaceMTU : nil,
            tcpMSS: tcpMSSVal,
            rttMs: max(1, rttMs),
            method: method,
            message: "TCP MSS=\(tcpMSSVal ?? 0) → оптимальный внешний MTU=\(optimal) (внутр. \(inner), интерфейс \(ifaceMTU), RTT=\(max(1, rttMs)) мс)",
            precedingBudget: precedingBudget > 0 ? precedingBudget : nil
        )
    }

    private static func probeUDP(host: String, port: Int, serverStr: String, proto: String, precedingBudget: Int) -> MTUProbeResult {
        guard let (fd, isIPv6, localIP, _, errMsg) = connectSocket(host: host, port: port, sockType: SOCK_DGRAM, timeoutMs: 2000) else {
            return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: "Ошибка DNS/сокета \(serverStr)")
        }
        if fd < 0 {
            return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: errMsg ?? "Ошибка UDP сокета \(serverStr)")
        }
        defer { Darwin.close(fd) }

        let ifaceMTU = localIP.flatMap { interfaceMTU(forIP: $0) } ?? 0
        var on: Int32 = 1
        let dfApplied: Bool
        if isIPv6 {
            dfApplied = Darwin.setsockopt(fd, IPPROTO_IPV6, darwinIPv6DontFrag, &on, socklen_t(MemoryLayout<Int32>.size)) == 0
        } else {
            dfApplied = Darwin.setsockopt(fd, IPPROTO_IP, darwinIPDontFrag, &on, socklen_t(MemoryLayout<Int32>.size)) == 0
        }
        let hdr = isIPv6 ? 48 : 28

        var upperPath = defaultPathMTU
        if ifaceMTU >= minPathMTU, ifaceMTU < upperPath { upperPath = ifaceMTU }
        if precedingBudget > 0, precedingBudget < upperPath { upperPath = precedingBudget }
        upperPath = max(minPathMTU, upperPath)

        let isQuic = proto == "hysteria2" || proto == "hysteria"
        var seq: UInt16 = 0
        var staleBuf = [UInt8](repeating: 0, count: 16384)
        var recvBuf = [UInt8](repeating: 0, count: 16384)

        enum StepOutcome {
            case ok(rttMs: Int64)
            case tooBig
            case timeout
            case refused(String)
        }

        func drainSocket() {
            for _ in 0..<32 {
                let got = Darwin.recv(fd, &staleBuf, staleBuf.count, MSG_DONTWAIT)
                if got <= 0 { break }
            }
        }

        func probePath(_ pathMTU: Int, waitMs: Int) -> StepOutcome {
            drainSocket()
            seq &+= 1
            let curSeq = seq
            let outerLen = max(8, pathMTU - hdr)
            let pkt = buildUDPProbePacket(proto: proto, outerLen: outerLen, pathMTU: pathMTU, seq: curSeq)

            let startNs = Darwin.clock_gettime_nsec_np(Darwin.CLOCK_UPTIME_RAW)
            let sent = pkt.withUnsafeBytes { ptr in
                Darwin.send(fd, ptr.baseAddress, pkt.count, 0)
            }
            if sent < 0 {
                if errno == EMSGSIZE { return .tooBig }
                if errno == ECONNREFUSED { return .refused("UDP-порт \(serverStr) отклонил пакет (connection refused)") }
                return .timeout
            }
            if sent < pkt.count { return .tooBig }

            let deadlineNs = startNs &+ UInt64(max(20, waitMs)) &* 1_000_000
            while true {
                let nowNs = Darwin.clock_gettime_nsec_np(Darwin.CLOCK_UPTIME_RAW)
                if nowNs >= deadlineNs { return .timeout }
                let remMs = Int32(max(1, (deadlineNs &- nowNs) / 1_000_000))
                var pfd = pollfd(fd: fd, events: Int16(POLLIN), revents: 0)
                let ready = Darwin.poll(&pfd, 1, remMs)
                if ready < 0 {
                    if errno == EINTR { continue }
                    return .timeout
                }
                if ready == 0 { return .timeout }

                let rn = Darwin.recv(fd, &recvBuf, recvBuf.count, MSG_DONTWAIT)
                if rn < 0 {
                    if errno == EAGAIN || errno == EWOULDBLOCK || errno == EINTR { continue }
                    if errno == EMSGSIZE { return .tooBig }
                    if errno == ECONNREFUSED { return .refused("UDP-порт \(serverStr) недоступен (ICMP Port Unreachable)") }
                    return .timeout
                }
                if rn == 0 { continue }
                if !matchesUDPProbeReply(Array(recvBuf[0..<rn]), pathMTU: pathMTU, seq: curSeq, proto: proto) {
                    continue
                }
                let elapsedNs = Darwin.clock_gettime_nsec_np(Darwin.CLOCK_UPTIME_RAW) &- startNs
                let rttMs = max(1, Int64(elapsedNs / 1_000_000))
                return .ok(rttMs: rttMs)
            }
        }

        var bestPath = 0
        var measuredRTT: Int64 = 1

        switch probePath(upperPath, waitMs: 220) {
        case .refused(let msg):
            return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: msg)
        case .ok(let rtt):
            bestPath = upperPath
            measuredRTT = rtt
        case .tooBig, .timeout:
            var floors = [minPathMTU]
            if isQuic {
                let quicFloor = min(upperPath, max(minPathMTU, 1200 + hdr))
                if quicFloor > minPathMTU {
                    floors = [quicFloor, minPathMTU]
                }
            }
            var confirmedFloor: Int?
            for candidate in floors {
                var got = false
                for _ in 0..<2 {
                    switch probePath(candidate, waitMs: 250) {
                    case .refused(let msg):
                        return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: msg)
                    case .ok(let rtt):
                        confirmedFloor = candidate
                        measuredRTT = rtt
                        got = true
                    case .tooBig:
                        got = true
                    case .timeout:
                        break
                    }
                    if got { break }
                }
                if confirmedFloor != nil { break }
            }
            guard let floor = confirmedFloor else {
                return MTUProbeResult(
                    ok: false,
                    proto: proto,
                    server: serverStr,
                    error: "UDP-узел \(serverStr) не подтвердил доставку ни при \(upperPath) Б, ни при минимальном MTU \(minPathMTU) Б (нет ответа)"
                )
            }

            bestPath = floor
            var lo = floor
            var hi = upperPath - 1
            let stepWait = max(140, min(350, Int(measuredRTT) * 4 + 120))

            while lo < hi {
                let mid: Int
                if hi - lo > 16 {
                    let aligned = (((lo + hi + 1) / 2) / 4) * 4
                    mid = aligned > lo ? aligned : (lo + 1)
                } else {
                    mid = (lo + hi + 1) / 2
                }
                if mid <= lo || mid > hi { break }

                var stepRes = probePath(mid, waitMs: stepWait)
                if case .timeout = stepRes, hi - lo <= 32 {
                    stepRes = probePath(mid, waitMs: stepWait)
                }
                switch stepRes {
                case .refused(let msg):
                    return MTUProbeResult(ok: false, proto: proto, server: serverStr, error: msg)
                case .ok(let rtt):
                    bestPath = mid
                    lo = mid
                    measuredRTT = rtt
                case .tooBig, .timeout:
                    hi = mid - 1
                }
            }
        }

        var optimal = clampPathMTU(bestPath)
        let method = dfApplied ? "udp_df_probe" : "udp_probe"

        if ifaceMTU >= minPathMTU, ifaceMTU < optimal { optimal = ifaceMTU }
        if precedingBudget > 0, precedingBudget < optimal { optimal = precedingBudget }
        optimal = clampPathMTU(optimal)
        let inner = clampPathMTU(optimal - (proto == "amneziawg" ? 80 : 64))

        return MTUProbeResult(
            ok: true,
            proto: proto,
            server: serverStr,
            optimalPathMTU: optimal,
            innerTunMTU: inner,
            effectiveOuter: optimal - hdr,
            interfaceMTU: ifaceMTU > 0 ? ifaceMTU : nil,
            tcpMSS: nil,
            rttMs: measuredRTT,
            method: method,
            message: "Подобран внешний MTU=\(optimal) (внутр. \(inner), интерфейс \(ifaceMTU), метод \(method), RTT=\(measuredRTT) мс)",
            precedingBudget: precedingBudget > 0 ? precedingBudget : nil
        )
    }

    private static func buildUDPProbePacket(proto: String, outerLen: Int, pathMTU: Int, seq: UInt16) -> [UInt8] {
        var pkt = [UInt8](repeating: 0x50, count: outerLen)
        let mHi = UInt8((pathMTU >> 8) & 0xFF)
        let mLo = UInt8(pathMTU & 0xFF)
        let sHi = UInt8((seq >> 8) & 0xFF)
        let sLo = UInt8(seq & 0xFF)
        if (proto == "hysteria2" || proto == "hysteria") && outerLen >= 1200 {
            pkt[0] = 0xC0
            pkt[1] = 0x0A; pkt[2] = 0x1A; pkt[3] = 0x2A; pkt[4] = 0x3A
            pkt[5] = 8
            pkt[6] = 0x50; pkt[7] = 0x4D; pkt[8] = 0x54; pkt[9] = 0x55
            pkt[10] = mHi; pkt[11] = mLo; pkt[12] = sHi; pkt[13] = sLo
            pkt[14] = 8
            pkt[15] = 0x43; pkt[16] = 0x45; pkt[17] = 0x50; pkt[18] = 0x4D
            pkt[19] = mHi; pkt[20] = mLo; pkt[21] = sHi; pkt[22] = sLo
            return pkt
        }
        if outerLen >= 8 {
            pkt[0] = 0x50; pkt[1] = 0x4D; pkt[2] = 0x54; pkt[3] = 0x55
            pkt[4] = mHi; pkt[5] = mLo; pkt[6] = sHi; pkt[7] = sLo
        }
        return pkt
    }

    private static func matchesUDPProbeReply(_ reply: [UInt8], pathMTU: Int, seq: UInt16, proto: String) -> Bool {
        if reply.count >= 8, reply[0] == 0x50, reply[1] == 0x4D, reply[2] == 0x54, reply[3] == 0x55 {
            let rSeq = (UInt16(reply[6]) << 8) | UInt16(reply[7])
            return rSeq == seq
        }
        if (proto == "hysteria2" || proto == "hysteria") && reply.count >= 7 {
            let markers: [[UInt8]] = [[0x50, 0x4D, 0x54, 0x55], [0x43, 0x45, 0x50, 0x4D]]
            for marker in markers {
                if reply.count >= 8 {
                    for idx in 0...(reply.count - 8) {
                        if reply[idx] == marker[0], reply[idx + 1] == marker[1], reply[idx + 2] == marker[2], reply[idx + 3] == marker[3] {
                            let rSeq = (UInt16(reply[idx + 6]) << 8) | UInt16(reply[idx + 7])
                            return rSeq == seq
                        }
                    }
                }
            }
        }
        return true
    }

    private static func connectSocket(host: String, port: Int, sockType: Int32, timeoutMs: Int) -> (Int32, Bool, String?, Int64, String?)? {
        var hints = addrinfo()
        hints.ai_family = AF_UNSPEC
        hints.ai_socktype = sockType
        var res: UnsafeMutablePointer<addrinfo>?
        guard getaddrinfo(host, String(port), &hints, &res) == 0, let head = res else { return nil }
        defer { freeaddrinfo(head) }

        var ptr: UnsafeMutablePointer<addrinfo>? = head
        var lastErr = "Ошибка соединения"
        while let ai = ptr {
            let start = ContinuousClock.now
            let fd = Darwin.socket(ai.pointee.ai_family, ai.pointee.ai_socktype, ai.pointee.ai_protocol)
            if fd >= 0 {
                let flags = fcntl(fd, F_GETFL, 0)
                _ = fcntl(fd, F_SETFL, flags | O_NONBLOCK)
                let rc = Darwin.connect(fd, ai.pointee.ai_addr, ai.pointee.ai_addrlen)
                var ok = (rc == 0)
                if !ok && errno == EINPROGRESS {
                    var pfd = pollfd(fd: fd, events: Int16(POLLOUT), revents: 0)
                    let prc = Darwin.poll(&pfd, 1, Int32(timeoutMs))
                    if prc > 0 {
                        var soErr: Int32 = 0
                        var len = socklen_t(MemoryLayout<Int32>.size)
                        if getsockopt(fd, SOL_SOCKET, SO_ERROR, &soErr, &len) == 0, soErr == 0 {
                            ok = true
                        } else {
                            lastErr = String(cString: strerror(soErr))
                        }
                    } else if prc == 0 {
                        lastErr = "Таймаут подключения"
                    } else {
                        lastErr = String(cString: strerror(errno))
                    }
                } else if !ok {
                    lastErr = String(cString: strerror(errno))
                }
                _ = fcntl(fd, F_SETFL, flags)
                if ok {
                    let dur = ContinuousClock.now - start
                    let ms = Int64(dur.components.seconds * 1000) + Int64(dur.components.attoseconds / 1_000_000_000_000_000)
                    let localIP = socketLocalIP(fd: fd)
                    return (fd, ai.pointee.ai_family == AF_INET6, localIP, max(1, ms), nil)
                }
                Darwin.close(fd)
            }
            ptr = ai.pointee.ai_next
        }
        return (-1, false, nil, 0, lastErr)
    }

    private static func socketLocalIP(fd: Int32) -> String? {
        var storage = sockaddr_storage()
        var len = socklen_t(MemoryLayout<sockaddr_storage>.size)
        let rc = withUnsafeMutablePointer(to: &storage) { ptr in
            ptr.withMemoryRebound(to: sockaddr.self, capacity: 1) { sa in
                getsockname(fd, sa, &len)
            }
        }
        guard rc == 0 else { return nil }
        var hostBuf = [CChar](repeating: 0, count: Int(NI_MAXHOST))
        let nameRC = withUnsafePointer(to: &storage) { ptr in
            ptr.withMemoryRebound(to: sockaddr.self, capacity: 1) { sa in
                getnameinfo(sa, len, &hostBuf, socklen_t(hostBuf.count), nil, 0, NI_NUMERICHOST)
            }
        }
        guard nameRC == 0 else { return nil }
        return String(cString: hostBuf)
    }

    private static func interfaceMTU(forIP ip: String) -> Int {
        var ifap: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&ifap) == 0, let first = ifap else { return 0 }
        defer { freeifaddrs(first) }
        var cur: UnsafeMutablePointer<ifaddrs>? = first
        while let ifa = cur {
            if let sa = ifa.pointee.ifa_addr {
                var hostBuf = [CChar](repeating: 0, count: Int(NI_MAXHOST))
                let family = sa.pointee.sa_family
                if family == sa_family_t(AF_INET) || family == sa_family_t(AF_INET6) {
                    let slen = socklen_t(sa.pointee.sa_len)
                    if getnameinfo(sa, slen, &hostBuf, socklen_t(hostBuf.count), nil, 0, NI_NUMERICHOST) == 0,
                       String(cString: hostBuf) == ip
                    {
                        var ifr = ifreq()
                        let name = String(cString: ifa.pointee.ifa_name)
                        withUnsafeMutableBytes(of: &ifr.ifr_name) { dst in
                            let bytes = Array(name.utf8.prefix(dst.count - 1))
                            dst.copyBytes(from: bytes)
                        }
                        let s = Darwin.socket(AF_INET, SOCK_DGRAM, 0)
                        if s >= 0 {
                            defer { Darwin.close(s) }
                            // SIOCGIFMTU = 0xC0206933 на macOS
                            if ioctl(s, 0xC0206933, &ifr) == 0 {
                                let mtu = Int(ifr.ifr_ifru.ifru_mtu)
                                if mtu >= minPathMTU && mtu <= maxPathMTU {
                                    return mtu
                                }
                            }
                        }
                    }
                }
            }
            cur = ifa.pointee.ifa_next
        }
        return 0
    }

    private static func runCepCliProbe(cliPath: String, profile: Profile, precedingMTU: Int, target: String? = nil) -> MTUProbeResult? {
        var req: [String: Any] = [
            "proto": profile.proto.rawValue,
            "server": profile.server,
            "password": profile.password,
            "client_id": profile.clientID.isEmpty ? profile.id.uuidString : profile.clientID,
            "client_secret": profile.clientSecret,
            "link": profile.uri,
            "use_argon": profile.useArgon,
            "argon_password": profile.argonPassword,
        ]
        if profile.proto == .amneziawg && !profile.config.isEmpty { req["wireguard_config"] = profile.config }
        if profile.proto == .openvpn && !profile.config.isEmpty { req["openvpn_config"] = profile.config }
        if let v = profile.argonProtocolVersion { req["protocol_version"] = v; req["argon_protocol_version"] = v }
        if let p = profile.argonPaddingProfile { req["padding_profile"] = p; req["argon_padding_profile"] = p }
        if let f = profile.argonFirstPacketProfile { req["first_packet_profile"] = f; req["argon_first_packet_profile"] = f }
        if profile.proto == .transit {
            req["transit_proto"] = "udp"
            if !profile.password.isEmpty { req["mode"] = "transit" }
            if let target, !target.isEmpty { req["target"] = target }
        }
        if precedingMTU > 0 { req["preceding_mtu"] = precedingMTU }
        guard let reqData = try? JSONSerialization.data(withJSONObject: req),
              let reqStr = String(data: reqData, encoding: .utf8)
        else { return nil }

        let p = Process()
        p.executableURL = URL(fileURLWithPath: cliPath)
        p.arguments = ["probe-mtu", "-req-json", reqStr, "-json"]
        let pipe = Pipe()
        p.standardOutput = pipe
        p.standardError = FileHandle.nullDevice
        do {
            try p.run()
            p.waitUntilExit()
            let data = pipe.fileHandleForReading.readDataToEndOfFile()
            return try JSONDecoder().decode(MTUProbeResult.self, from: data)
        } catch {
            return nil
        }
    }
}
