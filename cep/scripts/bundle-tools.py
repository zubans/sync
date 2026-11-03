#!/usr/bin/env python3
"""Кладёт VPN-программы внутрь CEP.app, чтобы на другом Mac не нужен был Homebrew.

Программы — в Contents/Helpers, их библиотеки (кроме системных) — в Contents/Frameworks.
Пути к библиотекам переписываются на @executable_path/@loader_path, затем всё подписывается
ad-hoc: install_name_tool ломает подпись, а на arm64 неподписанный код не запускается.

Использование: scripts/bundle-tools.py build/CEP.app
"""
import os
import re
import shutil
import subprocess
import sys

# имя в .app → кандидаты на этой машине (первый найденный)
TOOLS = {
    "sing-box": ["/opt/homebrew/bin/sing-box", "/usr/local/bin/sing-box"],
    # brew-обёртка xray — shell-скрипт, берём сам бинарь
    "xray": ["/opt/homebrew/opt/xray/libexec/xray", "/usr/local/opt/xray/libexec/xray"],
    "openvpn": ["/opt/homebrew/sbin/openvpn", "/usr/local/sbin/openvpn"],
    "openconnect": ["/opt/homebrew/bin/openconnect", "/usr/local/bin/openconnect"],
    "sstpc": ["/opt/homebrew/sbin/sstpc", "/usr/local/sbin/sstpc"],
    "amneziawg-go": ["/opt/homebrew/bin/amneziawg-go", "/usr/local/bin/amneziawg-go"],
    "awg": ["/opt/homebrew/bin/awg", "/usr/local/bin/awg"],
}

SYSTEM = ("/usr/lib/", "/System/")


def run(*args):
    return subprocess.run(args, check=True, capture_output=True, text=True).stdout


def deps(path):
    """Несистемные зависимости Mach-O: (как записано, реальный путь)."""
    rpaths = re.findall(r"cmd LC_RPATH\n\s+cmdsize \d+\n\s+path (\S+)", run("otool", "-l", path))
    result = []
    for line in run("otool", "-L", path).splitlines()[1:]:
        name = line.strip().split(" (")[0]
        if name.startswith(SYSTEM) or os.path.basename(name) == os.path.basename(path):
            continue
        real = name
        if name.startswith("@rpath/"):
            for rp in rpaths:
                rp = rp.replace("@loader_path", os.path.dirname(path))
                cand = os.path.join(rp, name[len("@rpath/"):])
                if os.path.exists(cand):
                    real = cand
                    break
        elif name.startswith("@loader_path/"):
            real = os.path.join(os.path.dirname(path), name[len("@loader_path/"):])
        if not os.path.exists(real):
            sys.exit(f"не найдена библиотека {name} для {path}")
        result.append((name, os.path.realpath(real)))
    return result


def main():
    app = sys.argv[1]
    helpers = os.path.join(app, "Contents/Helpers")
    frameworks = os.path.join(app, "Contents/Frameworks")
    shutil.rmtree(helpers, ignore_errors=True)
    shutil.rmtree(frameworks, ignore_errors=True)
    os.makedirs(helpers)
    os.makedirs(frameworks)

    copied = {}  # реальный путь библиотеки → путь в Frameworks
    queue = []
    missing = []
    for name, candidates in TOOLS.items():
        src = next((c for c in candidates if os.path.exists(c)), None)
        if src is None:
            missing.append(name)
            continue
        dst = os.path.join(helpers, name)
        shutil.copy2(os.path.realpath(src), dst)
        os.chmod(dst, 0o755)
        queue.append((dst, os.path.realpath(src), "@executable_path/../Frameworks/"))

    # обход в ширину: зависимости программ и зависимости библиотек
    while queue:
        dst, src, prefix = queue.pop(0)
        for name, real in deps(src):
            base = os.path.basename(real)
            if real not in copied:
                lib = os.path.join(frameworks, base)
                shutil.copy2(real, lib)
                os.chmod(lib, 0o755)
                run("install_name_tool", "-id", "@loader_path/" + base, lib)
                copied[real] = lib
                queue.append((lib, real, "@loader_path/"))
            run("install_name_tool", "-change", name, prefix + base, dst)

    for f in [*copied.values(), *(os.path.join(helpers, n) for n in os.listdir(helpers))]:
        # rpath в Cellar на другой машине не существует, а все зависимости уже переписаны
        for rp in re.findall(r"cmd LC_RPATH\n\s+cmdsize \d+\n\s+path (\S+)", run("otool", "-l", f)):
            if rp.startswith(("/opt/homebrew", "/usr/local")):
                run("install_name_tool", "-delete_rpath", rp, f)
        run("codesign", "--force", "--sign", "-", f)

    # корневые сертификаты: openconnect (gnutls) и sstpc (openssl) из brew ищут их в /opt/homebrew/etc,
    # которого на другом Mac нет; движок передаёт этот файл через --cafile / --ca-cert
    roots = run("security", "find-certificate", "-a", "-p", "/System/Library/Keychains/SystemRootCertificates.keychain")
    with open(os.path.join(app, "Contents/Resources/cacert.pem"), "w") as f:
        f.write(roots)

    print(f"программ: {len(TOOLS) - len(missing)}, библиотек: {len(copied)}")
    if missing:
        print("не найдены (протоколы с ними на другом Mac не заработают):", ", ".join(missing))


if __name__ == "__main__":
    main()
