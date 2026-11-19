package cepcore

import (
	"net/netip"
	"testing"
)

func TestVPNRoutesWithoutBypassIsDefault(t *testing.T) {
	got := vpnRoutes(nil)
	if len(got) != 1 || got[0] != "0.0.0.0/0" {
		t.Fatalf("got %v", got)
	}
}

func TestVPNRoutesExcludeExactlyBypass(t *testing.T) {
	bypass := []string{"192.168.0.0/16", "203.0.113.7/32", "10.0.0.0/8"}
	routes := vpnRoutes(bypass)
	var prefixes []netip.Prefix
	for _, r := range routes {
		prefixes = append(prefixes, netip.MustParsePrefix(r))
	}
	in := func(ip string) bool {
		a := netip.MustParseAddr(ip)
		for _, p := range prefixes {
			if p.Contains(a) {
				return true
			}
		}
		return false
	}
	for _, ip := range []string{"192.168.1.1", "203.0.113.7", "10.1.2.3"} {
		if in(ip) {
			t.Errorf("%s должен идти мимо VPN", ip)
		}
	}
	for _, ip := range []string{"8.8.8.8", "203.0.113.6", "203.0.113.8", "192.169.0.1", "11.0.0.1", "0.0.0.1", "255.255.255.254"} {
		if !in(ip) {
			t.Errorf("%s должен идти в VPN", ip)
		}
	}
	// маршруты не пересекаются
	for i := range prefixes {
		for j := i + 1; j < len(prefixes); j++ {
			if prefixes[i].Overlaps(prefixes[j]) {
				t.Errorf("%v пересекается с %v", prefixes[i], prefixes[j])
			}
		}
	}
}

func TestValidateRejectsBadBypass(t *testing.T) {
	plan := `{"chainName":"c","hops":[{"id":"1","name":"a","proto":"hysteria2","uri":"hy2://pw@1.2.3.4:443"}],"dns":[],"blockIPv6":true,"bypass":"10.0.0.0/33"}`
	if got := Validate(plan); got == "[]" {
		t.Fatalf("ошибка в исключениях не найдена: %s", got)
	}
}
