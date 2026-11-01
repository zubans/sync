package tunnel

import (
	"bytes"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"testing"
	"time"
)

type mockPipeTun struct {
	r *io.PipeReader
	w *io.PipeWriter
}

func (m *mockPipeTun) Read(p []byte) (int, error)  { return m.r.Read(p) }
func (m *mockPipeTun) Write(p []byte) (int, error) { return m.w.Write(p) }
func (m *mockPipeTun) Close() error {
	_ = m.r.Close()
	_ = m.w.Close()
	return nil
}

func newTestPipeTun() (*mockPipeTun, *io.PipeReader, *io.PipeWriter) {
	inR, inW := io.Pipe()
	outR, outW := io.Pipe()
	return &mockPipeTun{r: inR, w: outW}, outR, inW
}

func TestArgon2idMatchesRFC9106AndAndroidReference(t *testing.T) {
	// Тот же эталонный вектор RFC 9106, что и в android/app/src/test/java/com/example/contactsync/vault/VaultCryptoTest.kt:
	// echo -n password | argon2 somesalt16bytes! -id -t 2 -k 64 -p 1 -l 32 -r
	params := ArgonParams{
		Time:    2,
		Memory:  64,
		Threads: 1,
	}
	key := DeriveKey("password", []byte("somesalt16bytes!"), params)
	got := hex.EncodeToString(key)
	want := "ac09230651f4855141b3cdfca3e77a7ab1a70d300bbd4a78cf9bb658333e4abe"
	if got != want {
		t.Fatalf("Argon2id mismatch:\ngot:  %s\nwant: %s", got, want)
	}
}

func TestSealAndOpenPacket(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	salt := []byte("0123456789abcdef")
	rightKey := DeriveKey("мастер-пароль-секрет", salt, params)
	wrongKey := DeriveKey("чужой-мастер-пароль", salt, params)

	payload := []byte("зашифрованный пакет данных через интерфейс")
	frame, err := SealPacket(FrameTypeData, salt, rightKey, payload)
	if err != nil {
		t.Fatalf("SealPacket error: %v", err)
	}

	fType, gotSalt, gotPlain, err := OpenPacket(frame, rightKey)
	if err != nil {
		t.Fatalf("OpenPacket error: %v", err)
	}
	if fType != FrameTypeData {
		t.Fatalf("expected FrameTypeData, got %d", fType)
	}
	if !bytes.Equal(gotSalt, salt) {
		t.Fatalf("salt mismatch")
	}
	if !bytes.Equal(gotPlain, payload) {
		t.Fatalf("plaintext mismatch")
	}

	// Неверный мастер-пароль не проходит аутентификацию GCM
	if _, _, _, err := OpenPacket(frame, wrongKey); err == nil {
		t.Fatalf("expected error with wrong key, got nil")
	}

	// Подмена типа кадра в заголовке ломает проверку AAD
	tampered := append([]byte(nil), frame...)
	tampered[1] = FrameTypeKeepalive
	if _, _, _, err := OpenPacket(tampered, rightKey); err == nil {
		t.Fatalf("expected error when header frameType is tampered, got nil")
	}
}

func TestServerHandshakeAndBidirectionalTunnel(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	tunDev, tunReadFromSrv, tunWriteToSrv := newTestPipeTun()
	defer tunDev.Close()

	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:51820",
		TunName:        "argon0",
		SubnetCIDR:     "10.44.0.1/24",
		MasterPassword: "общий-мастер-пароль-сервера",
		ArgonParams:    params,
		MTU:            1380,
		DNS:            []string{"1.1.1.1"},
		Routes:         []string{"0.0.0.0/0"},
	}, tunDev)
	if err != nil {
		t.Fatalf("NewServer: %v", err)
	}
	defer srv.Close()

	clientAddr := &net.UDPAddr{IP: net.IPv4(192, 168, 1, 50), Port: 41234}
	salt := []byte("client-salt-16b!")
	clientKey := DeriveKey("общий-мастер-пароль-сервера", salt, params)

	// 1. Рукопожатие (Handshake)
	hsReq := HandshakeRequest{
		ClientID:  "android-device-1",
		Apps:      []string{"com.example.contactsync"},
		Timestamp: time.Now().Unix(),
	}
	hsReqBytes, _ := json.Marshal(hsReq)
	wireReq, err := SealPacket(FrameTypeHandshakeReq, salt, clientKey, hsReqBytes)
	if err != nil {
		t.Fatalf("SealPacket handshake: %v", err)
	}
	respWire := srv.HandlePacket(wireReq, clientAddr)
	if respWire == nil {
		t.Fatalf("Expected handshake response packet, got nil")
	}

	fType, _, respPlain, err := OpenPacket(respWire, clientKey)
	if err != nil {
		t.Fatalf("OpenPacket handshake response: %v", err)
	}
	if fType != FrameTypeHandshakeResp {
		t.Fatalf("expected FrameTypeHandshakeResp, got %d", fType)
	}
	var hsResp HandshakeResponse
	if err := json.Unmarshal(respPlain, &hsResp); err != nil {
		t.Fatalf("Unmarshal handshake response: %v", err)
	}
	if hsResp.AssignedIP != "10.44.0.2" {
		t.Fatalf("expected assigned IP 10.44.0.2, got %s", hsResp.AssignedIP)
	}

	// 2. Клиент отправляет зашифрованный IPv4-пакет -> сервер расшифровывает и пишет в TUN
	fakeIPv4 := make([]byte, 28)
	fakeIPv4[0] = 0x45 // IPv4, IHL=5
	copy(fakeIPv4[12:16], net.IPv4(10, 44, 0, 2).To4())
	copy(fakeIPv4[16:20], net.IPv4(10, 44, 0, 1).To4())
	copy(fakeIPv4[20:28], []byte("helloTUN"))

	wireData, err := SealPacket(FrameTypeData, salt, clientKey, fakeIPv4)
	if err != nil {
		t.Fatalf("SealPacket data: %v", err)
	}
	go func() {
		srv.HandlePacket(wireData, clientAddr)
	}()

	tunBuf := make([]byte, 2048)
	tunN, err := tunReadFromSrv.Read(tunBuf)
	if err != nil {
		t.Fatalf("Read from server TUN: %v", err)
	}
	if !bytes.Equal(tunBuf[:tunN], fakeIPv4) {
		t.Fatalf("TUN received packet mismatch")
	}

	// 3. Ответный IPv4-пакет из TUN сервера -> сервер шифрует и отправляет клиенту
	replyIPv4 := make([]byte, 28)
	replyIPv4[0] = 0x45
	copy(replyIPv4[12:16], net.IPv4(10, 44, 0, 1).To4())
	copy(replyIPv4[16:20], net.IPv4(10, 44, 0, 2).To4())
	copy(replyIPv4[20:28], []byte("replyUDP"))

	go func() {
		_, _ = tunWriteToSrv.Write(replyIPv4)
	}()
	n, err := tunDev.Read(tunBuf)
	if err != nil {
		t.Fatalf("tunDev.Read: %v", err)
	}
	wireReply, dstAddr := srv.SealTunPacket(tunBuf[:n])
	if wireReply == nil || dstAddr.String() != clientAddr.String() {
		t.Fatalf("SealTunPacket failed or wrong destination: %v", dstAddr)
	}

	fType, _, gotReply, err := OpenPacket(wireReply, clientKey)
	if err != nil {
		t.Fatalf("Client OpenPacket reply: %v", err)
	}
	if fType != FrameTypeData || !bytes.Equal(gotReply, replyIPv4) {
		t.Fatalf("Client received reply mismatch")
	}
}
