package argon4

import (
	"bytes"
	"context"
	"encoding/binary"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"
)

func startGoServer(t *testing.T, password string) string {
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
		t.Fatalf("сборка настоящего Go-сервера: %v\n%s", err, output)
	}

	addrFile := filepath.Join(tempDir, "server.addr")
	command := exec.Command(serverBinary,
		"-listen", "127.0.0.1:0",
		"-password", password,
		"-addr-file", addrFile,
		"-drop-responses", "1",
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

// Проверяется настоящий tunnel-server: первый ответ handshake теряется,
// затем сервер возвращает зашифрованный echo IPv4-пакета.
func TestGoServerInterop(t *testing.T) {
	const password = "macos-cep-interop-secret"
	client, err := NewClient(ClientConfig{
		Server:         startGoServer(t, password),
		MasterPassword: password,
		ClientID:       "macos-cep-test-client",
		ClientSecret:   "owner-secret",
	})
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
	select {
	case result := <-readDone:
		if result.err != nil {
			t.Fatalf("data server→client: %v", result.err)
		}
		if !bytes.Equal(result.packet[12:16], net.ParseIP(response.ServerIP).To4()) ||
			!bytes.Equal(result.packet[16:20], net.ParseIP(response.AssignedIP).To4()) {
			t.Fatalf("неверный echo-пакет: %v", result.packet)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("таймаут data server→client")
	}
}
