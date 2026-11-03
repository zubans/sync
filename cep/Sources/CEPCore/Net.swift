import Foundation

public enum Shell {
    /// Запуск команды с ожиданием; stdout и stderr вместе.
    @discardableResult
    public static func run(_ path: String, _ args: [String], input: String? = nil) -> (status: Int32, output: String) {
        let p = Process()
        p.executableURL = URL(fileURLWithPath: path)
        p.arguments = args
        let out = Pipe()
        p.standardOutput = out
        p.standardError = out
        let inPipe = Pipe()
        p.standardInput = input == nil ? FileHandle.nullDevice : inPipe
        do {
            try p.run()
        } catch {
            return (-1, "\(path): \(error.localizedDescription)")
        }
        if let input {
            inPipe.fileHandleForWriting.write(Data(input.utf8))
            try? inPipe.fileHandleForWriting.close()
        }
        let data = out.fileHandleForReading.readDataToEndOfFile()
        p.waitUntilExit()
        return (p.terminationStatus, String(decoding: data, as: UTF8.self))
    }
}

public enum Net {
    /// IPv4-адреса интерфейсов: имя → адреса.
    public static func interfaces() -> [String: [String]] {
        var result: [String: [String]] = [:]
        var head: UnsafeMutablePointer<ifaddrs>?
        guard getifaddrs(&head) == 0, let first = head else { return result }
        defer { freeifaddrs(head) }
        var cur: UnsafeMutablePointer<ifaddrs>? = first
        while let ifa = cur {
            let name = String(cString: ifa.pointee.ifa_name)
            result[name, default: []] += []
            if let addr = ifa.pointee.ifa_addr, addr.pointee.sa_family == UInt8(AF_INET) {
                var host = [CChar](repeating: 0, count: Int(NI_MAXHOST))
                if getnameinfo(addr, socklen_t(addr.pointee.sa_len), &host, socklen_t(host.count), nil, 0, NI_NUMERICHOST) == 0 {
                    result[name, default: []].append(String(cString: host))
                }
            }
            cur = ifa.pointee.ifa_next
        }
        return result
    }

    /// Все IPv4-адреса хоста через системный резолвер: маршрут через цепочку нужен до каждого,
    /// иначе клиент может выбрать другой адрес из round-robin и пойти мимо цепочки.
    public static func resolveAllIPv4(_ host: String) throws -> [String] {
        var v4 = in_addr()
        if inet_pton(AF_INET, host, &v4) == 1 { return [host] }
        var hints = addrinfo()
        hints.ai_family = AF_INET
        hints.ai_socktype = SOCK_STREAM
        var res: UnsafeMutablePointer<addrinfo>?
        let rc = getaddrinfo(host, nil, &hints, &res)
        guard rc == 0, res != nil else {
            throw ConfigError("не удалось разрешить \(host): \(String(cString: gai_strerror(rc)))")
        }
        defer { freeaddrinfo(res) }
        var result: [String] = []
        var cur = res
        while let info = cur {
            var buf = [CChar](repeating: 0, count: Int(INET_ADDRSTRLEN))
            info.pointee.ai_addr.withMemoryRebound(to: sockaddr_in.self, capacity: 1) { sin in
                var a = sin.pointee.sin_addr
                _ = inet_ntop(AF_INET, &a, &buf, socklen_t(buf.count))
            }
            let ip = String(cString: buf)
            if !result.contains(ip) { result.append(ip) }
            cur = info.pointee.ai_next
        }
        guard !result.isEmpty else { throw ConfigError("у \(host) нет IPv4-адресов") }
        return result
    }

    public struct DefaultRoute: Sendable {
        public var gateway: String?
        public var interface: String
    }

    public static func defaultRoute() -> DefaultRoute? {
        let out = Shell.run("/sbin/route", ["-n", "get", "default"]).output
        var gateway: String?
        var iface: String?
        for line in out.components(separatedBy: .newlines) {
            let parts = line.split(separator: ":", maxSplits: 1).map { $0.trimmingCharacters(in: .whitespaces) }
            guard parts.count == 2 else { continue }
            if parts[0] == "gateway" { gateway = parts[1] }
            if parts[0] == "interface" { iface = parts[1] }
        }
        guard let iface else { return nil }
        return DefaultRoute(gateway: gateway, interface: iface)
    }
}
