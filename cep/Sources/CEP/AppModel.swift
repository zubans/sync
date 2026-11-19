import AppKit
import CEPCore
import Foundation
import IOKit

enum SidebarItem: Hashable {
    case status
    case tools
    case chain(UUID)
    case profile(UUID)
    case subscription(UUID)
}

@MainActor
final class AppModel: ObservableObject {
    @Published var profiles: [Profile] = [] { didSet { save() } }
    @Published var chains: [Chain] = [] { didSet { save() } }
    @Published var subscriptions: [Subscription] = [] { didSet { save() } }
    /// Подписки, которые сейчас обновляются.
    @Published var refreshing: Set<UUID> = []
    @Published var status: EngineStatus?
    @Published var selection: SidebarItem? = .status
    @Published var alert: String?
    @Published var launching = false

    let supportDir: URL
    let run: RunDirectory
    private var loaded = false
    private var timer: Timer?

    private var storeURL: URL { supportDir.appendingPathComponent("profiles.json") }

    private struct Store: Codable {
        var profiles: [Profile]
        var chains: [Chain]
        var subscriptions: [Subscription]
    }

    init() {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        supportDir = base.appendingPathComponent("CEP", isDirectory: true)
        run = RunDirectory(supportDir.appendingPathComponent("run", isDirectory: true))
        try? FileManager.default.createDirectory(at: run.url, withIntermediateDirectories: true,
                                                 attributes: [.posixPermissions: 0o700])
        load()
        refreshStatus()
        refreshStaleSubscriptions()
        timer = Timer.scheduledTimer(withTimeInterval: 1, repeats: true) { [weak self] _ in
            Task { @MainActor in self?.refreshStatus() }
        }
    }

    // MARK: - Хранилище

    private func load() {
        defer { loaded = true }
        guard let data = try? Data(contentsOf: storeURL),
              let store = try? JSONDecoder().decode(StoredData.self, from: data) else { return }
        // Профили удалённых протоколов (например, PPTP) пропускаем, а не теряем весь файл.
        profiles = store.profiles.compactMap(\.value)
        let ids = Set(profiles.map(\.id))
        chains = store.chains.map { chain in
            var c = chain
            c.hops.removeAll { !ids.contains($0) }
            return c
        }
        subscriptions = store.subscriptions ?? []
    }

    private struct StoredData: Decodable {
        var profiles: [Lossy<Profile>]
        var chains: [Chain]
        var subscriptions: [Subscription]?
    }

    private struct Lossy<T: Decodable>: Decodable {
        let value: T?
        init(from decoder: Decoder) throws { value = try? T(from: decoder) }
    }

    private func save() {
        guard loaded else { return }
        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        guard let data = try? enc.encode(Store(profiles: profiles, chains: chains, subscriptions: subscriptions)) else { return }
        // В файле пароли: читать может только владелец.
        FileManager.default.createFile(atPath: storeURL.path, contents: data, attributes: [.posixPermissions: 0o600])
    }

    // MARK: - Профили и цепочки

    func profile(_ id: UUID) -> Profile? { profiles.first { $0.id == id } }

    @discardableResult
    func addProfile(_ proto: VPNProtocol) -> Profile {
        let p = Profile(name: "\(proto.title) \(profiles.filter { $0.proto == proto }.count + 1)", proto: proto)
        profiles.append(p)
        selection = .profile(p.id)
        return p
    }

    /// Профиль из ссылки vless:// hy2:// hysteria2:// hysteria://
    @discardableResult
    func importLink(_ text: String, select: Bool = true) -> Profile? {
        let link = text.trimmingCharacters(in: .whitespacesAndNewlines)
        let scheme = link.split(separator: ":").first.map { $0.lowercased() } ?? ""
        let proto: VPNProtocol
        switch scheme {
        case "vless": proto = .vless
        case "hy2", "hysteria2": proto = .hysteria2
        case "hysteria": proto = .hysteria
        default:
            alert = "Это не ссылка vless://, hysteria2://, hy2:// или hysteria://"
            return nil
        }
        do {
            let parsed = try ProxyLink.parse(link)
            var p = Profile(name: parsed.label.isEmpty ? "\(proto.title) \(parsed.host)" : parsed.label, proto: proto)
            p.uri = link
            profiles.append(p)
            if select { selection = .profile(p.id) }
            return p
        } catch {
            alert = error.localizedDescription
            return nil
        }
    }

    /// Перенос на другой Mac: файл с профилями и цепочками. В нём пароли — права 0600.
    func exportStore() {
        let panel = NSSavePanel()
        panel.nameFieldStringValue = "cep-profiles.json"
        panel.message = "В файле будут пароли и ключи профилей — храните и передавайте его как секрет"
        guard panel.runModal() == .OK, let url = panel.url else { return }
        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        do {
            let data = try enc.encode(Store(profiles: profiles, chains: chains, subscriptions: subscriptions))
            try? FileManager.default.removeItem(at: url)
            guard FileManager.default.createFile(atPath: url.path, contents: data, attributes: [.posixPermissions: 0o600]) else {
                throw CocoaError(.fileWriteNoPermission)
            }
        } catch {
            alert = "Не удалось сохранить: \(error.localizedDescription)"
        }
    }

    /// Профили и цепочки из файла экспорта: с тем же id заменяются, новые добавляются.
    func importStore() {
        let panel = NSOpenPanel()
        panel.allowedContentTypes = [.json]
        guard panel.runModal() == .OK, let url = panel.url else { return }
        do {
            let store = try JSONDecoder().decode(StoredData.self, from: Data(contentsOf: url))
            let imported = store.profiles.compactMap(\.value)
            for p in imported {
                if let i = profiles.firstIndex(where: { $0.id == p.id }) { profiles[i] = p } else { profiles.append(p) }
            }
            for c in store.chains {
                if let i = chains.firstIndex(where: { $0.id == c.id }) { chains[i] = c } else { chains.append(c) }
            }
            for s in store.subscriptions ?? [] {
                if let i = subscriptions.firstIndex(where: { $0.id == s.id }) { subscriptions[i] = s } else { subscriptions.append(s) }
            }
            alert = "Импортировано: профилей \(imported.count), цепочек \(store.chains.count), подписок \(store.subscriptions?.count ?? 0)"
        } catch {
            alert = "Не удалось прочитать файл: \(error.localizedDescription)"
        }
    }

    func deleteProfile(_ id: UUID) {
        profiles.removeAll { $0.id == id }
        for i in chains.indices { chains[i].hops.removeAll { $0 == id } }
        if selection == .profile(id) { selection = .status }
    }

    // MARK: - Подписки

    func subscription(_ id: UUID) -> Subscription? { subscriptions.first { $0.id == id } }

    /// Новая подписка по адресу: сразу обновляется, имя берётся из ответа панели.
    func addSubscription(url: String) {
        let trimmed = url.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let u = URL(string: trimmed), u.scheme == "https" || u.scheme == "http", u.host != nil else {
            alert = "Это не адрес подписки: нужен https://…"
            return
        }
        let s = Subscription(name: u.host ?? "Подписка", url: trimmed)
        subscriptions.append(s)
        selection = .subscription(s.id)
        refreshSubscription(s.id)
    }

    func deleteSubscription(_ id: UUID) {
        // Профили остаются с последней ссылкой, но больше не обновляются.
        for i in profiles.indices where profiles[i].subscriptionID == id {
            profiles[i].subscriptionID = nil
        }
        subscriptions.removeAll { $0.id == id }
        if selection == .subscription(id) { selection = .status }
    }

    /// HWID этого Mac: sha256 от IOPlatformUUID — постоянный и не раскрывает сам идентификатор.
    static let deviceHWID: String = {
        let service = IOServiceGetMatchingService(kIOMainPortDefault, IOServiceMatching("IOPlatformExpertDevice"))
        defer { IOObjectRelease(service) }
        let uuid = IORegistryEntryCreateCFProperty(service, "IOPlatformUUID" as CFString, kCFAllocatorDefault, 0)?
            .takeRetainedValue() as? String
        return SubscriptionParser.hwid(seed: uuid ?? Host.current().localizedName ?? "mac")
    }()

    func hwid(for s: Subscription) -> String {
        let manual = s.hwid.trimmingCharacters(in: .whitespacesAndNewlines)
        return manual.isEmpty ? Self.deviceHWID : manual
    }

    /// Скачивает список серверов и обновляет ссылки в профилях, выбравших серверы этой подписки.
    func refreshSubscription(_ id: UUID) {
        guard let s = subscription(id), let url = URL(string: s.url), !refreshing.contains(id) else { return }
        refreshing.insert(id)
        var request = URLRequest(url: url, timeoutInterval: 45)
        request.setValue(SubscriptionParser.userAgent, forHTTPHeaderField: "User-Agent")
        request.setValue(hwid(for: s), forHTTPHeaderField: "x-hwid")
        request.setValue("macOS", forHTTPHeaderField: "x-device-os")
        request.setValue(ProcessInfo.processInfo.operatingSystemVersionString, forHTTPHeaderField: "x-ver-os")
        request.setValue(Self.model, forHTTPHeaderField: "x-device-model")
        Task {
            defer { refreshing.remove(id) }
            do {
                let (data, response) = try await URLSession.shared.data(for: request)
                let http = response as? HTTPURLResponse
                guard let http, (200..<300).contains(http.statusCode) else {
                    throw ConfigError("сервер подписки ответил \(http?.statusCode ?? 0)")
                }
                let servers = SubscriptionParser.parse(data)
                guard !servers.isEmpty else { throw ConfigError("в ответе нет ни одного сервера") }
                // Remnawave без принятого HWID отдаёт заглушку «Приложение не поддерживается» с адресом 0.0.0.0
                if servers.allSatisfy({ (try? ProxyLink.parse($0.uri))?.host == "0.0.0.0" }) {
                    let limit = http.value(forHTTPHeaderField: "x-hwid-limit") == "true"
                    throw ConfigError(limit
                        ? "панель не приняла устройство: возможно, исчерпан лимит устройств подписки. Укажите HWID уже зарегистрированного устройства или освободите место у провайдера"
                        : "панель вернула заглушку вместо серверов: «\(servers[0].name)»")
                }
                applySubscription(id, servers: servers,
                                  title: SubscriptionParser.title(http.value(forHTTPHeaderField: "profile-title")),
                                  info: SubscriptionParser.userInfo(http.value(forHTTPHeaderField: "subscription-userinfo")))
            } catch {
                alert = "Подписка «\(s.name)»: \(error.localizedDescription)"
            }
        }
    }

    private func applySubscription(_ id: UUID, servers: [SubscriptionServer], title: String?, info: String?) {
        guard let i = subscriptions.firstIndex(where: { $0.id == id }) else { return }
        var s = subscriptions[i]
        // Имя из панели — только пока пользователь не переименовал подписку (оно ещё равно адресу хоста).
        if let title, !title.isEmpty, s.name == URL(string: s.url)?.host { s.name = title }
        s.servers = servers
        s.updatedAt = Date()
        s.info = info
        subscriptions[i] = s
        for j in profiles.indices where profiles[j].subscriptionID == id {
            guard let server = SubscriptionParser.match(servers, name: profiles[j].subscriptionServer, previousURI: profiles[j].uri),
                  let proto = server.proto else { continue }
            profiles[j].uri = server.uri
            profiles[j].proto = proto
            profiles[j].subscriptionServer = server.name
        }
    }

    /// Подписки старше суток обновляются при запуске приложения.
    private func refreshStaleSubscriptions() {
        for s in subscriptions where (s.updatedAt ?? .distantPast) < Date().addingTimeInterval(-86_400) {
            refreshSubscription(s.id)
        }
    }

    /// Профиль с выбранным сервером подписки.
    func addProfile(from server: SubscriptionServer, of subscriptionID: UUID) {
        guard let proto = server.proto else { return }
        var p = Profile(name: server.name, proto: proto)
        p.uri = server.uri
        p.subscriptionID = subscriptionID
        p.subscriptionServer = server.name
        profiles.append(p)
        selection = .profile(p.id)
    }

    /// Профиль переключается на другой сервер той же подписки; имя меняется, только если было именем сервера.
    func select(server: SubscriptionServer, for profileID: UUID) {
        guard let i = profiles.firstIndex(where: { $0.id == profileID }), let proto = server.proto else { return }
        if profiles[i].name == profiles[i].subscriptionServer { profiles[i].name = server.name }
        profiles[i].uri = server.uri
        profiles[i].proto = proto
        profiles[i].subscriptionServer = server.name
    }

    private static let model: String = {
        var size = 0
        sysctlbyname("hw.model", nil, &size, nil, 0)
        var buf = [CChar](repeating: 0, count: max(size, 1))
        sysctlbyname("hw.model", &buf, &size, nil, 0)
        return String(cString: buf)
    }()

    func addChain() {
        let c = Chain(name: "Цепочка \(chains.count + 1)")
        chains.append(c)
        selection = .chain(c.id)
    }

    func deleteChain(_ id: UUID) {
        chains.removeAll { $0.id == id }
        if selection == .chain(id) { selection = .status }
    }

    func plan(for chain: Chain) -> Plan { Plan(chain: chain, profiles: profiles) }

    func missingTools(for chain: Chain) -> [Tool] {
        let needed = plan(for: chain).hops.flatMap {
            $0.proto.requiredTools + ($0.useArgon ? [.cepArgon4] : [])
        }
        var seen = Set<Tool>()
        return needed.filter { seen.insert($0).inserted && $0.locate() == nil }
    }

    // MARK: - Движок

    var isActive: Bool {
        guard let status, status.isActive else { return false }
        return kill(status.pid, 0) == 0 || errno == EPERM
    }

    func refreshStatus() {
        let s = run.readStatus()
        if let s, s.isActive, kill(s.pid, 0) != 0, errno != EPERM {
            // Движок умер, не успев записать итог.
            var dead = s
            dead.phase = .failed
            dead.error = s.killSwitch == true
                ? "движок завершился аварийно во время переподключения, kill switch остался включён и интернет заблокирован. Подключите цепочку снова (движок снимет блокировку) или перезагрузите Mac"
                : dead.error ?? "движок завершился аварийно — см. журнал"
            status = dead
        } else {
            status = s
        }
    }

    func connect(_ chain: Chain) {
        guard !isActive else {
            alert = "Сначала отключите текущую цепочку"
            return
        }
        let plan = plan(for: chain)
        let problems = ChainValidation.problems(plan)
        guard problems.isEmpty else {
            alert = problems.joined(separator: "\n")
            return
        }
        let missing = missingTools(for: chain)
        guard missing.isEmpty else {
            alert = "Не установлены:\n" + missing.map { "• \($0.rawValue): \($0.installHint)" }.joined(separator: "\n")
            return
        }
        guard let engine = enginePath() else {
            alert = "Не найден cep-engine рядом с приложением"
            return
        }
        do {
            let data = try JSONEncoder().encode(plan)
            try? FileManager.default.removeItem(at: run.plan)
            FileManager.default.createFile(atPath: run.plan.path, contents: data, attributes: [.posixPermissions: 0o600])
            try? FileManager.default.removeItem(at: run.stopRequest)
            try? FileManager.default.removeItem(at: run.status)
        }
        catch {
            alert = error.localizedDescription
            return
        }

        let cmd = [engine, "up", "--run-dir", run.url.path].map(shellQuote).joined(separator: " ")
            + " >> \(shellQuote(run.engineLog.path)) 2>&1 < /dev/null &"
        let script = "do shell script \"\(appleScriptEscape(cmd))\" with administrator privileges"
        launching = true
        selection = .status
        Task.detached {
            let result = Shell.run("/usr/bin/osascript", ["-e", script])
            await MainActor.run {
                self.launching = false
                if result.status != 0 {
                    try? FileManager.default.removeItem(at: self.run.plan)
                    if !result.output.contains("-128") {
                        self.alert = "Не удалось запустить движок: \(result.output)"
                    }
                }
                self.refreshStatus()
            }
        }
    }

    func disconnect() {
        FileManager.default.createFile(atPath: run.stopRequest.path, contents: nil)
    }

    func logText(_ url: URL, lines: Int = 300) -> String {
        guard let text = try? String(contentsOf: url, encoding: .utf8) else { return "" }
        return text.split(separator: "\n", omittingEmptySubsequences: false).suffix(lines).joined(separator: "\n")
    }

    func clearLogs() {
        let fm = FileManager.default
        for name in (try? fm.contentsOfDirectory(atPath: run.url.path)) ?? [] where name.hasSuffix(".log") {
            try? fm.removeItem(at: run.url.appendingPathComponent(name))
        }
    }

    func probeMTU(for profileID: UUID) async -> MTUProbeResult {
        guard let profile = profile(profileID) else {
            return MTUProbeResult(ok: false, error: "Профиль не найден")
        }
        let chain = chains.first(where: { $0.hops.contains(profileID) })
        return await MTUProbe.probe(profile: profile, chain: chain, profiles: profiles)
    }

    private func enginePath() -> String? {
        let candidates = [
            Bundle.main.bundleURL.appendingPathComponent("Contents/MacOS/cep-engine"),
            Bundle.main.executableURL?.deletingLastPathComponent().appendingPathComponent("cep-engine"),
        ].compactMap { $0 }
        return candidates.first { FileManager.default.isExecutableFile(atPath: $0.path) }?.path
    }

    private func shellQuote(_ s: String) -> String {
        "'" + s.replacingOccurrences(of: "'", with: "'\\''") + "'"
    }

    private func appleScriptEscape(_ s: String) -> String {
        s.replacingOccurrences(of: "\\", with: "\\\\").replacingOccurrences(of: "\"", with: "\\\"")
    }
}
