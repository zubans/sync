package cepcore

import (
	"bytes"
	"context"
	"encoding/binary"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"testing"
	"time"

	"contactsync/cep-engine/argon4"
	M "github.com/sagernet/sing/common/metadata"
)

type directTestDialer struct{}

func (directTestDialer) DialContext(ctx context.Context, network string, destination M.Socksaddr) (net.Conn, error) {
	return (&net.Dialer{}).DialContext(ctx, network, destination.String())
}

func (directTestDialer) ListenPacket(ctx context.Context, _ M.Socksaddr) (net.PacketConn, error) {
	return (&net.ListenConfig{}).ListenPacket(ctx, "udp", ":0")
}

func startArgonTestServer(t *testing.T, password string, dropResponses int) string {
	t.Helper()
	serverRoot, err := filepath.Abs("../../tunnel-server")
	if err != nil {
		t.Fatal(err)
	}
	tempDir := t.TempDir()
	serverBinary := filepath.Join(tempDir, "argon-e2e-server")
	build := exec.Command("go", "build", "-o", serverBinary, "./internal/tunnel/testdata/e2e-server")
	build.Dir = serverRoot
	if output, err := build.CombinedOutput(); err != nil {
		t.Fatalf("сборка Go-сервера: %v\n%s", err, output)
	}

	addrFile := filepath.Join(tempDir, "server.addr")
	command := exec.Command(serverBinary,
		"-listen", "127.0.0.1:0",
		"-password", password,
		"-addr-file", addrFile,
		"-drop-responses", strconv.Itoa(dropResponses),
		"-echo-data",
	)
	var serverLog bytes.Buffer
	command.Stdout = &serverLog
	command.Stderr = &serverLog
	if err := command.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_ = command.Process.Kill()
		_ = command.Wait()
		if t.Failed() {
			t.Logf("журнал сервера:\n%s", serverLog.String())
		}
	})

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		if content, err := os.ReadFile(addrFile); err == nil && len(content) > 0 {
			return string(content)
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatal("Go-сервер не сообщил UDP-адрес")
	return ""
}

// TestArgon4GoServerInterop использует настоящий tunnel-server, а не его Go-копию в тесте.
// Первый ответ handshake теряется; echo-TUN сервера затем возвращает зашифрованный IPv4-пакет.
func TestArgon4GoServerInterop(t *testing.T) {
	serverAddress := startArgonTestServer(t, "cep-interop-secret", 1)
	client, err := argon4.NewClient(argon4.ClientConfig{
		Server:         serverAddress,
		MasterPassword: "cep-interop-secret",
		ClientID:       "cep-test-client",
		ClientSecret:   "owner-secret",
	}, directTestDialer{})
	if err != nil {
		t.Fatal(err)
	}
	defer client.Close()
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := client.Connect(ctx); err != nil {
		t.Fatalf("handshake с повтором: %v", err)
	}
	response := client.Response()
	if response.AssignedIP != "10.44.0.2" || response.ServerIP != "10.44.0.1" {
		t.Fatalf("ответ handshake: %+v", response)
	}

	packet := make([]byte, 20)
	packet[0] = 0x45
	binary.BigEndian.PutUint16(packet[2:4], uint16(len(packet)))
	packet[8] = 64
	packet[9] = 1
	copy(packet[12:16], net.ParseIP(response.AssignedIP).To4())
	copy(packet[16:20], net.ParseIP(response.ServerIP).To4())
	if err := client.WritePacket(packet); err != nil {
		t.Fatalf("data client→server: %v", err)
	}
	type readResult struct {
		packet []byte
		err    error
	}
	readDone := make(chan readResult, 1)
	go func() {
		packet, err := client.ReadPacket()
		readDone <- readResult{packet: packet, err: err}
	}()
	var echo []byte
	select {
	case result := <-readDone:
		if result.err != nil {
			t.Fatalf("data server→client: %v", result.err)
		}
		echo = result.packet
	case <-time.After(5 * time.Second):
		t.Fatal("таймаут data server→client")
	}
	if !bytes.Equal(echo[12:16], net.ParseIP(response.ServerIP).To4()) ||
		!bytes.Equal(echo[16:20], net.ParseIP(response.AssignedIP).To4()) {
		t.Fatalf("неверный echo-пакет: %v", echo)
	}
}
