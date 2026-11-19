import Foundation

/// Автоматическое переподключение цепочки после разрыва.
public enum Reconnect {
    /// Пауза перед попыткой N (с 1): сначала быстро, затем раз в минуту — пока пользователь не отключит.
    public static func delay(attempt: Int) -> TimeInterval {
        let steps: [TimeInterval] = [2, 5, 10, 20, 30]
        return attempt >= 1 && attempt <= steps.count ? steps[attempt - 1] : 60
    }

    /// Куда слать проверочный DNS-запрос, чтобы убедиться, что цепочка жива. Адреса локальных сетей
    /// и исключений не годятся: ответ от них придёт мимо цепочки. Если DNS цепочки не задан, — 1.1.1.1 и 8.8.8.8.
    public static func healthTargets(dns: [String], bypass: [String]) -> [String] {
        let candidates = dns.filter(RouteList.isIPv4)
        let fallback = ["1.1.1.1", "8.8.8.8"]
        return (candidates.isEmpty ? fallback : candidates).filter { ip in
            !privateNets.contains { RouteList.contains($0, ip) } && !bypass.contains { RouteList.contains($0, ip) }
        }
    }

    static let privateNets = ["10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16", "127.0.0.0/8", "169.254.0.0/16", "100.64.0.0/10"]

    /// Ответил ли DNS-сервер на запрос NS корневой зоны по UDP. Прокси-узлы (sing-box TUN) принимают
    /// TCP-соединение сами, до выхода в сеть, поэтому проверка по TCP ничего не говорит о живости цепочки.
    public static func dnsAnswers(_ server: String, timeout: TimeInterval = 2) -> Bool {
        let fd = socket(AF_INET, SOCK_DGRAM, 0)
        guard fd >= 0 else { return false }
        defer { close(fd) }
        var tv = timeval(tv_sec: Int(timeout), tv_usec: Int32((timeout - floor(timeout)) * 1_000_000))
        setsockopt(fd, SOL_SOCKET, SO_RCVTIMEO, &tv, socklen_t(MemoryLayout<timeval>.size))

        var addr = sockaddr_in()
        addr.sin_len = UInt8(MemoryLayout<sockaddr_in>.size)
        addr.sin_family = sa_family_t(AF_INET)
        addr.sin_port = in_port_t(UInt16(53).bigEndian)
        guard inet_pton(AF_INET, server, &addr.sin_addr) == 1 else { return false }

        let id = UInt16.random(in: 1...UInt16.max)
        // заголовок: id, RD, 1 вопрос; вопрос: корень, тип NS, класс IN
        let query: [UInt8] = [UInt8(id >> 8), UInt8(id & 0xff), 0x01, 0x00, 0x00, 0x01, 0, 0, 0, 0, 0, 0,
                              0x00, 0x00, 0x02, 0x00, 0x01]
        let sent = query.withUnsafeBytes { buf in
            withUnsafePointer(to: &addr) {
                $0.withMemoryRebound(to: sockaddr.self, capacity: 1) {
                    sendto(fd, buf.baseAddress, buf.count, 0, $0, socklen_t(MemoryLayout<sockaddr_in>.size))
                }
            }
        }
        guard sent == query.count else { return false }

        let deadline = Date().addingTimeInterval(timeout)
        var reply = [UInt8](repeating: 0, count: 1500)
        while Date() < deadline {
            let n = recv(fd, &reply, reply.count, 0)
            if n < 0 { return false }
            // чужие и запоздавшие ответы пропускаем
            // SERVFAIL может прийти и без связи с сервером (от DNS-модуля прокси) — не считаем ответом
            if n >= 12, reply[0] == UInt8(id >> 8), reply[1] == UInt8(id & 0xff), reply[2] & 0x80 != 0 { return reply[3] & 0x0f != 2 }
        }
        return false
    }
}

extension RouteList {
    /// Входит ли адрес в сеть вида 10.0.0.0/8.
    public static func contains(_ cidr: String, _ ip: String) -> Bool {
        let parts = cidr.split(separator: "/")
        guard parts.count == 2, let len = Int(parts[1]), (0...32).contains(len) else { return false }
        var net = in_addr(), host = in_addr()
        guard inet_pton(AF_INET, String(parts[0]), &net) == 1, inet_pton(AF_INET, ip, &host) == 1 else { return false }
        let mask: UInt32 = len == 0 ? 0 : ~UInt32(0) << (32 - len)
        return UInt32(bigEndian: net.s_addr) & mask == UInt32(bigEndian: host.s_addr) & mask
    }
}
