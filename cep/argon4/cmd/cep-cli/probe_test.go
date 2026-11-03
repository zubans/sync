package main

import (
	"bytes"
	"net"
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
