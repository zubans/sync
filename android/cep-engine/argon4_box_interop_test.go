package cepcore

import (
	"context"
	"encoding/json"
	"net"
	"net/netip"
	"testing"
	"time"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/option"
	sjson "github.com/sagernet/sing/common/json"
	M "github.com/sagernet/sing/common/metadata"
	"github.com/sagernet/sing/service"
)

// TestArgon4BoxUDP проходит весь путь sing-box Outbound → packet-device → Argon4 →
// настоящий Go-server → echo-TUN и обратно. Android TUN здесь не нужен.
func TestArgon4BoxUDP(t *testing.T) {
	serverAddress := startArgonTestServer(t, "cep-box-secret", 0)
	config := map[string]any{
		"log": map[string]any{"level": "error"},
		"endpoints": []any{map[string]any{
			"type":          argon4Type,
			"tag":           "argon",
			"server":        serverAddress,
			"password":      "cep-box-secret",
			"client_id":     "cep-box-client",
			"client_secret": "owner-secret",
		}},
		"outbounds": []any{map[string]any{"type": "direct", "tag": "direct"}},
		"route":     map[string]any{"final": "argon"},
	}
	raw, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(engineContext(context.Background()))
	defer cancel()
	options, err := sjson.UnmarshalExtendedContext[option.Options](ctx, raw)
	if err != nil {
		t.Fatalf("разбор конфига: %v", err)
	}
	service.MustRegister[adapter.PlatformInterface](ctx, &platform{p: fakePlatform{}})
	instance, err := box.New(box.Options{Context: ctx, Options: options})
	if err != nil {
		t.Fatalf("box.New: %v", err)
	}
	defer instance.Close()
	if err := instance.Start(); err != nil {
		t.Fatalf("box.Start: %v", err)
	}
	manager := service.FromContext[adapter.OutboundManager](ctx)
	outbound, ok := manager.Outbound("argon")
	if !ok {
		t.Fatal("Argon endpoint не зарегистрирован в OutboundManager")
	}

	destination := M.SocksaddrFrom(netip.MustParseAddr("10.44.0.1"), 53)
	packetConn, err := outbound.ListenPacket(context.Background(), destination)
	if err != nil {
		t.Fatalf("ListenPacket: %v", err)
	}
	defer packetConn.Close()
	if err := packetConn.SetDeadline(time.Now().Add(5 * time.Second)); err != nil {
		t.Fatal(err)
	}
	payload := []byte("cep-over-argon")
	remote := &net.UDPAddr{IP: net.ParseIP("10.44.0.1"), Port: 53}
	if _, err := packetConn.WriteTo(payload, remote); err != nil {
		t.Fatalf("UDP WriteTo: %v", err)
	}
	buffer := make([]byte, 2048)
	n, _, err := packetConn.ReadFrom(buffer)
	if err != nil {
		t.Fatalf("UDP ReadFrom: %v", err)
	}
	if string(buffer[:n]) != string(payload) {
		t.Fatalf("echo payload: %q", buffer[:n])
	}
}
