import AppKit
import CEPCore
import Foundation

enum SidebarItem: Hashable {
    case status
    case tools
    case chain(UUID)
    case profile(UUID)
}

@MainActor
final class AppModel: ObservableObject {
    @Published var profiles: [Profile] = [] { didSet { save() } }
    @Published var chains: [Chain] = [] { didSet { save() } }
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
    }

    init() {
        let base = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        supportDir = base.appendingPathComponent("CEP", isDirectory: true)
        run = RunDirectory(supportDir.appendingPathComponent("run", isDirectory: true))
        try? FileManager.default.createDirectory(at: run.url, withIntermediateDirectories: true,
                                                 attributes: [.posixPermissions: 0o700])
        load()
        refreshStatus()
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
    }

    private struct StoredData: Decodable {
        var profiles: [Lossy<Profile>]
        var chains: [Chain]
    }

    private struct Lossy<T: Decodable>: Decodable {
        let value: T?
        init(from decoder: Decoder) throws { value = try? T(from: decoder) }
    }

    private func save() {
        guard loaded else { return }
        let enc = JSONEncoder()
        enc.outputFormatting = [.prettyPrinted, .sortedKeys]
        guard let data = try? enc.encode(Store(profiles: profiles, chains: chains)) else { return }
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
            let data = try enc.encode(Store(profiles: profiles, chains: chains))
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
            alert = "Импортировано: профилей \(imported.count), цепочек \(store.chains.count)"
        } catch {
            alert = "Не удалось прочитать файл: \(error.localizedDescription)"
        }
    }

    func deleteProfile(_ id: UUID) {
        profiles.removeAll { $0.id == id }
        for i in chains.indices { chains[i].hops.removeAll { $0 == id } }
        if selection == .profile(id) { selection = .status }
    }

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
            dead.error = dead.error ?? "движок завершился аварийно — см. журнал"
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
