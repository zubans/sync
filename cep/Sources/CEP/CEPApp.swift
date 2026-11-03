import CEPCore
import SwiftUI

@main
struct CEPApp: App {
    @StateObject private var model = AppModel()

    init() {
        // Запуск через `swift run` без .app: иначе окно не получает фокус.
        NSApplication.shared.setActivationPolicy(.regular)
    }

    var body: some Scene {
        WindowGroup("CEP — цепочки VPN", id: "main") {
            ContentView()
                .environmentObject(model)
                .frame(minWidth: 860, minHeight: 540)
        }
        MenuBarExtra {
            MenuBarContent().environmentObject(model)
        } label: {
            Image(systemName: model.status?.phase.symbol ?? "link")
        }
    }
}

struct MenuBarContent: View {
    @EnvironmentObject var model: AppModel
    @Environment(\.openWindow) private var openWindow

    var body: some View {
        if let s = model.status, model.isActive {
            Text("\(s.chainName): \(s.phase.title)")
            Button("Отключить") { model.disconnect() }
        } else {
            ForEach(model.chains) { chain in
                Button("Подключить «\(chain.name)»") { model.connect(chain) }
            }
            if model.chains.isEmpty { Text("Цепочек пока нет") }
        }
        Divider()
        Button("Открыть окно") {
            openWindow(id: "main")
            NSApp.activate(ignoringOtherApps: true)
        }
        Button("Выйти") { NSApp.terminate(nil) }
    }
}

extension EnginePhase {
    var title: String {
        switch self {
        case .starting: "запуск"
        case .connecting: "подключение"
        case .connected: "подключено"
        case .stopping: "отключение"
        case .stopped: "отключено"
        case .failed: "ошибка"
        }
    }

    var symbol: String {
        switch self {
        case .starting, .connecting, .stopping: "link.badge.plus"
        case .connected: "lock.shield.fill"
        case .stopped: "link"
        case .failed: "exclamationmark.triangle"
        }
    }
}

extension HopState {
    var symbol: String {
        switch self {
        case .pending: "circle.dotted"
        case .connecting: "arrow.triangle.2.circlepath"
        case .up: "checkmark.circle.fill"
        case .failed: "xmark.octagon.fill"
        case .stopped: "stop.circle"
        }
    }

    var color: Color {
        switch self {
        case .up: .green
        case .failed: .red
        case .connecting: .orange
        default: .secondary
        }
    }
}
