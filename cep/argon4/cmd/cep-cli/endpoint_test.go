package main

import (
	"strings"
	"testing"
)

func TestParseHostPort(t *testing.T) {
	tests := []struct {
		raw  string
		host string
		port int
	}{
		{"94.103.12.175", "94.103.12.175", 0},
		{"94.103.12.175:51820", "94.103.12.175", 51820},
		{"https://example.com:4443/path", "example.com", 4443},
		{"argon4://example.com", "example.com", 0},
	}
	for _, test := range tests {
		endpoint, err := parseHostPort(test.raw)
		if err != nil || endpoint.Host != test.host || endpoint.Port != test.port {
			t.Errorf("parseHostPort(%q) = %+v, %v", test.raw, endpoint, err)
		}
	}
}

func TestParseProxyLink(t *testing.T) {
	link, err := parseProxyLink("vless://uuid@example.com:443?security=reality&sni=cdn.example.com#label")
	if err != nil {
		t.Fatal(err)
	}
	if link.UserInfo != "uuid" || link.Host != "example.com" || link.Port != 443 || link.sni() != "cdn.example.com" {
		t.Fatalf("неожиданная ссылка: %+v", link)
	}
}

func TestPinOpenVPNRemotePreservesInlineCertificates(t *testing.T) {
	config := "client\nremote example.com 1194 udp\n<ca>\nremote not-a-directive\n</ca>\nroute 10.0.0.0 255.0.0.0\n"
	pinned, err := pinOpenVPNRemote(config, "192.0.2.10", "cepo0")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(pinned, "remote 192.0.2.10 1194 udp\n") {
		t.Fatalf("remote не закреплён: %s", pinned)
	}
	if !strings.Contains(pinned, "<ca>\nremote not-a-directive\n</ca>") {
		t.Fatalf("inline-блок изменился: %s", pinned)
	}
	if !strings.HasSuffix(pinned, "dev cepo0\ndev-type tun") {
		t.Fatalf("не задан интерфейс: %s", pinned)
	}

	pinnedPort, err := pinOpenVPNRemoteWithPort(config, "127.0.0.1", 45678, "cepo1")
	if err != nil {
		t.Fatal(err)
	}
	if !strings.HasPrefix(pinnedPort, "remote 127.0.0.1 45678 udp\n") {
		t.Fatalf("portOverride не применён: %s", pinnedPort)
	}
}

func TestRewriteServerPortAndDefaults(t *testing.T) {
	if got := rewriteServerPort("https://vpn.example.com/group", 43210); got != "https://vpn.example.com:43210/group" {
		t.Fatalf("rewriteServerPort(https) = %q", got)
	}
	if got := rewriteServerPort("vpn.example.com:4443", 43210); got != "vpn.example.com:43210" {
		t.Fatalf("rewriteServerPort(host:port) = %q", got)
	}
	port, err := resolvedPortFor(Profile{Proto: "transit", Server: "relay.example.com"})
	if err != nil || port != 51820 {
		t.Fatalf("resolvedPortFor(transit) = %d, %v", port, err)
	}
}
