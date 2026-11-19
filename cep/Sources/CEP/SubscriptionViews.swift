import CEPCore
import SwiftUI

struct AddSubscriptionSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var url = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Адрес подписки провайдера").font(.headline)
            TextField("https://…", text: $url)
                .font(.system(.body, design: .monospaced))
                .frame(width: 520)
            Text("CEP скачает список серверов; из него выбирается конкретный сервер для профиля. Панели с лимитом устройств получают HWID этого Mac — он займёт место в лимите, если не указать HWID уже зарегистрированного устройства в настройках подписки.")
                .font(.caption).foregroundStyle(.secondary).frame(width: 520, alignment: .leading)
            HStack {
                Button("Вставить из буфера") { url = NSPasteboard.general.string(forType: .string) ?? "" }
                Spacer()
                Button("Отмена") { dismiss() }.keyboardShortcut(.cancelAction)
                Button("Добавить") {
                    model.addSubscription(url: url)
                    dismiss()
                }
                .keyboardShortcut(.defaultAction)
                .disabled(url.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
        }
        .padding(20)
    }
}

struct SubscriptionEditor: View {
    @EnvironmentObject var model: AppModel
    let subscriptionID: UUID

    private var binding: Binding<Subscription> {
        Binding(
            get: { model.subscription(subscriptionID) ?? Subscription(name: "", url: "") },
            set: { new in
                if let i = model.subscriptions.firstIndex(where: { $0.id == subscriptionID }) { model.subscriptions[i] = new }
            }
        )
    }

    var body: some View {
        let s = binding
        let refreshing = model.refreshing.contains(subscriptionID)
        Form {
            Section {
                TextField("Название", text: s.name)
                TextField("Адрес", text: s.url).font(.system(.body, design: .monospaced))
                TextField("HWID", text: s.hwid, prompt: Text("этого Mac: \(AppModel.deviceHWID)"))
                    .font(.system(.body, design: .monospaced))
            } footer: {
                Text("HWID — идентификатор устройства для панелей с лимитом устройств. Пусто — свой HWID этого Mac (новое место в лимите). Чтобы не занимать место, впишите HWID уже зарегистрированного устройства.")
            }
            Section {
                if let info = s.wrappedValue.info { LabeledContent("Трафик", value: info) }
                LabeledContent("Обновлена", value: s.wrappedValue.updatedAt.map { $0.formatted(date: .abbreviated, time: .shortened) } ?? "ещё нет")
                Button {
                    model.refreshSubscription(subscriptionID)
                } label: {
                    Label(refreshing ? "Обновляется…" : "Обновить", systemImage: "arrow.clockwise")
                }
                .disabled(refreshing)
            }
            Section {
                if s.wrappedValue.servers.isEmpty {
                    Text("Серверов пока нет — обновите подписку.").foregroundStyle(.secondary)
                }
                ForEach(s.wrappedValue.servers) { server in
                    let linked = model.profiles.filter { $0.subscriptionID == subscriptionID && $0.subscriptionServer == server.name }
                    HStack {
                        VStack(alignment: .leading, spacing: 1) {
                            Text(server.name)
                            Text(serverDetails(server)).font(.caption).foregroundStyle(.secondary)
                        }
                        Spacer()
                        if let p = linked.first {
                            Button("Профиль «\(p.name)»") { model.selection = .profile(p.id) }
                                .buttonStyle(.link)
                        }
                        Button("Добавить в профили") { model.addProfile(from: server, of: subscriptionID) }
                            .disabled(server.proto == nil)
                    }
                }
            } header: {
                Text("Серверы")
            } footer: {
                Text("Профиль хранит выбор сервера: при обновлении подписки его ссылка заменяется свежей. Сервер в профиле можно сменить на другой из этой подписки.")
            }
        }
        .formStyle(.grouped)
        .navigationTitle(s.wrappedValue.name)
        .toolbar {
            Button(role: .destructive) { model.deleteSubscription(subscriptionID) } label: {
                Label("Удалить", systemImage: "trash")
            }
        }
    }

    private func serverDetails(_ server: SubscriptionServer) -> String {
        guard let proto = server.proto else {
            return "\(server.uri.split(separator: ":").first ?? "?")://… — CEP этот протокол не поддерживает"
        }
        guard let link = try? ProxyLink.parse(server.uri) else { return proto.title }
        let transport = link.query["type"].map { " · \($0)" } ?? ""
        let security = link.query["security"].flatMap { $0.isEmpty || $0 == "none" ? nil : " · \($0)" } ?? ""
        return "\(proto.title)\(transport)\(security) · \(link.host):\(link.port)"
    }
}

/// Раздел профиля, выбранного из подписки: какой сервер и смена на другой.
struct SubscriptionServerSection: View {
    @EnvironmentObject var model: AppModel
    let profile: Profile

    var body: some View {
        if let id = profile.subscriptionID, let s = model.subscription(id) {
            Section {
                Picker("Сервер", selection: Binding(
                    get: { profile.subscriptionServer },
                    set: { name in
                        if let server = s.servers.first(where: { $0.name == name }) { model.select(server: server, for: profile.id) }
                    }
                )) {
                    if !s.servers.contains(where: { $0.name == profile.subscriptionServer }) {
                        Text("\(profile.subscriptionServer) — нет в подписке").tag(profile.subscriptionServer)
                    }
                    ForEach(s.servers.filter { $0.proto != nil }) { Text($0.name).tag($0.name) }
                }
                Button("Открыть подписку «\(s.name)»") { model.selection = .subscription(id) }
                    .buttonStyle(.link)
            } header: {
                Text("Из подписки")
            } footer: {
                Text("Ссылка ниже обновляется вместе с подпиской. Если править её вручную, следующее обновление подписки её перезапишет.")
            }
        }
    }
}
