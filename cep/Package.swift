// swift-tools-version:5.10
import PackageDescription

let package = Package(
    name: "CEP",
    platforms: [.macOS(.v14)],
    products: [
        .executable(name: "CEP", targets: ["CEP"]),
        .executable(name: "cep-engine", targets: ["cep-engine"]),
    ],
    targets: [
        .target(name: "CEPCore"),
        .executableTarget(name: "cep-engine", dependencies: ["CEPCore"]),
        .executableTarget(name: "CEP", dependencies: ["CEPCore"]),
        .testTarget(name: "CEPCoreTests", dependencies: ["CEPCore"]),
    ]
)
