import CEPCore
import SwiftUI

struct ContentView: View {
    @EnvironmentObject var model: AppModel
    @State private var linkSheet = false

    var body: some View {
        NavigationSplitView {
            List(selection: $model.selection) {
                Section {
                    Label("Подключение", systemImage: model.status?.phase.symbol ?? "link").tag(SidebarItem.status)
                    Label("Компоненты", systemImage: "shippingbox").tag(SidebarItem.tools)
                }
                Section("Цепочки") {
                    ForEach(model.chains) { chain in
                        Label(chain.name, systemImage: "point.3.connected.trianglepath.dotted")
                            .tag(SidebarItem.chain(chain.id))
                            .contextMenu {
                                Button("Подключить") { model.connect(chain) }
                                Button("Удалить", role: .destructive) { model.deleteChain(chain.id) }
                            }
                    }
                }
                Section("Профили") {
                    ForEach(model.profiles) { p in
                        VStack(alignment: .leading, spacing: 1) {
                            Text(p.name)
                            Text(p.proto.title).font(.caption).foregroundStyle(.secondary)
                        }
                        .tag(SidebarItem.profile(p.id))
                        .contextMenu {
                            Button("Удалить", role: .destructive) { model.deleteProfile(p.id) }
                        }
                    }
                }
            }
            .navigationSplitViewColumnWidth(min: 220, ideal: 250)
            .toolbar {
                Menu {
                    Button("Новая цепочка") { model.addChain() }
                    Divider()
                    Button("Профиль из ссылки…") { linkSheet = true }
                    Divider()
                    Button("Экспорт профилей и цепочек…") { model.exportStore() }
                    Button("Импорт профилей и цепочек…") { model.importStore() }
                    Menu("Новый профиль") {
                        ForEach(VPNProtocol.allCases) { proto in
                            Button(proto.title) { model.addProfile(proto) }
                        }
                    }
                } label: {
                    Label("Добавить", systemImage: "plus")
                }
            }
        } detail: {
            switch model.selection {
            case .chain(let id) where model.chains.contains { $0.id == id }:
                ChainEditor(chainID: id).id(id)
            case .profile(let id) where model.profiles.contains { $0.id == id }:
                ProfileEditor(profileID: id).id(id)
            case .tools:
                ToolsView()
            default:
                StatusView()
            }
        }
        .sheet(isPresented: $linkSheet) { ImportLinkSheet() }
        .alert("CEP", isPresented: Binding(get: { model.alert != nil }, set: { if !$0 { model.alert = nil } })) {
            Button("OK") { model.alert = nil }
        } message: {
            Text(model.alert ?? "")
        }
    }
}

struct ImportLinkSheet: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.dismiss) private var dismiss
    @State private var text = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Ссылка vless://, hysteria2://, hy2:// или hysteria://").font(.headline)
            TextEditor(text: $text)
                .font(.system(.body, design: .monospaced))
                .frame(width: 520, height: 120)
                .border(.separator)
            HStack {
                Button("Вставить из буфера") { text = NSPasteboard.general.string(forType: .string) ?? "" }
                Spacer()
                Button("Отмена") { dismiss() }.keyboardShortcut(.cancelAction)
                Button("Добавить") {
                    for line in text.split(whereSeparator: \.isNewline) where !line.trimmingCharacters(in: .whitespaces).isEmpty {
                        model.importLink(String(line))
                    }
                    dismiss()
                }
                .keyboardShortcut(.defaultAction)
                .disabled(text.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
            }
        }
        .padding(20)
    }
}

struct ToolsView: View {
    var body: some View {
        Form {
            Section {
                ForEach(Tool.allCases, id: \.self) { tool in
                    let path = tool.locate()
                    LabeledContent {
                        if let path {
                            Text(path).foregroundStyle(.secondary).textSelection(.enabled)
                        } else {
                            Text(tool.installHint).foregroundStyle(.orange).textSelection(.enabled)
                        }
                    } label: {
                        Label(tool.rawValue, systemImage: path == nil ? "xmark.circle" : "checkmark.circle.fill")
                            .foregroundStyle(path == nil ? .orange : .green)
                    }
                }
            } header: {
                Text("Внешние программы")
            } footer: {
                Text("Ищутся сначала внутри CEP.app\(Tool.bundledDir == nil ? " (в этой сборке их нет)" : ""), затем в \(Tool.searchPaths.joined(separator: ", ")). Протоколы: VLESS — xray + sing-box; Hysteria — sing-box; AmneziaWG — amneziawg-go + awg; SSTP — sstpc.")
            }
        }
        .formStyle(.grouped)
        .navigationTitle("Компоненты")
    }
}
