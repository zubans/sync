import CEPCore
import SwiftUI

struct StatusView: View {
    @EnvironmentObject var model: AppModel
    @State private var logSource = 0

    var body: some View {
        VStack(alignment: .leading, spacing: 16) {
            header
            if let s = model.status, !s.hops.isEmpty {
                hops(s)
            }
            if let error = model.status?.error {
                Label(error, systemImage: "exclamationmark.triangle.fill")
                    .foregroundStyle(.red)
                    .textSelection(.enabled)
            }
            logs
        }
        .padding(20)
        .navigationTitle("Подключение")
    }

    private var header: some View {
        HStack(spacing: 12) {
            Image(systemName: model.status?.phase.symbol ?? "link")
                .font(.largeTitle)
                .foregroundStyle(model.status?.phase == .connected ? .green : .secondary)
            VStack(alignment: .leading) {
                if model.launching {
                    Text("Запуск движка…").font(.title2)
                    Text("Подтвердите пароль администратора").foregroundStyle(.secondary)
                } else if let s = model.status {
                    Text(s.chainName).font(.title2)
                    Text(s.phase.title.capitalized).foregroundStyle(.secondary)
                } else {
                    Text("Не подключено").font(.title2)
                    Text("Выберите цепочку слева и нажмите «Подключить»").foregroundStyle(.secondary)
                }
            }
            Spacer()
            if model.isActive {
                Button(role: .destructive) { model.disconnect() } label: {
                    Label("Отключить", systemImage: "stop.fill")
                }
                .controlSize(.large)
                .disabled(model.status?.phase == .stopping)
            }
        }
    }

    private func hops(_ s: EngineStatus) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Image(systemName: "laptopcomputer")
                Text("Mac")
            }
            .foregroundStyle(.secondary)
            ForEach(s.hops) { hop in
                HStack(alignment: .firstTextBaseline) {
                    Image(systemName: hop.state.symbol).foregroundStyle(hop.state.color)
                    Text("\(hop.index + 1). \(hop.name)").bold()
                    Text(hop.proto.title).foregroundStyle(.secondary)
                    if let ip = hop.serverIP { Text("→ \(ip)").monospaced().foregroundStyle(.secondary) }
                    if let iface = hop.interface {
                        Text("\(iface) \(hop.localAddress ?? "")").monospaced().foregroundStyle(.secondary)
                    }
                    Spacer()
                }
                if let routes = hop.routes {
                    Text("через узел: \(routes.joined(separator: ", "))")
                        .font(.caption).foregroundStyle(.secondary).padding(.leading, 26).textSelection(.enabled)
                }
                if let dns = hop.innerDNS {
                    Text("внутренний DNS: \(dns)")
                        .font(.caption).foregroundStyle(.secondary).padding(.leading, 26).textSelection(.enabled)
                }
                if let message = hop.message {
                    Text(message).font(.caption).foregroundStyle(.red).padding(.leading, 26).textSelection(.enabled)
                }
            }
            HStack {
                Image(systemName: "globe")
                Text("Интернет")
            }
            .foregroundStyle(.secondary)
        }
        .padding(12)
        .background(.quaternary.opacity(0.5), in: RoundedRectangle(cornerRadius: 8))
    }

    private var logs: some View {
        VStack(alignment: .leading) {
            HStack {
                Picker("Журнал", selection: $logSource) {
                    Text("Движок").tag(0)
                    ForEach(model.status?.hops ?? []) { hop in
                        Text("Узел \(hop.index + 1)").tag(hop.index + 1)
                    }
                }
                .pickerStyle(.segmented)
                .frame(maxWidth: 420)
                Spacer()
                Button("Очистить") { model.clearLogs() }.disabled(model.isActive)
                Button("Показать в Finder") { NSWorkspace.shared.open(model.run.url) }
            }
            TimelineView(.periodic(from: .now, by: 1)) { _ in
                let url = logSource == 0 ? model.run.engineLog : model.run.hopLog(logSource - 1)
                ScrollView {
                    Text(model.logText(url))
                        .font(.system(.caption, design: .monospaced))
                        .textSelection(.enabled)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(8)
                }
                .defaultScrollAnchor(.bottom)
                .background(.background.secondary, in: RoundedRectangle(cornerRadius: 6))
            }
        }
    }
}
