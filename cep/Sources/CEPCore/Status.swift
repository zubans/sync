import Foundation

public enum HopState: String, Codable, Sendable {
    case pending, connecting, up, failed, stopped
}

public struct HopStatus: Codable, Sendable, Identifiable {
    public var id: Int { index }
    public var index: Int
    public var name: String
    public var proto: VPNProtocol
    public var state: HopState = .pending
    public var serverIP: String?
    public var interface: String?
    public var localAddress: String?
    public var message: String?
    /// Сети, проложенные через интерфейс узла, и внутренний DNS.
    public var routes: [String]?
    public var innerDNS: String?

    public init(index: Int, name: String, proto: VPNProtocol) {
        self.index = index
        self.name = name
        self.proto = proto
    }
}

public enum EnginePhase: String, Codable, Sendable {
    case starting, connecting, connected, reconnecting, stopping, stopped, failed
}

public struct EngineStatus: Codable, Sendable {
    public var phase: EnginePhase
    public var chainName: String
    public var hops: [HopStatus]
    public var error: String?
    /// Исключения цепочки, проложенные через исходный шлюз, и те, что добавить не удалось.
    public var bypass: [String]?
    public var bypassFailed: [String]?
    /// Номер попытки переподключения и когда она начнётся; nil — цепочка не переподключается.
    public var reconnectAttempt: Int?
    public var nextAttemptAt: Date?
    /// Сколько раз цепочку уже поднимали заново после разрыва.
    public var reconnects: Int?
    /// Включена блокировка трафика мимо цепочки на время переподключения.
    public var killSwitch: Bool?
    public var pid: Int32
    public var updatedAt: Date

    public init(phase: EnginePhase, chainName: String, hops: [HopStatus], pid: Int32) {
        self.phase = phase
        self.chainName = chainName
        self.hops = hops
        self.pid = pid
        updatedAt = Date()
    }

    public var isActive: Bool {
        [.starting, .connecting, .connected, .reconnecting, .stopping].contains(phase)
    }
}

/// Файлы обмена между приложением и движком.
public struct RunDirectory: Sendable {
    public let url: URL
    public init(_ url: URL) { self.url = url }

    public var plan: URL { url.appendingPathComponent("plan.json") }
    public var status: URL { url.appendingPathComponent("status.json") }
    public var stopRequest: URL { url.appendingPathComponent("stop") }
    public var engineLog: URL { url.appendingPathComponent("engine.log") }
    /// Метка: стоят blackhole-маршруты kill switch. Если движок убили, следующий запуск их снимет.
    public var killSwitchMarker: URL { url.appendingPathComponent("killswitch") }
    public func hopLog(_ i: Int) -> URL { url.appendingPathComponent("hop\(i + 1).log") }

    public func readStatus() -> EngineStatus? {
        guard let data = try? Data(contentsOf: status) else { return nil }
        let dec = JSONDecoder()
        dec.dateDecodingStrategy = .iso8601
        return try? dec.decode(EngineStatus.self, from: data)
    }
}
