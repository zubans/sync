import CEPCore
import Foundation

// cep-engine up --run-dir DIR
// Запускается приложением под root, читает DIR/plan.json (и сразу удаляет его — там пароли),
// пишет DIR/status.json, останавливается по файлу DIR/stop или SIGTERM.

let args = CommandLine.arguments
guard args.count == 4, args[1] == "up", args[2] == "--run-dir" else {
    FileHandle.standardError.write(Data("usage: cep-engine up --run-dir DIR\n".utf8))
    exit(64)
}
guard getuid() == 0 else {
    FileHandle.standardError.write(Data("cep-engine должен работать под root\n".utf8))
    exit(77)
}

let dir = RunDirectory(URL(fileURLWithPath: args[3], isDirectory: true))

if let previous = dir.readStatus(), previous.isActive, previous.pid != getpid(), kill(previous.pid, 0) == 0 {
    log("уже запущен движок pid \(previous.pid)")
    exit(75)
}

// Добиваем процессы узлов, оставшиеся от движка, который убили через SIGKILL.
let childrenFile = dir.url.appendingPathComponent("children.pid")
if let text = try? String(contentsOf: childrenFile, encoding: .utf8) {
    for line in text.split(separator: "\n") {
        let parts = line.split(separator: " ", maxSplits: 1)
        guard parts.count == 2, let pid = Int32(parts[0]) else { continue }
        let comm = Shell.run("/bin/ps", ["-p", String(pid), "-o", "comm="]).output
        if comm.contains(parts[1]) {
            log("останавливаю оставшийся процесс \(parts[1]) pid \(pid)")
            kill(pid, SIGTERM)
        }
    }
    try? FileManager.default.removeItem(at: childrenFile)
}

let plan: Plan
do {
    let data = try Data(contentsOf: dir.plan)
    try? FileManager.default.removeItem(at: dir.plan)
    plan = try JSONDecoder().decode(Plan.self, from: data)
} catch {
    log("не удалось прочитать задание: \(error.localizedDescription)")
    exit(66)
}
try? FileManager.default.removeItem(at: dir.stopRequest)

for sig in [SIGTERM, SIGINT, SIGHUP] {
    signal(sig) { _ in Stop.requested = true }
}
signal(SIGPIPE, SIG_IGN)

exit(Engine(dir: dir, plan: plan).run())
