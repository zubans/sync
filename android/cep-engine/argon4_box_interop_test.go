package cepcore

import (
	"context"
	"encoding/json"
	"io"
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

func TestArgon4TransitBoxTCPAndUDP(t *testing.T) {
	tcpLn, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcpLn.Close()
	go func() {
		for {
			c, err := tcpLn.Accept()
			if err != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				_, _ = io.Copy(conn, conn)
			}(c)
		}
	}()

	udpEcho, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer udpEcho.Close()
	go func() {
		buf := make([]byte, 4096)
		for {
			n, addr, err := udpEcho.ReadFrom(buf)
			if err != nil {
				return
			}
			_, _ = udpEcho.WriteTo(buf[:n], addr)
		}
	}()

	serverAddress := startArgonTestServerWithMode(t, "cep-transit-secret", 0, "transit", tcpLn.Addr().String()+","+udpEcho.LocalAddr().String())
	config := map[string]any{
		"log": map[string]any{"level": "error"},
		"endpoints": []any{
			map[string]any{
				"type":          argon4Type,
				"tag":           "transit-tcp",
				"server":        serverAddress,
				"password":      "cep-transit-secret",
				"client_id":     "cep-transit-tcp",
				"client_secret": "owner-secret",
				"mode":          "auto",
				"target":        tcpLn.Addr().String(),
				"proto":         "tcp",
			},
			map[string]any{
				"type":          argon4Type,
				"tag":           "transit-udp",
				"server":        serverAddress,
				"password":      "cep-transit-secret",
				"client_id":     "cep-transit-udp",
				"client_secret": "owner-secret",
				"mode":          "transit",
				"target":        udpEcho.LocalAddr().String(),
				"proto":         "udp",
			},
		},
		"outbounds": []any{map[string]any{"type": "direct", "tag": "direct"}},
		"route":     map[string]any{"final": "transit-tcp"},
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
	tcpOut, ok := manager.Outbound("transit-tcp")
	if !ok {
		t.Fatal("transit-tcp endpoint не зарегистрирован")
	}
	tcpDest := M.ParseSocksaddr(tcpLn.Addr().String())
	tcpConn, err := tcpOut.DialContext(context.Background(), "tcp", tcpDest)
	if err != nil {
		t.Fatalf("DialContext TCP: %v", err)
	}
	defer tcpConn.Close()
	_ = tcpConn.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := tcpConn.Write([]byte("transit-tcp-hello")); err != nil {
		t.Fatal(err)
	}
	if cw, ok := tcpConn.(interface{ CloseWrite() error }); ok {
		_ = cw.CloseWrite()
	}
	echoed, err := io.ReadAll(tcpConn)
	if err != nil || string(echoed) != "transit-tcp-hello" {
		t.Fatalf("TCP transit echo = %q, %v", echoed, err)
	}

	udpOut, ok := manager.Outbound("transit-udp")
	if !ok {
		t.Fatal("transit-udp endpoint не зарегистрирован")
	}
	udpDest := M.ParseSocksaddr(udpEcho.LocalAddr().String())
	pktConn, err := udpOut.ListenPacket(context.Background(), udpDest)
	if err != nil {
		t.Fatalf("ListenPacket UDP: %v", err)
	}
	defer pktConn.Close()
	_ = pktConn.SetDeadline(time.Now().Add(5 * time.Second))
	if _, err := pktConn.WriteTo([]byte("transit-udp-hello"), udpDest.UDPAddr()); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 2048)
	n, _, err := pktConn.ReadFrom(buf)
	if err != nil || string(buf[:n]) != "transit-udp-hello" {
		t.Fatalf("UDP transit echo = %q, %v", buf[:n], err)
	}
}
