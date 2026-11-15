package main

import (
	"bytes"
	"encoding/json"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestCheckPlanTreatsUDPAsSoftWarning(t *testing.T) {
	plan := &Plan{
		ChainName: "UDP",
		Hops: []Profile{{
			ID: "h1", Name: "Hysteria", Proto: "hysteria2",
			URI: "hy2://password@example.com:443",
		}},
		DNS: []string{"1.1.1.1"},
	}
	var output bytes.Buffer
	if err := checkPlan(plan, &output); err != nil {
		t.Fatal(err)
	}
	text := output.String()
	if !strings.Contains(text, "Синтаксис") || !strings.Contains(text, "ПРЕДУПРЕЖДЕНИЕ") || !strings.Contains(text, "не блокируют") {
		t.Fatalf("неожиданный результат проверки: %s", text)
	}
}

func TestProbeOpenConnectTCP(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	accepted := make(chan struct{})
	go func() {
		connection, acceptErr := listener.Accept()
		if acceptErr == nil {
			_ = connection.Close()
		}
		close(accepted)
	}()
	profile := Profile{Proto: "openconnect", Server: listener.Addr().String()}
	if result := probeProfile(profile); !strings.Contains(result, "доступен напрямую") {
		t.Fatalf("TCP-узел не распознан: %s", result)
	}
	<-accepted
	_ = listener.Close()

	profile.Server = listener.Addr().String()
	if result := probeProfile(profile); !strings.Contains(result, "ПРЕДУПРЕЖДЕНИЕ") {
		t.Fatalf("отказ подключения должен быть предупреждением: %s", result)
	}
}

func TestOpenVPNProtocolDetection(t *testing.T) {
	if !openVPNUsesUDP("client\nremote example.com 1194\n") {
		t.Fatal("UDP должен быть протоколом по умолчанию")
	}
	if openVPNUsesUDP("client\nproto udp\nremote example.com 443 tcp\n") {
		t.Fatal("протокол remote должен иметь приоритет")
	}
	if openVPNUsesUDP("client\nproto tcp-client\nremote example.com 443\n") {
		t.Fatal("TCP не должен считаться UDP")
	}
}

func TestRunProbeMTUCommandProfilesAndApply(t *testing.T) {
	ln, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		for {
			c, aerr := ln.Accept()
			if aerr != nil {
				return
			}
			_ = c.Close()
		}
	}()

	tmp := t.TempDir()
	profilesPath := filepath.Join(tmp, "profiles.json")
	store := &Store{
		Profiles: []Profile{
			{ID: "t1", Name: "Relay", Proto: "transit", Server: "127.0.0.1:51820", Password: "transit-secret", ArgonPathMTU: 1350},
			{ID: "n2", Name: "Exit", Proto: "openconnect", Server: ln.Addr().String()},
		},
		Chains: []Chain{{ID: "c1", Name: "Chain1", Hops: []string{"t1", "n2"}}},
	}
	if err := saveStore(profilesPath, store); err != nil {
		t.Fatal(err)
	}

	var out bytes.Buffer
	_, updatedStore, changed, err := runProbeMTUCommand(
		profilesPath, "c1", "n2", "",
		"", "", "", "", "", "", 0,
		true, true, &out,
	)
	if err != nil {
		t.Fatalf("runProbeMTUCommand: %v\n%s", err, out.String())
	}
	if !changed || updatedStore == nil {
		t.Fatalf("ожидалось обновление профиля с применённым MTU")
	}
	var res map[string]any
	if err := json.Unmarshal(bytes.TrimSpace(out.Bytes()), &res); err != nil {
		t.Fatalf("JSON результата: %v (%s)", err, out.String())
	}
	if ok, _ := res["ok"].(bool); !ok {
		t.Fatalf("ожидалось ok=true: %v", res)
	}
	if budget, _ := res["preceding_budget"].(float64); int(budget) != 1286 {
		t.Fatalf("ожидался preceding_budget=1286 (1350-64), получено %v", res)
	}
	if updatedStore.Profiles[1].ArgonPathMTU <= 0 || updatedStore.Profiles[1].ArgonPathMTU > 1286 {
		t.Fatalf("некорректный применённый MTU: %d", updatedStore.Profiles[1].ArgonPathMTU)
	}
	_ = os.Remove(profilesPath)
}
