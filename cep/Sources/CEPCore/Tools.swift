import Foundation

public enum Tool: String, CaseIterable, Sendable {
    case singBox = "sing-box"
    case xray
    case openvpn
    case openconnect
    case amneziawgGo = "amneziawg-go"
    case awg
    case sstpc
    case cepArgon4 = "cep-argon4"

    public var installHint: String {
        switch self {
        case .singBox: "brew install sing-box"
        case .xray: "brew install xray"
        case .openvpn: "brew install openvpn"
        case .openconnect: "brew install openconnect"
        case .amneziawgGo, .awg: "scripts/install-deps.sh (сборка из исходников amnezia-vpn)"
        case .sstpc: "brew install sstp-client"
        case .cepArgon4: "./scripts/build-app.sh (сборка встроенного Argon4-помощника)"
        }
    }

    /// Каталоги, где ищутся программы. Путь в Plan не передаётся: движок под root ищет сам.
    public static let searchPaths = [
        "/opt/homebrew/bin",
        "/opt/homebrew/sbin",
        "/usr/local/bin",
        "/usr/local/sbin",
        "/usr/sbin",
        "/usr/bin",
    ]

    /// Программы, вложенные в CEP.app (scripts/bundle-tools.py): и приложение, и движок лежат в Contents/MacOS.
    public static var bundledDir: String? {
        guard let exe = Bundle.main.executableURL?.resolvingSymlinksInPath() else { return nil }
        let dir = exe.deletingLastPathComponent().deletingLastPathComponent().appendingPathComponent("Helpers").path
        return FileManager.default.fileExists(atPath: dir) ? dir : nil
    }

    /// Корневые сертификаты из CEP.app: brew-сборки gnutls и openssl ищут их в /opt/homebrew/etc.
    public static var bundledCAFile: String? {
        guard let helpers = bundledDir else { return nil }
        let path = URL(fileURLWithPath: helpers).deletingLastPathComponent().appendingPathComponent("Resources/cacert.pem").path
        return FileManager.default.fileExists(atPath: path) ? path : nil
    }

    /// Сначала копия из CEP.app: она не зависит от Homebrew, а в /Applications её не подменить без прав администратора.
    public func locate() -> String? {
        let fm = FileManager.default
        let executableDir = Bundle.main.executableURL?.deletingLastPathComponent().path
        for dir in [Self.bundledDir, executableDir].compactMap({ $0 }) + Self.searchPaths {
            let path = "\(dir)/\(rawValue)"
            if fm.isExecutableFile(atPath: path) {
                return path
            }
        }
        return nil
    }
}
