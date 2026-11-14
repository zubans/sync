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

func TestArgon4ChainAndValidation(t *testing.T) {
	profile := Profile{ID: "profile-id", Name: "Argon", Proto: "argon4", Server: "argon.example.com", Password: "secret", ClientSecret: "owner"}
	plan, err := parsePlan(testPlan(
		Profile{Name: "VLESS", Proto: "vless", URI: "vless://u@example.com:443?type=grpc&security=tls"},
		profile,
	))
	if err != nil {
		t.Fatal(err)
	}
	config, err := buildConfig(plan, []string{"1.2.3.4", "5.6.7.8"})
	if err != nil {
		t.Fatal(err)
	}
	endpoints := config["endpoints"].([]any)
	ep := endpoints[0].(map[string]any)
	if ep["type"] != argon4Type || ep["server"] != "5.6.7.8:51820" || ep["detour"] != "hop1-deadline" {
		t.Fatalf("endpoint: %v", ep)
	}
	if ep["client_id"] != "profile-id" || ep["client_secret"] != "owner" {
		t.Fatalf("client: %v", ep)
	}
	if problems := Validate(testPlan(profile)); problems != "[]" {
		t.Fatalf("problems: %s", problems)
	}
	profile.Password = ""
	if problems := Validate(testPlan(profile)); !strings.Contains(problems, "мастер-пароль") {
		t.Fatalf("problems: %s", problems)
	}
}

func TestArgon4ServerForms(t *testing.T) {
	for input, expected := range map[string]string{
		"argon.example.com":            "argon.example.com:51820",
		"argon.example.com:1234":       "argon.example.com:1234",
		"udp://argon.example.com:1234": "argon.example.com:1234",
		"argon4://argon.example.com":   "argon.example.com:51820",
		"192.0.2.1":                    "192.0.2.1:51820",
		"2001:db8::1":                  "2001:db8::1:51820",
	} {
		host, port, err := parseArgon4Server(input)
		if err != nil || host+":"+port != expected {
			t.Fatalf("%q: host=%q port=%q err=%v", input, host, port, err)
		}
	}
	for _, input := range []string{"", "https://argon.example.com", "host:0", "host:65536"} {
		if _, _, err := parseArgon4Server(input); err == nil {
			t.Fatalf("%q должен отклоняться", input)
		}
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

func TestTransitValidationAndConfig(t *testing.T) {
	var problems []string
	_ = json.Unmarshal([]byte(Validate(testPlan(Profile{Name: "T", Proto: "transit", Server: "relay.example.com", Password: "p"}))), &problems)
	if len(problems) == 0 {
		t.Fatal("последний узел transit должен отклоняться")
	}

	problems = nil
	_ = json.Unmarshal([]byte(Validate(testPlan(
		Profile{Name: "Blind", Proto: "transit", Server: "relay.example.com"},
		Profile{Name: "V", Proto: "vless", URI: "vless://uuid@example.com:443?security=tls"},
	))), &problems)
	if len(problems) == 0 {
		t.Fatal("слепой транзит перед простым VLESS должен отклоняться")
	}

	problems = nil
	_ = json.Unmarshal([]byte(Validate(testPlan(
		Profile{Name: "T", Proto: "transit", Server: "relay.example.com", Password: "p", UseArgon: true, ArgonPassword: "x"},
		Profile{Name: "A", Proto: "argon4", Server: "vpn.example.com", Password: "p"},
	))), &problems)
	if len(problems) == 0 {
		t.Fatal("useArgon на transit должен отклоняться")
	}

	// Слепой транзит + VLESS с useArgon (без argonServer)
	blindPlanJSON := testPlan(
		Profile{Name: "Blind", Proto: "transit", Server: "relay.example.com"},
		Profile{Name: "V", Proto: "vless", URI: "vless://11111111-2222-3333-4444-555555555555@example.com:8443?security=tls", UseArgon: true, ArgonPassword: "overlay-secret"},
	)
	problems = nil
	_ = json.Unmarshal([]byte(Validate(blindPlanJSON)), &problems)
	if len(problems) > 0 {
		t.Fatalf("слепой транзит + useArgon должен быть валидным: %v", problems)
	}
	plan, err := parsePlan(blindPlanJSON)
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := buildConfig(plan, []string{"1.1.1.1", "2.2.2.2"})
	if err != nil {
		t.Fatal(err)
	}
	eps := cfg["endpoints"].([]any)
	underlay := eps[0].(map[string]any)
	if underlay["tag"] != "hop2-argon" || underlay["server"] != "1.1.1.1:51820" || underlay["mode"] != "auto" || underlay["target"] != "2.2.2.2:8443" || underlay["proto"] != "tcp" {
		t.Fatalf("неожиданный underlay: %v", underlay)
	}
	vlessOut := cfg["outbounds"].([]any)[0].(map[string]any)
	if vlessOut["detour"] != "hop2-argon" {
		t.Fatalf("VLESS должен идти через hop2-argon: %v", vlessOut)
	}

	// Авторизованный транзит + OpenConnect
	ocPlanJSON := testPlan(
		Profile{ID: "tr1", Name: "AuthT", Proto: "transit", Server: "relay.example.com:55182", Password: "tpass"},
		Profile{Name: "OC", Proto: "openconnect", Server: "https://vpn.example.com:4443", Username: "u", Password: "p"},
	)
	plan, err = parsePlan(ocPlanJSON)
	if err != nil {
		t.Fatal(err)
	}
	cfg, err = buildConfig(plan, []string{"1.1.1.1", "3.3.3.3"})
	if err != nil {
		t.Fatal(err)
	}
	eps = cfg["endpoints"].([]any)
	tEp := eps[0].(map[string]any)
	ocEp := eps[1].(map[string]any)
	if tEp["mode"] != "transit" || tEp["target"] != "3.3.3.3:4443" || tEp["proto"] != "tcp" || tEp["server"] != "1.1.1.1:55182" {
		t.Fatalf("неожиданный transit endpoint: %v", tEp)
	}
	if ocEp["detour"] != "hop1" || ocEp["no_udp"] != true {
		t.Fatalf("неожиданный OpenConnect endpoint: %v", ocEp)
	}

	redacted := redact(map[string]any{"argonPassword": "secret", "password": "p"}).(map[string]any)
	if redacted["argonPassword"] != "***" || redacted["password"] != "***" {
		t.Fatalf("redact не скрыл пароль: %v", redacted)
	}
}

func TestArgon4V5OptionsPropagateToEndpointConfig(t *testing.T) {
	plan, err := parsePlan(testPlan(Profile{
		ID:                             "a1",
		Name:                           "Argon v5",
		Proto:                          "argon4",
		Server:                         "argon.example.com",
		Password:                       "secret",
		ArgonProtocolVersion:           5,
		ArgonPaddingProfile:            "wide",
		ArgonFirstPacketProfile:        "compact",
		ArgonPathMTU:                   1420,
		ArgonHandshakeMaxAttempts:      6,
		ArgonHandshakeInitialTimeoutMs: 900,
		ArgonHandshakeMaxTimeoutMs:     3600,
		ArgonKeepaliveMinIdleSec:       16,
		ArgonKeepaliveMaxIdleSec:       26,
	}))
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := buildConfig(plan, []string{"1.2.3.4"})
	if err != nil {
		t.Fatal(err)
	}
	ep := cfg["endpoints"].([]any)[0].(map[string]any)
	if ep["protocol_version"] != float64(5) && ep["protocol_version"] != 5 {
		t.Fatalf("ожидалась protocol_version=5, получено %v", ep["protocol_version"])
	}
	if ep["padding_profile"] != "wide" || ep["first_packet_profile"] != "compact" {
		t.Fatalf("не переданы профили padding: %v", ep)
	}
	if ep["path_mtu"] != float64(1420) && ep["path_mtu"] != 1420 {
		t.Fatalf("не передан path_mtu=1420: %v", ep["path_mtu"])
	}
	if ep["handshake_max_attempts"] != float64(6) && ep["handshake_max_attempts"] != 6 {
		t.Fatalf("не передан handshake_max_attempts=6: %v", ep["handshake_max_attempts"])
	}
	if ep["handshake_initial_timeout_ms"] != float64(900) && ep["handshake_initial_timeout_ms"] != 900 {
		t.Fatalf("не передан handshake_initial_timeout_ms=900: %v", ep["handshake_initial_timeout_ms"])
	}
	if ep["handshake_max_timeout_ms"] != float64(3600) && ep["handshake_max_timeout_ms"] != 3600 {
		t.Fatalf("не передан handshake_max_timeout_ms=3600: %v", ep["handshake_max_timeout_ms"])
	}
	if ep["keepalive_min_idle_sec"] != float64(16) && ep["keepalive_min_idle_sec"] != 16 {
		t.Fatalf("не передан keepalive_min_idle_sec=16: %v", ep["keepalive_min_idle_sec"])
	}
	if ep["keepalive_max_idle_sec"] != float64(26) && ep["keepalive_max_idle_sec"] != 26 {
		t.Fatalf("не передан keepalive_max_idle_sec=26: %v", ep["keepalive_max_idle_sec"])
	}
}

func TestBehaviorProfilePlanValidationAndPropagation(t *testing.T) {
	// v4 + профиль должен отклоняться на Validate
	badV4 := testPlan(Profile{
		ID: "a1", Name: "Argon", Proto: "argon4", Server: "1.2.3.4", Password: "sec",
		ArgonProtocolVersion: 4, ArgonBehaviorProfile: "baseline",
	})
	if res := Validate(badV4); res == "[]" {
		t.Fatalf("ожидалась ошибка Validate для v4+profile, получено %s", res)
	}

	// video-call поверх транзита на TCP (vless) должен отклоняться
	badTransit := mustJSON(Plan{
		ChainName: "bad",
		Hops: []Profile{
			{ID: "t1", Name: "Relay", Proto: "transit", Server: "1.2.3.4:51820", Password: "sec", ArgonBehaviorProfile: "video-call"},
			{ID: "v1", Name: "VLESS", Proto: "vless", URI: "vless://11111111-1111-1111-1111-111111111111@5.6.7.8:443#v"},
		},
		DNS: []string{"1.1.1.1"},
	})
	if res := Validate(badTransit); res == "[]" {
		t.Fatalf("ожидалась ошибка Validate для transit(video-call)->vless(tcp), получено %s", res)
	}

	// Валидный video-call транзит на UDP-узел (hysteria2) и распространение полей в buildConfig
	goodPlanJSON := mustJSON(Plan{
		ChainName: "good",
		Hops: []Profile{
			{
				ID: "t1", Name: "Relay", Proto: "transit", Server: "1.2.3.4:51820", Password: "sec",
				ArgonBehaviorProfile: "video-call", ArgonLowPowerMode: true, ArgonVideoCallMaxDurationSec: 1800,
			},
			{ID: "h1", Name: "HY2", Proto: "hysteria2", URI: "hysteria2://pass@5.6.7.8:443#h"},
		},
		DNS: []string{"1.1.1.1"},
	})
	if res := Validate(goodPlanJSON); res != "[]" {
		t.Fatalf("ожидалась чистая валидация UDP-цели, получено %s", res)
	}
	plan, err := parsePlan(goodPlanJSON)
	if err != nil {
		t.Fatal(err)
	}
	cfg, err := buildConfig(plan, []string{"1.2.3.4", "5.6.7.8"})
	if err != nil {
		t.Fatal(err)
	}
	ep := cfg["endpoints"].([]any)[0].(map[string]any)
	if ep["behavior_profile"] != "video-call" || ep["low_power_mode"] != true {
		t.Fatalf("поведенческий профиль не попал в endpoint конфиг: %v", ep)
	}
}
