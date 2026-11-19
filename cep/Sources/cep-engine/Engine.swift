import CEPCore
import Foundation

struct EngineError: LocalizedError {
    let message: String
    init(_ message: String) { self.message = message }
    var errorDescription: String? { message }
}

/// Поднимает цепочку узлов: каждый следующий сервер доступен только через интерфейс предыдущего узла,
/// весь остальной трафик уходит в последний узел (0.0.0.0/1 + 128.0.0.0/1).
final class Engine {
    private let dir: RunDirectory
    private let plan: Plan
    private var status: EngineStatus
    private var processes: [[Process]] = []
    private var hostRoutes: [String] = []
    private var netRoutes: [String] = []
    /// Исключения цепочки через исходный шлюз: сеть и как её удалять (-host для /32, -net для остальных).
    private var bypassRoutes: [(kind: String, target: String)] = []
    private var v6Blackhole = false
    private var dns: DNSOverride?
    private var splitDNS: [SplitDNS] = []
    private var argonRoutes: [Int: [String]] = [:]
    /// Маршрут по умолчанию до подъёма цепочки: по нему видно, что Mac сменил сеть.
    private var original: Net.DefaultRoute?
    /// Адреса серверов с последнего удачного подключения: под kill switch системный DNS может быть недоступен.
    private var cachedServerIPs: [[String]]?
    /// Стоят blackhole-маршруты 0.0.0.0/1 и 128.0.0.0/1 на время переподключения.
    private var killSwitchOn = false
    /// Через что проложены исключения: после смены сети их надо проложить заново.
    private var bypassVia: [String] = []
    private let hopTimeout: TimeInterval = 60
    /// Конфиги с паролями и скрипты: каталог root без пробелов в пути.
    /// openconnect запускает --script через /bin/sh, путь с «Application Support» там разваливается.
    private let privateDir = URL(fileURLWithPath: "/var/run/cep", isDirectory: true)

    init(dir: RunDirectory, plan: Plan) {
        self.dir = dir
        self.plan = plan
        status = EngineStatus(
            phase: .starting,
            chainName: plan.chainName,
            hops: plan.hops.enumerated().map { HopStatus(index: $0.offset, name: $0.element.name, proto: $0.element.proto) },
            pid: getpid()
        )
    }

    // MARK: - Жизненный цикл

    /// Поднимает цепочку и держит её. После разрыва уже работавшей цепочки снимает всё и поднимает заново
    /// с нарастающей паузой, пока не придёт команда остановки. Первая неудачная попытка — ошибка без повторов:
    /// это почти всегда настройки, и пользователь как раз смотрит на экран.
    func run() -> Int32 {
        writeStatus()
        var wasConnected = false
        var attempt = 0
        while true {
            try? FileManager.default.removeItem(at: privateDir)
            do {
                try FileManager.default.createDirectory(at: privateDir, withIntermediateDirectories: true,
                                                        attributes: [.posixPermissions: 0o700])
                try connect()
                if attempt > 0 { status.reconnects = (status.reconnects ?? 0) + 1 }
                wasConnected = true
                attempt = 0
                status.phase = .connected
                status.error = nil
                status.reconnectAttempt = nil
                status.nextAttemptAt = nil
                writeStatus()
                log("цепочка поднята: \(status.hops.compactMap(\.interface).joined(separator: " → "))")
                try supervise()
                teardown(phase: .stopped)
                return 0
            } catch {
                log("ошибка: \(error.localizedDescription)")
                status.error = error.localizedDescription
                guard !Stop.requested, plan.autoReconnect, wasConnected else {
                    teardown(phase: Stop.requested ? .stopped : .failed)
                    return 1
                }
                release(keepProtection: plan.killSwitch)
                if plan.killSwitch { setKillSwitch(true) }
                attempt += 1
                let delay = Reconnect.delay(attempt: attempt)
                status.phase = .reconnecting
                status.reconnectAttempt = attempt
                status.nextAttemptAt = Date().addingTimeInterval(delay)
                writeStatus()
                log("переподключение: попытка \(attempt) через \(Int(delay)) с")
                guard pause(delay) else {
                    teardown(phase: .stopped)
                    return 0
                }
            }
        }
    }

    /// Пауза между попытками; false — пришла команда остановки.
    private func pause(_ seconds: TimeInterval) -> Bool {
        let deadline = Date().addingTimeInterval(seconds)
        while Date() < deadline {
            if Stop.requested || FileManager.default.fileExists(atPath: dir.stopRequest.path) {
                log("получена команда остановки")
                Stop.requested = true
                return false
            }
            Thread.sleep(forTimeInterval: 0.5)
        }
        return true
    }

    private func connect() throws {
        let problems = ChainValidation.problems(plan)
        guard problems.isEmpty else { throw EngineError(problems.joined(separator: "; ")) }
        status.hops = plan.hops.enumerated().map { HopStatus(index: $0.offset, name: $0.element.name, proto: $0.element.proto) }
        writeStatus()
        guard let original = Net.defaultRoute() else { throw EngineError("нет маршрута по умолчанию — нет сети?") }
        self.original = original
        log("исходный маршрут: \(original.gateway ?? "-") через \(original.interface)")

        // Все адреса разрешаем заранее системным DNS, пока цепочка не изменила маршруты.
        // Под kill switch DNS заблокирован вместе с остальным трафиком — берём адреса прошлого подключения.
        var serverIPs: [[String]] = []
        if killSwitchOn, let cached = cachedServerIPs {
            serverIPs = cached
            log("адреса серверов — с прошлого подключения: \(cached.map { $0.joined(separator: ", ") }.joined(separator: "; "))")
        } else {
            for hop in plan.hops {
                let host = try Endpoint.of(hop).host
                let ips = try Net.resolveAllIPv4(host)
                log("\(hop.name): \(host) → \(ips.joined(separator: ", "))")
                serverIPs.append(ips)
            }
            cachedServerIPs = serverIPs
        }

        let addRoutes = { (ips: [String], interface: String?) throws in
            for ip in ips {
                if let interface {
                    try self.addHostRoute(ip, args: ["-interface", interface])
                } else if let gw = original.gateway, !gw.hasPrefix("link#") {
                    try self.addHostRoute(ip, args: [gw])
                } else {
                    try self.addHostRoute(ip, args: ["-interface", original.interface])
                }
            }
        }

        try routeBypass(original)

        status.phase = .connecting
        var previousInterface: String?
        var pendingRedirect: Int?
        var blindRelay: (ip: String, port: Int)?
        var nestedUDPBudget: Int? = nil

        func clampedHop(_ profile: Profile) -> Profile {
            guard let budget = nestedUDPBudget else { return profile }
            var copy = profile
            if copy.argonPathMTU == nil || copy.argonPathMTU! > budget {
                copy.argonPathMTU = budget
            }
            return copy
        }

        func nextNestedBudget(for profile: Profile) -> Int {
            let outer = min(profile.argonPathMTU ?? 1500, nestedUDPBudget ?? 1500)
            return max(576, outer - 64)
        }

        for (i, hop) in plan.hops.enumerated() {
            try checkStop()
            setHop(i) { $0.state = .connecting; $0.serverIP = serverIPs[i][0] }
            do {
                if hop.proto == .transit {
                    let transitEP = try Endpoint.argon4(hop.server)
                    let transitPort = transitEP.port ?? 51820
                    if hop.password.isEmpty {
                        let connectIP: String
                        let connectPort: Int
                        if let relay = blindRelay {
                            connectIP = relay.ip
                            connectPort = relay.port
                        } else if let redirPort = pendingRedirect {
                            pendingRedirect = nil
                            connectIP = "127.0.0.1"
                            connectPort = redirPort
                        } else {
                            try addRoutes(serverIPs[i], previousInterface)
                            connectIP = serverIPs[i][0]
                            connectPort = transitPort
                        }
                        blindRelay = (connectIP, connectPort)
                        var effectiveBlindOuter = hop.argonPathMTU
                        if effectiveBlindOuter == nil {
                            let nextProto = (i + 1 < plan.hops.count && !plan.hops[i + 1].useArgon) ? ArgonTransportProto.of(plan.hops[i + 1]) : "udp"
                            let probe = MTUProbe.probeEndpointSync(
                                host: connectIP,
                                port: connectPort,
                                proto: "transit",
                                transport: nextProto == "tcp" ? "tcp" : "udp",
                                precedingBudget: nestedUDPBudget ?? 0,
                                timeoutMs: 1200
                            )
                            if probe.ok, probe.optimalPathMTU > 0 {
                                effectiveBlindOuter = probe.optimalPathMTU
                            }
                        }
                        if let blindOuter = effectiveBlindOuter {
                            var blindHop = hop
                            blindHop.argonPathMTU = blindOuter
                            nestedUDPBudget = nextNestedBudget(for: blindHop)
                        }
                        setHop(i) {
                            $0.state = .up
                            $0.interface = previousInterface ?? original.interface
                            $0.localAddress = "\(connectIP):\(connectPort)"
                            $0.message = "слепой транзит к узлу \(i + 2)"
                        }
                        log("узел \(i + 1) «\(hop.name)»: слепой транзит → \(connectIP):\(connectPort) (внешн_mtu=\(effectiveBlindOuter.map(String.init) ?? "авто"), бюджет=\(nestedUDPBudget.map(String.init) ?? "авто"))")
                    } else {
                        let nextHop = plan.hops[i + 1]
                        let nextPort = nextHop.useArgon ? nextHop.argonUnderlayPort : (try Endpoint.resolvedPort(for: nextHop))
                        let target = "\(serverIPs[i + 1][0]):\(nextPort)"
                        let targetProto = nextHop.useArgon ? "udp" : ArgonTransportProto.of(nextHop)

                        let connectIP: String
                        let connectPort: Int
                        if let relay = blindRelay {
                            blindRelay = nil
                            connectIP = relay.ip
                            connectPort = relay.port
                        } else if let redirPort = pendingRedirect {
                            pendingRedirect = nil
                            connectIP = "127.0.0.1"
                            connectPort = redirPort
                        } else {
                            try addRoutes(serverIPs[i], previousInterface)
                            connectIP = serverIPs[i][0]
                            connectPort = transitPort
                        }
                        let effectiveHop = clampedHop(hop)
                        let ready = try runArgonHelper(
                            i, effectiveHop, ip: connectIP, underlay: false,
                            mode: "transit", target: target, proto: nextHop.useArgon ? "" : ArgonTransportProto.of(nextHop),
                            listen: "127.0.0.1:0", portOverride: connectPort
                        )
                        guard ready.mode == "transit", ready.port > 0 else {
                            throw EngineError("Транзитный узел вернул неожиданный режим \(ready.mode)")
                        }
                        pendingRedirect = ready.port
                        var discoveredHop = effectiveHop
                        if let probedPath = ready.pathMTU, probedPath > 0 {
                            if discoveredHop.argonPathMTU == nil || probedPath < discoveredHop.argonPathMTU! {
                                discoveredHop.argonPathMTU = probedPath
                            }
                        }
                        nestedUDPBudget = (targetProto == "udp") ? nextNestedBudget(for: discoveredHop) : nil
                        setHop(i) {
                            $0.state = .up
                            $0.interface = previousInterface ?? original.interface
                            $0.localAddress = "127.0.0.1:\(ready.port)"
                            $0.message = "L4-транзит → \(target)"
                        }
                        log("узел \(i + 1) «\(hop.name)»: транзит слушает 127.0.0.1:\(ready.port) → \(target) (path_mtu=\(ready.pathMTU ?? 0), бюджет=\(nestedUDPBudget ?? 0))")
                    }
                    continue
                }

                var routeInterface = previousInterface
                var hopDialIP = serverIPs[i][0]
                var hopPortOverride: Int?
                var effectiveHop = clampedHop(hop)

                if effectiveHop.useArgon {
                    let hopPort = try Endpoint.resolvedPort(for: effectiveHop)
                    let argonIP: String
                    let argonPort: Int
                    if let relay = blindRelay {
                        blindRelay = nil
                        argonIP = relay.ip
                        argonPort = relay.port
                    } else if let redirPort = pendingRedirect {
                        pendingRedirect = nil
                        argonIP = "127.0.0.1"
                        argonPort = redirPort
                    } else {
                        try addRoutes(serverIPs[i], routeInterface)
                        argonIP = serverIPs[i][0]
                        argonPort = effectiveHop.argonUnderlayPort
                    }

                    let ready = try runArgonHelper(
                        i, effectiveHop, ip: argonIP, underlay: true,
                        mode: "auto", target: "\(serverIPs[i][0]):\(hopPort)",
                        proto: ArgonTransportProto.of(effectiveHop), listen: "127.0.0.1:0", portOverride: argonPort
                    )
                    if ready.mode == "transit", ready.port > 0 {
                        hopDialIP = "127.0.0.1"
                        hopPortOverride = ready.port
                        if let probedPath = ready.pathMTU, probedPath > 0, ArgonTransportProto.of(effectiveHop) == "udp" {
                            effectiveHop.argonPathMTU = max(576, min(probedPath, nestedUDPBudget ?? 1500) - 64)
                        }
                        log("узел \(i + 1) «\(hop.name)»: Argon4-надстройка работает в режиме L4-транзита на 127.0.0.1:\(ready.port) (path_mtu=\(ready.pathMTU ?? 0))")
                    } else {
                        guard !ready.interface.isEmpty, !ready.address.isEmpty else {
                            throw EngineError("Argon4: помощник вернул пустой интерфейс или адрес")
                        }
                        if ready.mtu > 0, effectiveHop.argonPathMTU == nil || ready.mtu < effectiveHop.argonPathMTU! {
                            effectiveHop.argonPathMTU = ready.mtu
                        }
                        log("узел \(i + 1) «\(hop.name)»: Argon4-надстройка поднята на \(ready.interface) (\(ready.address), mtu=\(ready.mtu))")
                        routeInterface = ready.interface
                        try addRoutes(serverIPs[i], routeInterface)
                    }
                } else if let relay = blindRelay {
                    blindRelay = nil
                    hopDialIP = relay.ip
                    hopPortOverride = relay.port
                } else if let redirPort = pendingRedirect {
                    pendingRedirect = nil
                    hopDialIP = "127.0.0.1"
                    hopPortOverride = redirPort
                } else {
                    try addRoutes(serverIPs[i], routeInterface)
                }

                let (iface, address) = try startHop(i, effectiveHop, ip: hopDialIP, portOverride: hopPortOverride)
                nestedUDPBudget = nil
                setHop(i) { $0.state = .up; $0.interface = iface; $0.localAddress = address; $0.message = nil }
                log("узел \(i + 1) «\(hop.name)» поднят на \(iface) (\(address))")
                try routeInner(i, hop, iface: iface)
                previousInterface = iface
            } catch {
                setHop(i) { $0.state = .failed; $0.message = error.localizedDescription }
                throw EngineError("узел \(i + 1) «\(hop.name)»: \(error.localizedDescription)")
            }
        }

        guard let last = previousInterface else { throw EngineError("пустая цепочка") }
        setKillSwitch(false)
        for net in ["0.0.0.0/1", "128.0.0.0/1"] {
            try route(["add", "-net", net, "-interface", last])
            netRoutes.append(net)
        }
        if plan.blockIPv6 && !v6Blackhole {
            v6Blackhole = Shell.run("/sbin/route", ["-n", "add", "-inet6", "-net", "2000::/3", "::1", "-blackhole"]).status == 0
        }
        if let current = dns, current.isStale {
            current.restore()
            dns = nil
        }
        if dns != nil {
            dns?.apply()
        } else if !plan.dns.isEmpty {
            dns = DNSOverride(servers: plan.dns)
            dns?.apply()
            log("DNS: \(plan.dns.joined(separator: ", "))")
        }
    }

    /// Следит за цепочкой. Падение процесса узла — разрыв всегда. Остальные признаки разрыва проверяются
    /// только с автопереподключением: без него цепочка, как и раньше, остаётся висеть до ручного отключения.
    private func supervise() throws {
        let health = Reconnect.healthTargets(dns: plan.dns, bypass: bypassRoutes.map { $0.kind == "-host" ? "\($0.target)/32" : $0.target })
        var healthFailures = 0
        var noNetwork = 0
        var tick = 0
        while true {
            Thread.sleep(forTimeInterval: 0.5)
            tick += 1
            if Stop.requested || FileManager.default.fileExists(atPath: dir.stopRequest.path) {
                log("получена команда остановки")
                return
            }
            for (i, procs) in processes.enumerated() {
                if let dead = procs.first(where: { !$0.isRunning }) {
                    let reason = "процесс \(dead.executableURL?.lastPathComponent ?? "?") завершился (код \(dead.terminationStatus))"
                    setHop(i) { $0.state = .failed; $0.message = reason }
                    throw EngineError("узел \(i + 1) «\(plan.hops[i].name)»: \(reason)")
                }
            }
            if tick % 20 == 0 {
                dns?.reapplyIfChanged()
            }
            guard plan.autoReconnect else { continue }
            if tick % 4 == 0 {
                try checkInterfaces()
            }
            if tick % 10 == 0 {
                try checkNetwork(&noNetwork)
            }
            // первая проверка через 30 с после подъёма, затем каждые 15 с; разрыв — после трёх неудач подряд
            if !health.isEmpty, tick >= 60, tick % 30 == 0 {
                if health.contains(where: { Reconnect.dnsAnswers($0) }) {
                    healthFailures = 0
                } else {
                    healthFailures += 1
                    log("нет ответа через цепочку от \(health.joined(separator: ", ")) (\(healthFailures)/3)")
                    if healthFailures >= 3 {
                        throw EngineError("цепочка не отвечает: нет ответа DNS от \(health.joined(separator: ", ")) за 45 с")
                    }
                }
            }
        }
    }

    /// Интерфейс узла исчез (utun закрыт), а процесс ещё жив.
    private func checkInterfaces() throws {
        let present = Net.interfaces()
        for (i, hop) in status.hops.enumerated() where plan.hops[i].proto != .transit {
            if let iface = hop.interface, present[iface] == nil {
                setHop(i) { $0.state = .failed; $0.message = "интерфейс \(iface) пропал" }
                throw EngineError("узел \(i + 1) «\(hop.name)»: интерфейс \(iface) пропал")
            }
        }
    }

    /// Mac сменил сеть (другой Wi-Fi, кабель): маршрут до первого сервера смотрит на старый шлюз.
    /// Пропажу сети терпим 15 с — на короткий обрыв Wi-Fi клиенты узлов переживут его сами.
    private func checkNetwork(_ noNetwork: inout Int) throws {
        guard let original else { return }
        guard let now = Net.defaultRoute() else {
            noNetwork += 1
            if noNetwork >= 3 { throw EngineError("пропала сеть: нет маршрута по умолчанию") }
            return
        }
        noNetwork = 0
        let ours = Set(status.hops.compactMap(\.interface))
        if ours.contains(now.interface) { return }
        if now.interface != original.interface || now.gateway != original.gateway {
            throw EngineError("сменилась сеть: \(original.gateway ?? "-") через \(original.interface) → \(now.gateway ?? "-") через \(now.interface)")
        }
    }

    private func teardown(phase: EnginePhase) {
        status.phase = .stopping
        writeStatus()
        release(keepProtection: false)
        setKillSwitch(false)
        for i in status.hops.indices where status.hops[i].state == .up || status.hops[i].state == .connecting {
            status.hops[i].state = .stopped
        }
        status.reconnectAttempt = nil
        status.nextAttemptAt = nil
        try? FileManager.default.removeItem(at: dir.stopRequest)
        status.phase = phase
        writeStatus()
        log("остановлено")
    }

    /// Снимает всё, что поставила цепочка: DNS, маршруты, процессы узлов, секреты. Движок после этого
    /// может поднять цепочку заново. keepProtection — переподключение под kill switch: подмена DNS,
    /// блокировка IPv6 и исключения остаются, чтобы ни запросы, ни IPv6 не ушли мимо цепочки.
    private func release(keepProtection: Bool) {
        if !keepProtection {
            dns?.restore()
            dns = nil
        }
        splitDNS.forEach { $0.remove() }
        splitDNS = []
        for net in netRoutes {
            Shell.run("/sbin/route", ["-n", "delete", "-net", net])
        }
        netRoutes = []
        if !keepProtection {
            removeBypass()
        }
        if v6Blackhole && !keepProtection {
            Shell.run("/sbin/route", ["-n", "delete", "-inet6", "-net", "2000::/3"])
            v6Blackhole = false
        }
        for procs in processes.reversed() {
            for p in procs.reversed() { terminate(p) }
        }
        processes = []
        for ip in hostRoutes.reversed() {
            Shell.run("/sbin/route", ["-n", "delete", "-host", ip])
        }
        hostRoutes = []
        argonRoutes = [:]
        try? FileManager.default.removeItem(at: privateDir)
        try? FileManager.default.removeItem(at: dir.url.appendingPathComponent("children.pid"))
    }

    /// Исключения цепочки: сети и адреса через исходный шлюз. Они точнее 0.0.0.0/1 и 128.0.0.0/1,
    /// поэтому обходят цепочку, но не мешают host-маршрутам до серверов узлов (/32 точнее любой сети).
    /// Ставятся до узлов: сеть, совпадающая с внутренней сетью узла, остаётся за исключением.
    /// При переподключении под kill switch они уже стоят — заново прокладываются, только если сменилась сеть.
    private func routeBypass(_ original: Net.DefaultRoute) throws {
        let cidrs = try RouteList.parse(plan.bypass)
        guard !cidrs.isEmpty else { return }
        let via: [String]
        if let gw = original.gateway, !gw.hasPrefix("link#") {
            via = [gw]
        } else {
            via = ["-interface", original.interface]
        }
        if via == bypassVia, status.bypass != nil || status.bypassFailed != nil { return }
        removeBypass()
        bypassVia = via
        var applied: [String] = []
        var failed: [String] = []
        for cidr in cidrs {
            let host = cidr.hasSuffix("/32")
            let kind = host ? "-host" : "-net"
            let target = host ? String(cidr.dropLast(3)) : cidr
            let r = Shell.run("/sbin/route", ["-n", "add", kind, target] + via)
            if r.status == 0 {
                bypassRoutes.append((kind, target))
                applied.append(cidr)
            } else if r.output.contains("File exists") {
                // сеть уже маршрутизирована мимо будущей цепочки (локальная подсеть) — исключение и так работает
                applied.append(cidr)
                log("исключение \(cidr): маршрут уже есть, оставлен как есть")
            } else {
                failed.append("\(cidr): \(r.output.trimmingCharacters(in: .whitespacesAndNewlines))")
            }
        }
        log("мимо цепочки через \(via.joined(separator: " ")): \(applied.joined(separator: ", "))")
        for f in failed { log("исключение не добавлено — \(f)") }
        status.bypass = applied.isEmpty ? nil : applied
        status.bypassFailed = failed.isEmpty ? nil : failed
        writeStatus()
    }

    private func removeBypass() {
        for r in bypassRoutes.reversed() {
            Shell.run("/sbin/route", ["-n", "delete", r.kind, r.target])
        }
        bypassRoutes = []
        bypassVia = []
        status.bypass = nil
        status.bypassFailed = nil
    }

    /// Kill switch: на время переподключения 0.0.0.0/1 и 128.0.0.0/1 уходят в blackhole. Локальная сеть,
    /// исключения и host-маршруты до серверов узлов точнее, поэтому работают, и цепочка может подняться.
    private func setKillSwitch(_ on: Bool) {
        guard on != killSwitchOn else { return }
        for net in ["0.0.0.0/1", "128.0.0.0/1"] {
            Shell.run("/sbin/route", ["-n", "delete", "-net", net])
            if on {
                let r = Shell.run("/sbin/route", ["-n", "add", "-net", net, "127.0.0.1", "-blackhole"])
                log("kill switch: \(net) → blackhole: \(r.output.trimmingCharacters(in: .whitespacesAndNewlines))")
            }
        }
        if on {
            FileManager.default.createFile(atPath: dir.killSwitchMarker.path, contents: nil)
        } else {
            try? FileManager.default.removeItem(at: dir.killSwitchMarker)
            log("kill switch снят")
        }
        killSwitchOn = on
        status.killSwitch = on ? true : nil
        writeStatus()
    }

    // MARK: - Узлы

    /// Внутренние сети узла через его интерфейс: от сервера (split-include OpenConnect, PUSH_REPLY OpenVPN)
    /// и из профиля. Маршрут на сеть точнее 0.0.0.0/1, поэтому он работает и для узла в середине цепочки,
    /// и для адресов из локальной подсети (192.168.1.119 при локальной 192.168.1.0/24 — только через /32).
    private func routeInner(_ i: Int, _ hop: Profile, iface: String) throws {
        var inner = InnerNetwork()
        switch hop.proto {
        case .openconnect:
            let file = privateFile(i, "inner")
            let text = try? waitUntil(hop: i, "внутренние сети OpenConnect", timeout: 5) {
                try? String(contentsOf: file, encoding: .utf8)
            }
            inner = RouteList.fromOpenConnect(text ?? "")
        case .openvpn:
            let log = (try? String(contentsOf: dir.hopLog(i), encoding: .utf8)) ?? ""
            inner = RouteList.fromOpenVPN(log: log, config: hop.config)
        case .argon4:
            inner = RouteList.fromArgon4(routes: argonRoutes[i] ?? [])
        default:
            break
        }
        let fromServer = inner.routes
        for cidr in try RouteList.parse(hop.routes) where !inner.routes.contains(cidr) {
            inner.routes.append(cidr)
        }
        // до внутреннего DNS — тоже через узел, иначе запрос уйдёт дальше по цепочке
        for ip in inner.dns where !inner.routes.contains("\(ip)/32") && !inner.domains.isEmpty {
            inner.routes.append("\(ip)/32")
        }

        var applied: [String] = []
        var failed: [String] = []
        for cidr in inner.routes {
            // чужой маршрут на ту же сеть (например, 192.168.1.0/24 локальной сети на en0) не трогаем:
            // добавление упадёт, и в статусе узла будет подсказка задать отдельные адреса /32
            let r = Shell.run("/sbin/route", ["-n", "add", "-net", cidr, "-interface", iface])
            if r.status == 0 {
                netRoutes.append(cidr)
                applied.append(cidr)
            } else {
                let reason = r.output.contains("File exists") ? "такая сеть уже есть (локальная сеть?) — укажите в профиле нужные адреса /32" : r.output.trimmingCharacters(in: .whitespacesAndNewlines)
                failed.append("\(cidr): \(reason)")
            }
        }
        var dnsNote: String?
        if let split = SplitDNS(id: "hop\(i + 1)", servers: inner.dns, domains: inner.domains) {
            splitDNS.append(split)
            dnsNote = "\(inner.domains.joined(separator: ", ")) → \(inner.dns.joined(separator: ", "))"
        }
        log("узел \(i + 1): от сервера сети [\(fromServer.joined(separator: ", "))], DNS [\(inner.dns.joined(separator: ", "))], домены [\(inner.domains.joined(separator: ", "))]")
        if !applied.isEmpty { log("узел \(i + 1): через \(iface) — \(applied.joined(separator: ", "))") }
        for f in failed { log("узел \(i + 1): маршрут не добавлен — \(f)") }
        setHop(i) {
            $0.routes = applied.isEmpty ? nil : applied
            $0.innerDNS = dnsNote
            $0.message = failed.isEmpty ? nil : "не добавлены маршруты: " + failed.joined(separator: "; ")
        }
    }

    private func startHop(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?) throws -> (String, String) {
        let before = Set(Net.interfaces().keys)
        switch hop.proto {
        case .vless, .hysteria2, .hysteria:
            return try startProxy(i, hop, ip: ip, portOverride: portOverride)
        case .openvpn:
            return try startOpenVPN(i, hop, ip: ip, portOverride: portOverride, before: before)
        case .openconnect:
            return try startOpenConnect(i, hop, ip: ip, portOverride: portOverride, before: before)
        case .argon4:
            return try startArgon(i, hop, ip: ip, portOverride: portOverride, underlay: false)
        case .transit:
            throw EngineError("Внутренняя ошибка: .transit обрабатывается до startHop")
        case .sstp:
            return try startSSTP(i, hop, ip: ip, portOverride: portOverride, before: before)
        case .amneziawg:
            return try startAWG(i, hop, ip: ip, portOverride: portOverride)
        }
    }

    private func runArgonHelper(
        _ i: Int,
        _ hop: Profile,
        ip: String,
        underlay: Bool,
        mode: String? = nil,
        target: String? = nil,
        proto: String? = nil,
        listen: String? = nil,
        portOverride: Int? = nil
    ) throws -> Argon4Ready {
        let readyFile = privateFile(i, "argon4-ready.json")
        try? FileManager.default.removeItem(at: readyFile)
        let configuration = try Argon4Configuration(
            profile: hop,
            resolvedIP: ip,
            readyFile: readyFile.path,
            underlay: underlay,
            mode: mode,
            target: target,
            proto: proto,
            listen: listen,
            portOverride: portOverride
        )
        let configFile = privateFile(i, "argon4.json")
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        try writeSecret(try encoder.encode(configuration), to: configFile)
        try spawn(i, tool: .cepArgon4, ["--config", configFile.path])
        let data = try waitUntil(hop: i, "Argon4 handshake", timeout: hopTimeout) {
            try? Data(contentsOf: readyFile)
        }
        return try JSONDecoder().decode(Argon4Ready.self, from: data)
    }

    /// Запускает Argon4 TUN-клиент.
    private func startArgon(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?, underlay: Bool) throws -> (String, String) {
        let ready = try runArgonHelper(i, hop, ip: ip, underlay: underlay, portOverride: portOverride)
        guard !ready.interface.isEmpty, !ready.address.isEmpty else {
            throw EngineError("Argon4: помощник вернул пустой интерфейс или адрес")
        }
        argonRoutes[i] = ready.routes
        return (ready.interface, ready.address)
    }

    private func startProxy(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?) throws -> (String, String) {
        let link = try ProxyLink.parse(hop.uri)
        let outbound: [String: Any]
        if link.scheme == "vless" {
            let port = 20800 + i
            let xray = try XrayConfig.make(link: link, server: ip, portOverride: portOverride, socksPort: port)
            let cfg = privateFile(i, "xray.json")
            try writeSecret(JSON.data(xray), to: cfg)
            try spawn(i, tool: .xray, ["run", "-c", cfg.path])
            try waitForPort(port, hop: i)
            outbound = SingBoxConfig.socksOutbound(port: port)
        } else {
            outbound = try SingBoxConfig.outbound(for: link, server: ip, portOverride: portOverride)
        }
        let address = "172.19.\(i).1"
        let cfg = privateFile(i, "config")
        let proxyMTU = min(1400, max(576, (hop.argonPathMTU ?? 1500) - 64))
        try writeSecret(JSON.data(SingBoxConfig.make(tunAddress: "\(address)/30", outbound: outbound, mtu: proxyMTU)), to: cfg)
        try spawn(i, tool: .singBox, ["run", "-c", cfg.path, "-D", privateDir.path, "--disable-color"])
        let iface = try waitForInterface(hop: i) { _, addrs in addrs.contains(address) }
        return (iface.name, address)
    }

    private func startOpenVPN(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?, before: Set<String>) throws -> (String, String) {
        let cfg = privateFile(i, "config")
        try writeSecret(Data(OpenVPNConfig.pinRemote(hop.config, ip: ip, portOverride: portOverride).utf8), to: cfg)
        var args = [
            "--config", cfg.path,
            "--route-noexec",
            "--pull-filter", "ignore", "redirect-gateway",
            "--pull-filter", "ignore", "dhcp-option",
            "--connect-retry-max", "3",
            // verb 3 пишет PUSH_REPLY в журнал: по нему движок прокладывает внутренние сети узла
            "--verb", "3",
            // После --config: перекрывает script-security из .ovpn, чужие up/down-скрипты под root не выполнятся.
            "--script-security", "1",
        ]
        var nestedOVPNMTU: Int?
        if let cap = hop.argonPathMTU {
            let nested = max(576, cap - 64)
            nestedOVPNMTU = nested
            let mss = max(480, nested - 60)
            args += ["--mssfix", "\(mss)"]
        }
        if !hop.username.isEmpty {
            let auth = privateFile(i, "auth")
            try writeSecret(Data("\(hop.username)\n\(hop.password)\n".utf8), to: auth)
            args += ["--auth-user-pass", auth.path, "--auth-nocache"]
        }
        try spawn(i, tool: .openvpn, args)
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("utun") && !addrs.isEmpty }
        if let nested = nestedOVPNMTU, nested < 1500 {
            _ = Shell.run("/sbin/ifconfig", [iface.name, "mtu", "\(nested)"])
        }
        return (iface.name, iface.address)
    }

    private func startOpenConnect(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?, before: Set<String>) throws -> (String, String) {
        // Вместо vpnc-script: только поднимаем интерфейс, маршрутами и DNS управляет движок.
        let script = privateFile(i, "script")
        try writeSecret(Data("""
        #!/bin/sh
        case "$reason" in
          connect)
            OC_MTU="${CEP_FORCE_MTU:-${INTERNAL_IP4_MTU:-1300}}"
            if [ -n "$INTERNAL_IP4_MTU" ] && [ "$INTERNAL_IP4_MTU" -lt "$OC_MTU" ] 2>/dev/null; then
              OC_MTU="$INTERNAL_IP4_MTU"
            fi
            /sbin/ifconfig "$TUNDEV" inet "$INTERNAL_IP4_ADDRESS" "$INTERNAL_IP4_ADDRESS" netmask 255.255.255.255 mtu "$OC_MTU" up
            # внутренние сети и DNS от сервера — движку, он проложит их через этот узел
            {
              n=0
              while [ "$n" -lt "${CISCO_SPLIT_INC:-0}" ]; do
                eval "echo INC=\\${CISCO_SPLIT_INC_${n}_ADDR}/\\${CISCO_SPLIT_INC_${n}_MASKLEN}"
                n=$((n + 1))
              done
              echo "DNS=$INTERNAL_IP4_DNS"
              echo "DOMAIN=$CISCO_DEF_DOMAIN $CISCO_SPLIT_DNS"
            } > "$CEP_INNER_FILE.tmp" && mv "$CEP_INNER_FILE.tmp" "$CEP_INNER_FILE" ;;
          disconnect)
            /sbin/ifconfig "$TUNDEV" down 2>/dev/null ;;
        esac
        exit 0

        """.utf8), to: script, mode: 0o700)

        let endpoint = try Endpoint.of(hop)
        var args = [
            "--protocol=\(hop.openconnectFlavor.rawValue)",
            "--non-inter",
            "--passwd-on-stdin",
            "--script=\(script.path)",
            "--no-dtls",
        ]
        if portOverride != nil {
            args.append("--resolve=\(endpoint.host):127.0.0.1")
        } else if endpoint.host != ip {
            args.append("--resolve=\(endpoint.host):\(ip)")
        }
        if !hop.username.isEmpty { args.append("--user=\(hop.username)") }
        if !hop.serverCert.isEmpty { args.append("--servercert=\(hop.serverCert)") }
        if let ca = Tool.bundledCAFile { args.append("--cafile=\(ca)") }
        if !hop.authGroup.isEmpty { args.append("--authgroup=\(hop.authGroup)") }
        args.append(portOverride.map { Endpoint.rewritePort(hop.server, port: $0) } ?? hop.server.trimmingCharacters(in: .whitespaces))
        var ocEnv = ["CEP_INNER_FILE": privateFile(i, "inner").path]
        if let cap = hop.argonPathMTU {
            ocEnv["CEP_FORCE_MTU"] = String(max(576, cap - 64))
        }
        try spawn(i, tool: .openconnect, args, stdin: hop.password + "\n", env: ocEnv)
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("utun") && !addrs.isEmpty }
        return (iface.name, iface.address)
    }

    private func startSSTP(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?, before: Set<String>) throws -> (String, String) {
        var args = ["--log-stderr", "--log-level", "3", "--user", hop.username, "--password", hop.password]
        if hop.ignoreCertErrors { args.append("--cert-warn") }
        if let ca = Tool.bundledCAFile { args += ["--ca-cert", ca] }
        let serverArg = portOverride.map { "127.0.0.1:\($0)" } ?? hop.server.trimmingCharacters(in: .whitespaces)
        args += [serverArg,
                 "noauth", "nodefaultroute", "noipdefault", "refuse-eap", "refuse-pap", "nodetach"]
        try spawn(i, tool: .sstpc, args)
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("ppp") && !addrs.isEmpty }
        return (iface.name, iface.address)
    }

    private func startAWG(_ i: Int, _ hop: Profile, ip: String, portOverride: Int?) throws -> (String, String) {
        let conf = try AWGConfig.parse(hop.config)
        guard let address = conf.ipv4Address else { throw EngineError("в [Interface] нет IPv4 Address") }
        guard let awg = Tool.awg.locate() else { throw EngineError("не найден awg (\(Tool.awg.installHint))") }

        let nameFile = privateFile(i, "name")
        try? FileManager.default.removeItem(at: nameFile)
        try spawn(i, tool: .amneziawgGo, ["-f", "utun"], env: ["WG_TUN_NAME_FILE": nameFile.path])
        let iface = try waitUntil(hop: i, "интерфейс AmneziaWG", timeout: 15) {
            (try? String(contentsOf: nameFile, encoding: .utf8))?.trimmingCharacters(in: .whitespacesAndNewlines).nilIfEmpty
        }

        let setconf = privateFile(i, "awg.conf")
        try writeSecret(Data(conf.setconfText(endpointIP: ip, portOverride: portOverride).utf8), to: setconf)
        try check(Shell.run(awg, ["setconf", iface, setconf.path]), "awg setconf")
        let nestedCap = hop.argonPathMTU.map { max(576, $0 - 80) }
        let awgMTU = min(conf.mtu ?? 1376, nestedCap ?? (conf.mtu ?? 1376))
        try check(Shell.run("/sbin/ifconfig", [iface, "inet", address, address, "netmask", "255.255.255.255",
                                                "mtu", String(awgMTU), "up"]), "ifconfig")
        _ = try waitUntil(hop: i, "рукопожатие AmneziaWG", timeout: 30) { () -> Bool? in
            let out = Shell.run(awg, ["show", iface, "latest-handshakes"]).output
            let handshaken = out.split(separator: "\n").contains { line in
                (line.split(separator: "\t").last.flatMap { Int($0) } ?? 0) > 0
            }
            return handshaken ? true : nil
        }
        return (iface, address)
    }

    // MARK: - Процессы

    private func spawn(_ i: Int, tool: Tool, _ args: [String], stdin: String? = nil, env: [String: String] = [:]) throws {
        guard let path = tool.locate() else { throw EngineError("не найден \(tool.rawValue) (\(tool.installHint))") }
        let logURL = dir.hopLog(i)
        if !FileManager.default.fileExists(atPath: logURL.path) {
            FileManager.default.createFile(atPath: logURL.path, contents: nil, attributes: [.posixPermissions: 0o644])
        }
        let logHandle = try FileHandle(forWritingTo: logURL)
        logHandle.seekToEndOfFile()
        logHandle.write(Data("\n=== \(tool.rawValue) \(Date()) ===\n".utf8))

        let p = Process()
        p.executableURL = URL(fileURLWithPath: path)
        p.arguments = args
        p.currentDirectoryURL = dir.url
        p.standardOutput = logHandle
        p.standardError = logHandle
        p.environment = ProcessInfo.processInfo.environment.merging(env) { $1 }
        let input = Pipe()
        p.standardInput = stdin == nil ? FileHandle.nullDevice : input
        try p.run()
        if let stdin {
            input.fileHandleForWriting.write(Data(stdin.utf8))
            try? input.fileHandleForWriting.close()
        }
        while processes.count <= i { processes.append([]) }
        processes[i].append(p)
        recordChildren()
        log("узел \(i + 1): запущен \(tool.rawValue) (pid \(p.processIdentifier))")
    }

    private func terminate(_ p: Process) {
        guard p.isRunning else { return }
        kill(p.processIdentifier, SIGTERM)
        let deadline = Date().addingTimeInterval(5)
        while p.isRunning, Date() < deadline { Thread.sleep(forTimeInterval: 0.1) }
        if p.isRunning { kill(p.processIdentifier, SIGKILL) }
    }

    /// PID дочерних процессов — чтобы следующий запуск добил их, если движок убили через SIGKILL.
    private func recordChildren() {
        let lines = processes.flatMap { $0 }.map { "\($0.processIdentifier) \($0.executableURL?.lastPathComponent ?? "")" }
        try? Data((lines.joined(separator: "\n") + "\n").utf8).write(to: dir.url.appendingPathComponent("children.pid"))
    }

    // MARK: - Ожидание

    private func waitForInterface(hop i: Int, match: (String, [String]) -> Bool) throws -> (name: String, address: String) {
        try waitUntil(hop: i, "интерфейс", timeout: hopTimeout) { () -> (name: String, address: String)? in
            for (name, addrs) in Net.interfaces().sorted(by: { $0.key < $1.key }) where match(name, addrs) {
                return (name, addrs.first ?? "")
            }
            return nil
        }
    }

    private func waitForPort(_ port: Int, hop i: Int) throws {
        _ = try waitUntil(hop: i, "локальный порт \(port)", timeout: 10) { () -> Bool? in
            let fd = socket(AF_INET, SOCK_STREAM, 0)
            defer { close(fd) }
            var addr = sockaddr_in()
            addr.sin_family = sa_family_t(AF_INET)
            addr.sin_port = in_port_t(UInt16(port).bigEndian)
            addr.sin_addr.s_addr = inet_addr("127.0.0.1")
            let rc = withUnsafePointer(to: &addr) {
                $0.withMemoryRebound(to: sockaddr.self, capacity: 1) { Darwin.connect(fd, $0, socklen_t(MemoryLayout<sockaddr_in>.size)) }
            }
            return rc == 0 ? true : nil
        }
    }

    /// Ждёт, пока probe вернёт значение; падает, если процесс узла умер или пришла команда остановки.
    private func waitUntil<T>(hop i: Int, _ what: String, timeout: TimeInterval, probe: () -> T?) throws -> T {
        let deadline = Date().addingTimeInterval(timeout)
        while Date() < deadline {
            try checkStop()
            if let value = probe() { return value }
            if let dead = processes[safe: i]?.first(where: { !$0.isRunning }) {
                throw EngineError("\(dead.executableURL?.lastPathComponent ?? "процесс") завершился (код \(dead.terminationStatus)): \(logTail(i))")
            }
            Thread.sleep(forTimeInterval: 0.3)
        }
        throw EngineError("не дождались: \(what) за \(Int(timeout)) с. \(logTail(i))")
    }

    private func checkStop() throws {
        if Stop.requested || FileManager.default.fileExists(atPath: dir.stopRequest.path) {
            Stop.requested = true
            throw EngineError("остановлено пользователем")
        }
    }

    // MARK: - Маршруты

    private func addHostRoute(_ ip: String, args: [String]) throws {
        Shell.run("/sbin/route", ["-n", "delete", "-host", ip])
        try route(["add", "-host", ip] + args)
        hostRoutes.append(ip)
    }

    private func route(_ args: [String]) throws {
        let r = Shell.run("/sbin/route", ["-n"] + args)
        log("route \(args.joined(separator: " ")) → \(r.output.trimmingCharacters(in: .whitespacesAndNewlines))")
        try check(r, "route \(args.joined(separator: " "))")
    }

    // MARK: - Служебное

    private func check(_ r: (status: Int32, output: String), _ what: String) throws {
        if r.status != 0 { throw EngineError("\(what): \(r.output.trimmingCharacters(in: .whitespacesAndNewlines))") }
    }

    private func privateFile(_ i: Int, _ name: String) -> URL {
        privateDir.appendingPathComponent("hop\(i + 1)-\(name)")
    }

    private func writeSecret(_ data: Data, to url: URL, mode: Int = 0o600) throws {
        try? FileManager.default.removeItem(at: url)
        guard FileManager.default.createFile(atPath: url.path, contents: data, attributes: [.posixPermissions: mode]) else {
            throw EngineError("не удалось записать \(url.lastPathComponent)")
        }
    }

    private func logTail(_ i: Int) -> String {
        guard let text = try? String(contentsOf: dir.hopLog(i), encoding: .utf8) else { return "" }
        let lines = text.split(separator: "\n").suffix(3).joined(separator: " | ")
        return lines.isEmpty ? "" : "Лог: \(lines)"
    }

    private func setHop(_ i: Int, _ change: (inout HopStatus) -> Void) {
        change(&status.hops[i])
        writeStatus()
    }

    private func writeStatus() {
        status.updatedAt = Date()
        let enc = JSONEncoder()
        enc.dateEncodingStrategy = .iso8601
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        guard let data = try? enc.encode(status) else { return }
        try? data.write(to: dir.status, options: .atomic)
        chmod(dir.status.path, 0o644)
    }
}

func log(_ message: String) {
    let ts = ISO8601DateFormatter().string(from: Date())
    print("[\(ts)] \(message)")
    fflush(stdout)
}

/// Флаг остановки по SIGTERM/SIGINT.
enum Stop {
    nonisolated(unsafe) static var requested = false
}

extension Array {
    subscript(safe i: Int) -> Element? { indices.contains(i) ? self[i] : nil }
}

extension String {
    var nilIfEmpty: String? { isEmpty ? nil : self }
}
