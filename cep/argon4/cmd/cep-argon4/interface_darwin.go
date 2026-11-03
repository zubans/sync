//go:build darwin

package main

import (
	"fmt"
	"net"
	"net/netip"
	"os/exec"

	"cep/argon4"
)

const defaultTunName = "utun"

func configureInterface(name string, response argon4.HandshakeResponse) error {
	local, err := netip.ParseAddr(response.AssignedIP)
	if err != nil || !local.Is4() {
		return fmt.Errorf("неверный assignedIp %q", response.AssignedIP)
	}
	peer, err := netip.ParseAddr(response.ServerIP)
	if err != nil || !peer.Is4() {
		return fmt.Errorf("неверный serverIp %q", response.ServerIP)
	}
	if response.SubnetCidr < 0 || response.SubnetCidr > 32 {
		return fmt.Errorf("неверная маска /%d", response.SubnetCidr)
	}
	mask := net.CIDRMask(response.SubnetCidr, 32)
	command := exec.Command("/sbin/ifconfig", name, "inet", local.String(), peer.String(),
		"netmask", net.IP(mask).String(), "mtu", fmt.Sprint(response.MTU), "up")
	if output, err := command.CombinedOutput(); err != nil {
		return fmt.Errorf("ifconfig %s: %w: %s", name, err, output)
	}
	return nil
}
