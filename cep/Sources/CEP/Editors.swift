import CEPCore
import SwiftUI
import UniformTypeIdentifiers

struct ProfileEditor: View {
    @EnvironmentObject var model: AppModel
    let profileID: UUID
    @State private var importing = false

    private var binding: Binding<Profile> {
        Binding(
            get: { model.profile(profileID) ?? Profile(name: "", proto: .vless) },
            set: { new in
                if let i = model.profiles.firstIndex(where: { $0.id == profileID }) { model.profiles[i] = new }
            }
        )
    }

    var body: some View {
        let p = binding
        Form {
            Section {
                TextField("Название", text: p.name)
                LabeledContent("Протокол", value: p.wrappedValue.proto.title)
            }
            switch p.wrappedValue.proto {
            case .vless, .hysteria2, .hysteria:
                Section("Ссылка") {
                    TextEditor(text: p.uri)
                        .font(.system(.body, design: .monospaced))
                        .frame(minHeight: 80)
                }
            case .openvpn:
                configSection(p, title: "Конфигурация .ovpn")
                credentials(p, optional: true)
            case .amneziawg:
                configSection(p, title: "Конфигурация AmneziaWG (.conf)")
            case .openconnect:
                Section("Сервер") {
                    Picker("Тип сервера", selection: p.openconnectFlavor) {
                        ForEach(OpenConnectFlavor.allCases) { Text($0.title).tag($0) }
                    }
                    TextField("Адрес", text: p.server, prompt: Text("vpn.example.com или https://vpn.example.com/group"))
                    TextField("Сертификат сервера", text: p.serverCert, prompt: Text("pin-sha256:… (нужен для самоподписанного)"))
                    TextField("Группа (authgroup)", text: p.authGroup)
                }
                credentials(p, optional: false)
            case .argon4:
                Section("Сервер Argon4") {
                    TextField("Адрес", text: p.server, prompt: Text("vpn.example.com:51820"))
                    SecureField("Мастер-пароль", text: p.password)
                    TextField("Client ID (необязательно)", text: p.clientID)
                    SecureField("Client Secret (необязательно)", text: p.clientSecret)
                }
            case .sstp:
                Section("Сервер") {
                    TextField("Адрес", text: p.server, prompt: Text("vpn.example.com[:443]"))
                    Toggle("Не проверять сертификат сервера", isOn: p.ignoreCertErrors)
                }
                credentials(p, optional: false)
            }
            if p.wrappedValue.proto != .argon4 {
                Section {
                    Toggle("Шифровать подключение через Argon4", isOn: p.useArgon)
                    if p.wrappedValue.useArgon {
                        TextField("Сервер Argon4", text: p.argonServer, prompt: Text("vpn.example.com:51820"))
                        SecureField("Мастер-пароль Argon4", text: p.argonPassword)
                        TextField("Client ID (необязательно)", text: p.clientID)
                        SecureField("Client Secret (необязательно)", text: p.clientSecret)
                    }
                } header: {
                    Text("Argon4-надстройка")
                } footer: {
                    Text("Сначала поднимается отдельный Argon4-туннель, затем сервер этого профиля подключается через него. Для пустого Client ID используется идентификатор профиля.")
                }
            }
            Section {
                TextField("Сети", text: p.routes, prompt: Text("192.168.1.119, 10.0.0.0/8"))
                if let error = Result(catching: { try RouteList.parse(p.wrappedValue.routes) }).failureMessage {
                    Label(error, systemImage: "exclamationmark.triangle").foregroundStyle(.orange)
                }
            } header: {
                Text("Сети через этот узел")
            } footer: {
                Text("Эти адреса пойдут через узел, а не дальше по цепочке и не в локальную сеть. Сети, которые присылает сервер OpenConnect или OpenVPN, добавляются сами. Адрес из своей локальной подсети указывайте отдельно (/32): всю подсеть забрать у локальной сети нельзя.")
            }
            Section("Проверка") {
                switch Result(catching: { try Endpoint.of(p.wrappedValue) }) {
                case .success(let ep):
                    Label("Сервер: \(ep.host)\(ep.port.map { ":\($0)" } ?? "")", systemImage: "checkmark.circle")
                        .foregroundStyle(.green)
                case .failure(let error):
                    Label(error.localizedDescription, systemImage: "exclamationmark.triangle")
                        .foregroundStyle(.orange)
                }
            }
        }
        .formStyle(.grouped)
        .navigationTitle(p.wrappedValue.name)
        .toolbar {
            Button(role: .destructive) { model.deleteProfile(profileID) } label: {
                Label("Удалить", systemImage: "trash")
            }
        }
        .fileImporter(isPresented: $importing, allowedContentTypes: [.data, .text]) { result in
            guard case .success(let url) = result else { return }
            let scoped = url.startAccessingSecurityScopedResource()
            defer { if scoped { url.stopAccessingSecurityScopedResource() } }
            if let text = try? String(contentsOf: url, encoding: .utf8) {
                binding.wrappedValue.config = text
            }
        }
    }

    private func configSection(_ p: Binding<Profile>, title: String) -> some View {
        Section {
            TextEditor(text: p.config)
                .font(.system(.callout, design: .monospaced))
                .frame(minHeight: 220)
        } header: {
            HStack {
                Text(title)
                Spacer()
                Button("Загрузить из файла…") { importing = true }
            }
        }
    }

    private func credentials(_ p: Binding<Profile>, optional: Bool) -> some View {
        Section(optional ? "Логин и пароль (если сервер требует)" : "Логин и пароль") {
            TextField("Логин", text: p.username)
            SecureField("Пароль", text: p.password)
        }
    }
}

struct ChainEditor: View {
    @EnvironmentObject var model: AppModel
    let chainID: UUID

    private var binding: Binding<Chain> {
        Binding(
            get: { model.chains.first { $0.id == chainID } ?? Chain(name: "") },
            set: { new in
                if let i = model.chains.firstIndex(where: { $0.id == chainID }) { model.chains[i] = new }
            }
        )
    }

    var body: some View {
        let c = binding
        let plan = model.plan(for: c.wrappedValue)
        let problems = ChainValidation.problems(plan)
        let missing = model.missingTools(for: c.wrappedValue)
        Form {
            Section {
                TextField("Название", text: c.name)
            }
            Section {
                if c.wrappedValue.hops.isEmpty {
                    Text("Добавьте узлы: трафик пойдёт через них по порядку, сверху вниз.").foregroundStyle(.secondary)
                }
                ForEach(Array(c.wrappedValue.hops.enumerated()), id: \.offset) { index, id in
                    HStack {
                        Text("\(index + 1).").monospacedDigit().foregroundStyle(.secondary)
                        if let p = model.profile(id) {
                            Text(p.name)
                            Text(p.proto.title).font(.caption).foregroundStyle(.secondary)
                        }
                        Spacer()
                        Button { move(index, by: -1) } label: { Image(systemName: "arrow.up") }
                            .disabled(index == 0)
                        Button { move(index, by: 1) } label: { Image(systemName: "arrow.down") }
                            .disabled(index == c.wrappedValue.hops.count - 1)
                        Button { binding.wrappedValue.hops.remove(at: index) } label: { Image(systemName: "minus.circle") }
                    }
                    .buttonStyle(.borderless)
                }
                Menu("Добавить узел") {
                    ForEach(model.profiles) { p in
                        Button("\(p.name) — \(p.proto.title)") { binding.wrappedValue.hops.append(p.id) }
                    }
                    if !model.profiles.isEmpty { Divider() }
                    Button("Новый профиль из ссылки в буфере обмена") {
                        let text = NSPasteboard.general.string(forType: .string) ?? ""
                        if let p = model.importLink(text, select: false) { binding.wrappedValue.hops.append(p.id) }
                    }
                    Menu("Новый профиль") {
                        ForEach(VPNProtocol.allCases) { proto in
                            Button(proto.title) {
                                // Узел сразу в цепочке, а редактор профиля открывается для заполнения.
                                let p = model.addProfile(proto)
                                if let i = model.chains.firstIndex(where: { $0.id == chainID }) { model.chains[i].hops.append(p.id) }
                            }
                        }
                    }
                }
            } header: {
                Text("Узлы: Mac → 1 → 2 → … → интернет")
            } footer: {
                Text("Сервер каждого следующего узла доступен только через предыдущий.")
            }
            Section("Сеть") {
                TextField("DNS через цепочку", text: Binding(
                    get: { c.wrappedValue.dns.joined(separator: ", ") },
                    set: { binding.wrappedValue.dns = $0.split(whereSeparator: { $0 == "," || $0 == " " }).map(String.init) }
                ), prompt: Text("1.1.1.1, 8.8.8.8 — пусто, чтобы не менять"))
                Toggle("Блокировать IPv6 (защита от утечки мимо цепочки)", isOn: c.blockIPv6)
            }
            if !problems.isEmpty || !missing.isEmpty {
                Section("Нужно исправить") {
                    ForEach(problems, id: \.self) { Label($0, systemImage: "exclamationmark.triangle").foregroundStyle(.orange) }
                    ForEach(missing, id: \.self) { tool in
                        Label("Не установлен \(tool.rawValue): \(tool.installHint)", systemImage: "shippingbox")
                            .foregroundStyle(.orange)
                    }
                }
            }
        }
        .formStyle(.grouped)
        .navigationTitle(c.wrappedValue.name)
        .toolbar {
            Button { model.connect(c.wrappedValue) } label: {
                Label("Подключить", systemImage: "play.fill")
            }
            .disabled(!problems.isEmpty || !missing.isEmpty || model.isActive || model.launching)
            Button(role: .destructive) { model.deleteChain(chainID) } label: {
                Label("Удалить", systemImage: "trash")
            }
        }
    }

    private func move(_ index: Int, by delta: Int) {
        binding.wrappedValue.hops.swapAt(index, index + delta)
    }
}

extension Result where Failure == Error {
    var failureMessage: String? {
        if case .failure(let error) = self { return error.localizedDescription }
        return nil
    }
}
