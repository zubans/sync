//go:build linux

package tunnel

import (
	"fmt"
	"os"
	"os/exec"
	"syscall"
	"unsafe"
)

const (
	cIFF_TUN   = 0x0001
	cIFF_NO_PI = 0x1000
	cTUNSETIFF = 0x400454ca
)

type ifReq struct {
	Name  [16]byte
	Flags uint16
	pad   [22]byte
}

// TunDevice представляет виртуальный сетевой интерфейс Linux TUN (/dev/net/tun).
type TunDevice struct {
	file *os.File
	name string
}

// OpenTun создаёт или подключается к виртуальному TUN-интерфейсу в Linux/Ubuntu (например, "argon0").
func OpenTun(ifName string) (*TunDevice, error) {
	fd, err := syscall.Open("/dev/net/tun", os.O_RDWR|syscall.O_NONBLOCK, 0)
	if err != nil {
		return nil, fmt.Errorf("open /dev/net/tun: %w", err)
	}

	var req ifReq
	copy(req.Name[:15], ifName)
	req.Flags = cIFF_TUN | cIFF_NO_PI

	_, _, errno := syscall.Syscall(syscall.SYS_IOCTL, uintptr(fd), uintptr(cTUNSETIFF), uintptr(unsafe.Pointer(&req)))
	if errno != 0 {
		_ = syscall.Close(fd)
		return nil, fmt.Errorf("ioctl TUNSETIFF(%s): %w", ifName, errno)
	}

	actualName := string(req.Name[:])
	for i, b := range req.Name {
		if b == 0 {
			actualName = string(req.Name[:i])
			break
		}
	}

	file := os.NewFile(uintptr(fd), "/dev/net/tun")
	return &TunDevice{file: file, name: actualName}, nil
}

func (t *TunDevice) Name() string {
	return t.name
}

func (t *TunDevice) Read(p []byte) (int, error) {
	return t.file.Read(p)
}

func (t *TunDevice) Write(p []byte) (int, error) {
	return t.file.Write(p)
}

func (t *TunDevice) Close() error {
	return t.file.Close()
}

// ConfigureInterface назначает IP/CIDR и MTU, поднимает интерфейс TUN, а при включённом NAT настраивает
// IPv4 forwarding, правила iptables MASQUERADE на выходной интерфейс Ubuntu и перенаправление (DNAT) на сервис по умолчанию.
func ConfigureInterface(tunName, cidr, serverIP string, mtu int, outInterface string, enableNAT bool, backendTarget string) error {
	cmds := [][]string{
		{"ip", "addr", "replace", cidr, "dev", tunName},
		{"ip", "link", "set", "dev", tunName, "mtu", fmt.Sprintf("%d", mtu), "up"},
	}
	for _, args := range cmds {
		if out, err := exec.Command(args[0], args[1:]...).CombinedOutput(); err != nil {
			return fmt.Errorf("команда %v завершилась с ошибкой: %w (%s)", args, err, string(out))
		}
	}

	if enableNAT {
		_ = os.WriteFile("/proc/sys/net/ipv4/ip_forward", []byte("1\n"), 0644)
		if outInterface != "" {
			// Оборачивание и маршрутизация трафика туннеля в выбранный существующий интерфейс Ubuntu
			natCmds := [][]string{
				{"iptables", "-t", "nat", "-C", "POSTROUTING", "-o", outInterface, "-j", "MASQUERADE"},
			}
			if err := exec.Command(natCmds[0][0], natCmds[0][1:]...).Run(); err != nil {
				_ = exec.Command("iptables", "-t", "nat", "-A", "POSTROUTING", "-o", outInterface, "-j", "MASQUERADE").Run()
			}
			_ = exec.Command("iptables", "-A", "FORWARD", "-i", tunName, "-o", outInterface, "-j", "ACCEPT").Run()
			_ = exec.Command("iptables", "-A", "FORWARD", "-i", outInterface, "-o", tunName, "-m", "state", "--state", "RELATED,ESTABLISHED", "-j", "ACCEPT").Run()
		} else {
			// Универсальная трансляция адресов (MASQUERADE) для подсети туннеля
			_ = exec.Command("iptables", "-t", "nat", "-A", "POSTROUTING", "-s", cidr, "-j", "MASQUERADE").Run()
		}
		if backendTarget != "" && serverIP != "" {
			// Проброс TCP-обращений к виртуальному IP шлюза (например, 10.44.0.1:80) на целевой бэкенд по умолчанию (например, app:80)
			_ = exec.Command("iptables", "-t", "nat", "-A", "PREROUTING", "-i", tunName, "-p", "tcp", "-d", serverIP, "-j", "DNAT", "--to-destination", backendTarget).Run()
			_ = exec.Command("iptables", "-t", "nat", "-A", "POSTROUTING", "-p", "tcp", "-j", "MASQUERADE").Run()
		}
	}
	return nil
}
