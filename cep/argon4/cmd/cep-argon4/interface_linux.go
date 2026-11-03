//go:build linux

package main

import (
	"fmt"
	"net/netip"
	"os/exec"

	"cep/argon4"
)

const defaultTunName = "argon0"

func configureInterface(name string, response argon4.HandshakeResponse) error {
	local, err := netip.ParseAddr(response.AssignedIP)
	if err != nil || !local.Is4() {
		return fmt.Errorf("неверный assignedIp %q", response.AssignedIP)
	}
	if response.SubnetCidr < 0 || response.SubnetCidr > 32 {
		return fmt.Errorf("неверная маска /%d", response.SubnetCidr)
	}
	commands := [][]string{
		{"ip", "addr", "replace", fmt.Sprintf("%s/%d", local, response.SubnetCidr), "dev", name},
		{"ip", "link", "set", "dev", name, "mtu", fmt.Sprint(response.MTU), "up"},
	}
	for _, args := range commands {
		if output, err := exec.Command(args[0], args[1:]...).CombinedOutput(); err != nil {
			return fmt.Errorf("%v: %w: %s", args, err, output)
		}
	}
	return nil
}
