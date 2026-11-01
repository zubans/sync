package cepcore

import (
	"encoding/json"
	"strings"
	"testing"
)

func testPlan(hops ...Profile) string {
	b, _ := json.Marshal(Plan{ChainName: "test", Hops: hops, DNS: []string{"192.168.1.119"}, BlockIPv6: true})
	return string(b)
}

func TestVLESSGrpcTLS(t *testing.T) {
	l, err := parseLink("vless://11111111-2222-3333-4444-555555555555@example.com:443?type=grpc&security=tls&fp=safari&serviceName=svc#name")
	if err != nil {
		t.Fatal(err)
	}
	out, err := singBoxOutbound(l, "1.2.3.4")
	if err != nil {
		t.Fatal(err)
	}
	tls := out["tls"].(map[string]any)
	if tls["server_name"] != "example.com" || out["server"] != "1.2.3.4" {
		t.Fatalf("sni/server: %v", out)
	}
	if tls["utls"].(map[string]any)["fingerprint"] != "safari" {
		t.Fatalf("fingerprint: %v", tls)
	}
	if tr := out["transport"].(map[string]any); tr["type"] != "grpc" || tr["service_name"] != "svc" {
		t.Fatalf("transport: %v", tr)
	}
}

func TestVLESSRealityVision(t *testing.T) {
	l, _ := parseLink("vless://11111111-2222-3333-4444-555555555555@example.com:443?type=tcp&security=reality&flow=xtls-rprx-vision&pbk=KEY&sid=ab&sni=www.microsoft.com")
	out, err := singBoxOutbound(l, "1.2.3.4")
	if err != nil {
		t.Fatal(err)
	}
	tls := out["tls"].(map[string]any)
	if out["flow"] != "xtls-rprx-vision" || tls["server_name"] != "www.microsoft.com" {
		t.Fatalf("%v", out)
	}
	if r := tls["reality"].(map[string]any); r["public_key"] != "KEY" || r["short_id"] != "ab" {
		t.Fatalf("reality: %v", r)
	}
	if _, ok := out["transport"]; ok {
		t.Fatal("tcp не должен задавать transport")
	}
}

func TestXHTTPRejected(t *testing.T) {
	l, _ := parseLink("vless://u@example.com:443?type=xhttp&security=tls")
	if _, err := singBoxOutbound(l, "1.2.3.4"); err == nil || !strings.Contains(err.Error(), "xhttp") {
		t.Fatalf("ожидалась ошибка xhttp, получено %v", err)
	}
}

func TestChainVLESSThenOpenConnect(t *testing.T) {
	plan, err := parsePlan(testPlan(
		Profile{Name: "VLESS", Proto: "vless", URI: "vless://u@example.com:443?type=grpc&security=tls"},
		Profile{Name: "OC", Proto: "openconnect", Server: "https://94.103.9.172:4443", Username: "u", Password: "p",
			OpenConnectFlavor: "anyconnect", ServerCert: "pin-sha256:abc=", Routes: "192.168.1.0/24"},
	))
	if err != nil {
		t.Fatal(err)
	}
	config, err := buildConfig(plan, []string{"1.2.3.4", "94.103.9.172"})
	if err != nil {
		t.Fatal(err)
	}
	ep := config["endpoints"].([]any)[0].(map[string]any)
	if ep["type"] != "openconnect" || ep["detour"] != "hop1-deadline" || ep["no_udp"] != true || ep["tag"] != "hop2" {
		t.Fatalf("endpoint: %v", ep)
	}
	if ep["server"] != "https://94.103.9.172:4443" {
		t.Fatalf("server: %v", ep["server"])
	}
	if _, ok := ep["tls"].(map[string]any)["server_name"]; ok {
		t.Fatal("для IP server_name не нужен")
	}
	route := config["route"].(map[string]any)
	if route["final"] != "hop2" {
		t.Fatalf("final: %v", route["final"])
	}
	rule := route["rules"].([]any)[0].(map[string]any)
	if rule["outbound"] != "hop2" {
		t.Fatalf("rule: %v", rule)
	}
	if problems := Validate(testPlan(plan.Hops...)); problems != "[]" {
		t.Fatalf("problems: %s", problems)
	}
}

func TestOpenConnectHostnameKeepsSNI(t *testing.T) {
	opts, err := openConnectOptions(&Profile{Proto: "openconnect", Server: "vpn.example.com"}, "5.6.7.8")
	if err != nil {
		t.Fatal(err)
	}
	if opts["server"] != "https://5.6.7.8:443" || opts["tls"].(map[string]any)["server_name"] != "vpn.example.com" {
		t.Fatalf("%v", opts)
	}
}

func TestUnsupportedProtocols(t *testing.T) {
	var problems []string
	_ = json.Unmarshal([]byte(Validate(testPlan(Profile{Name: "S", Proto: "sstp", Server: "h"}))), &problems)
	if len(problems) != 1 || !strings.Contains(problems[0], "SSTP") {
		t.Fatalf("%v", problems)
	}
}

const testOVPN = `client
dev tun
proto tcp
remote vpn.example.com 443
remote other.example.com 1194
auth-user-pass
cipher AES-256-CBC
key-direction 1
<ca>
-----BEGIN CERTIFICATE-----
CA
-----END CERTIFICATE-----
</ca>
<tls-auth>
KEY
</tls-auth>
`

func TestOpenVPN(t *testing.T) {
	c, err := parseOVPN(testOVPN)
	if err != nil {
		t.Fatal(err)
	}
	if c.remoteHost != "vpn.example.com" || c.remotePort != 443 || c.network != "tcp" {
		t.Fatalf("%+v", c)
	}
	opts, err := c.options(&Profile{Username: "u", Password: "p"}, "9.9.9.9")
	if err != nil {
		t.Fatal(err)
	}
	wrap := opts["tls"].(map[string]any)["control_wrap"].(map[string]any)
	if wrap["type"] != "tls_auth" || wrap["direction"] != "client" {
		t.Fatalf("wrap: %v", wrap)
	}
	if opts["server"] != "9.9.9.9" || opts["username"] != "u" || opts["data_ciphers_fallback"] != "AES-256-CBC" {
		t.Fatalf("%v", opts)
	}
}

func TestRoutes(t *testing.T) {
	r, err := parseRoutes("192.168.1.119, 10.0.0.0/8\n172.16.5.1/16")
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(r, " ") != "192.168.1.119/32 10.0.0.0/8 172.16.0.0/16" {
		t.Fatalf("%v", r)
	}
	if _, err := parseRoutes("fe80::1"); err == nil {
		t.Fatal("IPv6 должен отклоняться")
	}
}
