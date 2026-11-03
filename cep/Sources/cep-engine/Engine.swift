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
    private var v6Blackhole = false
    private var dns: DNSOverride?
    private var splitDNS: [SplitDNS] = []
    private var argonRoutes: [Int: [String]] = [:]
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

    func run() -> Int32 {
        writeStatus()
        try? FileManager.default.removeItem(at: privateDir)
        do {
            try FileManager.default.createDirectory(at: privateDir, withIntermediateDirectories: true,
                                                    attributes: [.posixPermissions: 0o700])
        } catch {
            status.error = "не удалось создать \(privateDir.path): \(error.localizedDescription)"
            status.phase = .failed
            writeStatus()
            return 1
        }
        do {
            try connect()
            status.phase = .connected
            writeStatus()
            log("цепочка поднята: \(status.hops.compactMap(\.interface).joined(separator: " → "))")
            try supervise()
            teardown(phase: .stopped)
            return 0
        } catch {
            log("ошибка: \(error.localizedDescription)")
            status.error = error.localizedDescription
            teardown(phase: Stop.requested ? .stopped : .failed)
            return 1
        }
    }

    private func connect() throws {
        let problems = ChainValidation.problems(plan)
        guard problems.isEmpty else { throw EngineError(problems.joined(separator: "; ")) }
        guard let original = Net.defaultRoute() else { throw EngineError("нет маршрута по умолчанию — нет сети?") }
        log("исходный маршрут: \(original.gateway ?? "-") через \(original.interface)")

        // Все адреса разрешаем заранее системным DNS, пока цепочка не изменила маршруты.
        var serverIPs: [[String]] = []
        var argonServerIPs: [[String]?] = []
        for hop in plan.hops {
            let host = try Endpoint.of(hop).host
            let ips = try Net.resolveAllIPv4(host)
            log("\(hop.name): \(host) → \(ips.joined(separator: ", "))")
            serverIPs.append(ips)
            if hop.useArgon {
                let argonHost = try Endpoint.argon4(hop.argonServer).host
                let argonIPs = try Net.resolveAllIPv4(argonHost)
                log("\(hop.name), Argon4: \(argonHost) → \(argonIPs.joined(separator: ", "))")
                argonServerIPs.append(argonIPs)
            } else {
                argonServerIPs.append(nil)
            }
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

        status.phase = .connecting
        var previousInterface: String?
        for (i, hop) in plan.hops.enumerated() {
            try checkStop()
            setHop(i) { $0.state = .connecting; $0.serverIP = serverIPs[i][0] }
            do {
                var nextInterface = previousInterface
                if hop.useArgon {
                    try addRoutes(argonServerIPs[i] ?? [], nextInterface)
                    let (argonInterface, argonAddress) = try startArgon(i, hop, ip: (argonServerIPs[i] ?? [])[0], underlay: true)
                    log("узел \(i + 1) «\(hop.name)»: Argon4-надстройка поднята на \(argonInterface) (\(argonAddress))")
                    nextInterface = argonInterface
                }
                try addRoutes(serverIPs[i], nextInterface)
                let (iface, address) = try startHop(i, hop, ip: serverIPs[i][0])
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
        for net in ["0.0.0.0/1", "128.0.0.0/1"] {
            try route(["add", "-net", net, "-interface", last])
            netRoutes.append(net)
        }
        if plan.blockIPv6 {
            v6Blackhole = Shell.run("/sbin/route", ["-n", "add", "-inet6", "-net", "2000::/3", "::1", "-blackhole"]).status == 0
        }
        if !plan.dns.isEmpty {
            dns = DNSOverride(servers: plan.dns)
            dns?.apply()
            log("DNS: \(plan.dns.joined(separator: ", "))")
        }
    }

    private func supervise() throws {
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
        }
    }

    private func teardown(phase: EnginePhase) {
        status.phase = .stopping
        writeStatus()
        dns?.restore()
        splitDNS.forEach { $0.remove() }
        for net in netRoutes {
            Shell.run("/sbin/route", ["-n", "delete", "-net", net])
        }
        if v6Blackhole {
            Shell.run("/sbin/route", ["-n", "delete", "-inet6", "-net", "2000::/3"])
        }
        for (i, procs) in processes.enumerated().reversed() {
            for p in procs.reversed() { terminate(p) }
            setHop(i) { if $0.state == .up || $0.state == .connecting { $0.state = .stopped } }
        }
        for ip in hostRoutes.reversed() {
            Shell.run("/sbin/route", ["-n", "delete", "-host", ip])
        }
        try? FileManager.default.removeItem(at: privateDir)
        try? FileManager.default.removeItem(at: dir.stopRequest)
        try? FileManager.default.removeItem(at: dir.url.appendingPathComponent("children.pid"))
        status.phase = phase
        writeStatus()
        log("остановлено")
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

    private func startHop(_ i: Int, _ hop: Profile, ip: String) throws -> (String, String) {
        let before = Set(Net.interfaces().keys)
        switch hop.proto {
        case .vless, .hysteria2, .hysteria:
            return try startProxy(i, hop, ip: ip)
        case .openvpn:
            return try startOpenVPN(i, hop, ip: ip, before: before)
        case .openconnect:
            return try startOpenConnect(i, hop, ip: ip, before: before)
        case .argon4:
            return try startArgon(i, hop, ip: ip, underlay: false)
        case .sstp:
            return try startSSTP(i, hop, ip: ip, before: before)
        case .amneziawg:
            return try startAWG(i, hop, ip: ip)
        }
    }

    /// Запускает настоящий Argon4 UDP/TUN-клиент. В режиме underlay его интерфейс
    /// используется только для host-маршрута до сервера основного профиля.
    private func startArgon(_ i: Int, _ hop: Profile, ip: String, underlay: Bool) throws -> (String, String) {
        let readyFile = privateFile(i, "argon4-ready.json")
        try? FileManager.default.removeItem(at: readyFile)
        let configuration = try Argon4Configuration(profile: hop, resolvedIP: ip, readyFile: readyFile.path, underlay: underlay)
        let configFile = privateFile(i, "argon4.json")
        let encoder = JSONEncoder()
        encoder.outputFormatting = [.sortedKeys]
        try writeSecret(try encoder.encode(configuration), to: configFile)
        try spawn(i, tool: .cepArgon4, ["--config", configFile.path])
        let data = try waitUntil(hop: i, "Argon4 handshake и TUN", timeout: hopTimeout) {
            try? Data(contentsOf: readyFile)
        }
        let ready = try JSONDecoder().decode(Argon4Ready.self, from: data)
        guard !ready.interface.isEmpty, !ready.address.isEmpty else {
            throw EngineError("Argon4: помощник вернул пустой интерфейс или адрес")
        }
        argonRoutes[i] = ready.routes
        return (ready.interface, ready.address)
    }

    private func startProxy(_ i: Int, _ hop: Profile, ip: String) throws -> (String, String) {
        let link = try ProxyLink.parse(hop.uri)
        let outbound: [String: Any]
        if link.scheme == "vless" {
            let port = 20800 + i
            let xray = try XrayConfig.make(link: link, server: ip, socksPort: port)
            let cfg = privateFile(i, "xray.json")
            try writeSecret(JSON.data(xray), to: cfg)
            try spawn(i, tool: .xray, ["run", "-c", cfg.path])
            try waitForPort(port, hop: i)
            outbound = SingBoxConfig.socksOutbound(port: port)
        } else {
            outbound = try SingBoxConfig.outbound(for: link, server: ip)
        }
        let address = "172.19.\(i).1"
        let cfg = privateFile(i, "config")
        try writeSecret(JSON.data(SingBoxConfig.make(tunAddress: "\(address)/30", outbound: outbound)), to: cfg)
        try spawn(i, tool: .singBox, ["run", "-c", cfg.path, "-D", privateDir.path, "--disable-color"])
        let iface = try waitForInterface(hop: i) { _, addrs in addrs.contains(address) }
        return (iface.name, address)
    }

    private func startOpenVPN(_ i: Int, _ hop: Profile, ip: String, before: Set<String>) throws -> (String, String) {
        let cfg = privateFile(i, "config")
        try writeSecret(Data(OpenVPNConfig.pinRemote(hop.config, ip: ip).utf8), to: cfg)
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
        if !hop.username.isEmpty {
            let auth = privateFile(i, "auth")
            try writeSecret(Data("\(hop.username)\n\(hop.password)\n".utf8), to: auth)
            args += ["--auth-user-pass", auth.path, "--auth-nocache"]
        }
        try spawn(i, tool: .openvpn, args)
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("utun") && !addrs.isEmpty }
        return (iface.name, iface.address)
    }

    private func startOpenConnect(_ i: Int, _ hop: Profile, ip: String, before: Set<String>) throws -> (String, String) {
        // Вместо vpnc-script: только поднимаем интерфейс, маршрутами и DNS управляет движок.
        let script = privateFile(i, "script")
        try writeSecret(Data("""
        #!/bin/sh
        case "$reason" in
          connect)
            /sbin/ifconfig "$TUNDEV" inet "$INTERNAL_IP4_ADDRESS" "$INTERNAL_IP4_ADDRESS" netmask 255.255.255.255 mtu "${INTERNAL_IP4_MTU:-1300}" up
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
        if endpoint.host != ip { args.append("--resolve=\(endpoint.host):\(ip)") }
        if !hop.username.isEmpty { args.append("--user=\(hop.username)") }
        if !hop.serverCert.isEmpty { args.append("--servercert=\(hop.serverCert)") }
        if let ca = Tool.bundledCAFile { args.append("--cafile=\(ca)") }
        if !hop.authGroup.isEmpty { args.append("--authgroup=\(hop.authGroup)") }
        args.append(hop.server.trimmingCharacters(in: .whitespaces))
        try spawn(i, tool: .openconnect, args, stdin: hop.password + "\n", env: ["CEP_INNER_FILE": privateFile(i, "inner").path])
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("utun") && !addrs.isEmpty }
        return (iface.name, iface.address)
    }

    private func startSSTP(_ i: Int, _ hop: Profile, ip: String, before: Set<String>) throws -> (String, String) {
        var args = ["--log-stderr", "--log-level", "3", "--user", hop.username, "--password", hop.password]
        if hop.ignoreCertErrors { args.append("--cert-warn") }
        if let ca = Tool.bundledCAFile { args += ["--ca-cert", ca] }
        args += [hop.server.trimmingCharacters(in: .whitespaces),
                 "noauth", "nodefaultroute", "noipdefault", "refuse-eap", "refuse-pap", "nodetach"]
        try spawn(i, tool: .sstpc, args)
        let iface = try waitForInterface(hop: i) { name, addrs in !before.contains(name) && name.hasPrefix("ppp") && !addrs.isEmpty }
        return (iface.name, iface.address)
    }

    private func startAWG(_ i: Int, _ hop: Profile, ip: String) throws -> (String, String) {
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
        try writeSecret(Data(conf.setconfText(endpointIP: ip).utf8), to: setconf)
        try check(Shell.run(awg, ["setconf", iface, setconf.path]), "awg setconf")
        try check(Shell.run("/sbin/ifconfig", [iface, "inet", address, address, "netmask", "255.255.255.255",
                                                "mtu", String(conf.mtu ?? 1376), "up"]), "ifconfig")
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
