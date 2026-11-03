package main

import (
	"fmt"
	"os/exec"
	"strings"
)

// DNSManager использует systemd-resolved, чтобы не перезаписывать символическую ссылку resolv.conf.
type DNSManager struct {
	path          string
	interfaceName string
	servers       []string
	applied       bool
}

func newDNSManager(interfaceName string, servers []string) *DNSManager {
	return &DNSManager{interfaceName: interfaceName, servers: servers}
}

func (d *DNSManager) apply() error {
	if len(d.servers) == 0 {
		return nil
	}
	path, err := exec.LookPath("resolvectl")
	if err != nil {
		return fmt.Errorf("не найден resolvectl: задайте пустой dns в цепочке и настройте DNS самостоятельно")
	}
	args := append([]string{"dns", d.interfaceName}, d.servers...)
	if output, err := exec.Command(path, args...).CombinedOutput(); err != nil {
		return fmt.Errorf("resolvectl dns: %w: %s", err, strings.TrimSpace(string(output)))
	}
	if output, err := exec.Command(path, "domain", d.interfaceName, "~.").CombinedOutput(); err != nil {
		_ = exec.Command(path, "revert", d.interfaceName).Run()
		return fmt.Errorf("resolvectl domain: %w: %s", err, strings.TrimSpace(string(output)))
	}
	if output, err := exec.Command(path, "default-route", d.interfaceName, "yes").CombinedOutput(); err != nil {
		_ = exec.Command(path, "revert", d.interfaceName).Run()
		return fmt.Errorf("resolvectl default-route: %w: %s", err, strings.TrimSpace(string(output)))
	}
	d.path = path
	d.applied = true
	return nil
}

func (d *DNSManager) restore() {
	if d.applied {
		_ = exec.Command(d.path, "revert", d.interfaceName).Run()
		d.applied = false
	}
}
