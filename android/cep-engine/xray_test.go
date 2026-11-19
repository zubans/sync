package cepcore

import (
	"bytes"
	"encoding/json"
	"net"
	"strings"
	"testing"

	M "github.com/sagernet/sing/common/metadata"
	"github.com/xtls/xray-core/infra/conf/serial"
)

const xhttpLink = "vless://11111111-2222-3333-4444-555555555555@pool.example.com:443?encryption=none&type=xhttp&path=%2Fp&host=pool.example.com&mode=auto" +
	"&extra=%7B%22xmux%22%3A%7B%22maxConcurrency%22%3A%2216-32%22%7D%2C%22xPaddingBytes%22%3A%22100-1000%22%7D&security=tls&sni=pool.example.com&fp=chrome&alpn=h2#X"

func TestVLESSHopGoesThroughXrayViaPreviousHop(t *testing.T) {
	plan := &Plan{ChainName: "c", Hops: []Profile{
		{Name: "HY", Proto: "hysteria2", URI: "hy2://pw@first.example.com:443"},
		{Name: "XHTTP", Proto: "vless", URI: xhttpLink},
	}}
	config, err := buildConfig(plan, []string{"1.1.1.1", "2.2.2.2"})
	if err != nil {
		t.Fatal(err)
	}
	xcfg, err := moveVLESSToXray(config, plan)
	if err != nil || xcfg == nil {
		t.Fatalf("xray-конфиг не построен: %v", err)
	}
	var hop2 map[string]any
	for _, o := range config["outbounds"].([]any) {
		if m := o.(map[string]any); m["tag"] == "hop2" {
			hop2 = m
		}
	}
	if hop2["type"] != "socks" || hop2["server"] != "127.0.0.1" || hop2["detour"] != nil {
		t.Fatalf("узел VLESS в sing-box должен стать SOCKS к xray: %v", hop2)
	}
	rules := config["route"].(map[string]any)["rules"].([]any)
	first := rules[0].(map[string]any)
	if first["outbound"] != "hop1" || first["inbound"].([]string)[0] != "via-hop2" {
		t.Fatalf("xray должен выходить через предыдущий узел первым правилом: %v", first)
	}
	raw, _ := json.Marshal(xcfg)
	text := string(raw)
	for _, want := range []string{`"dialerProxy":"via-hop2"`, `"network":"xhttp"`, `"xPaddingBytes":"100-1000"`, `"address":"2.2.2.2"`} {
		if !strings.Contains(text, want) {
			t.Errorf("в конфиге xray нет %s: %s", want, text)
		}
	}
	// конфиг должен приниматься самим xray
	if _, err := serial.LoadJSONConfig(bytes.NewReader(raw)); err != nil {
		t.Fatalf("xray не принял конфиг: %v", err)
	}
}

func TestFirstVLESSHopDialsDirectly(t *testing.T) {
	plan := &Plan{ChainName: "c", Hops: []Profile{{Name: "V", Proto: "vless", URI: xhttpLink}}}
	config, err := buildConfig(plan, []string{"2.2.2.2"})
	if err != nil {
		t.Fatal(err)
	}
	xcfg, err := moveVLESSToXray(config, plan)
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := json.Marshal(xcfg)
	if strings.Contains(string(raw), "dialerProxy") {
		t.Fatalf("первый узел выходит в сеть сам (сокет защищается protect): %s", raw)
	}
}

func TestXrayGrpcMultiAuthorityAndRealityPQ(t *testing.T) {
	l, _ := parseLink("vless://u@q.example.com:443?type=grpc&serviceName=svc&mode=multi&authority=a.example.com&security=tls&sni=q.example.com&alpn=h2")
	out, err := xrayVLESSOutbound(l, "3.3.3.3", 443)
	if err != nil {
		t.Fatal(err)
	}
	grpc := out["streamSettings"].(map[string]any)["grpcSettings"].(map[string]any)
	if grpc["multiMode"] != true || grpc["authority"] != "a.example.com" {
		t.Fatalf("gRPC: %v", grpc)
	}
	l, _ = parseLink("vless://u@r.example.com:443?type=tcp&security=reality&flow=xtls-rprx-vision&pbk=KEY&sid=ab&sni=www.example.com&pqv=PQ")
	out, err = xrayVLESSOutbound(l, "4.4.4.4", 443)
	if err != nil {
		t.Fatal(err)
	}
	reality := out["streamSettings"].(map[string]any)["realitySettings"].(map[string]any)
	if reality["mldsa65Verify"] != "PQ" || reality["serverName"] != "www.example.com" {
		t.Fatalf("Reality: %v", reality)
	}
}

func TestChainWithoutVLESSHasNoXray(t *testing.T) {
	plan := &Plan{ChainName: "c", Hops: []Profile{{Name: "HY", Proto: "hysteria2", URI: "hy2://pw@h.example.com:443"}}}
	config, _ := buildConfig(plan, []string{"1.1.1.1"})
	if xcfg, err := moveVLESSToXray(config, plan); err != nil || xcfg != nil {
		t.Fatalf("xray не нужен: %v %v", xcfg, err)
	}
}

// OpenConnect за VLESS: RemoteAddr соединения через SOCKS к xray должен быть сервером, а не 127.0.0.1.
func TestDeadlineConnKeepsRealRemoteAddr(t *testing.T) {
	a, b := net.Pipe()
	defer a.Close()
	defer b.Close()
	c := newDeadlineConn(a, M.ParseSocksaddrHostPort("94.103.9.172", 4443))
	if got := c.RemoteAddr().String(); got != "94.103.9.172:4443" {
		t.Fatalf("RemoteAddr = %s", got)
	}
	if got := newDeadlineConn(a, M.ParseSocksaddrHostPort("vpn.example.com", 443)).RemoteAddr(); got != a.RemoteAddr() {
		t.Fatalf("для имени остаётся адрес соединения: %v", got)
	}
}
