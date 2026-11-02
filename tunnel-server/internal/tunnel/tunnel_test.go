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

func TestSealAndOpenPacketWithObfuscatedHeader(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	salt := []byte("0123456789abcdef")
	rightPSK := DerivePSK("мастер-пароль-секрет", params)
	rightKey := DeriveKey("мастер-пароль-секрет", salt, params)
	wrongPSK := DerivePSK("чужой-мастер-пароль", params)
	wrongKey := DeriveKey("чужой-мастер-пароль", salt, params)

	payload := []byte("зашифрованный пакет данных через интерфейс")
	frame, err := SealPacketWithSeq(FrameTypeData, salt, rightPSK, rightKey, 7, payload)
	if err != nil {
		t.Fatalf("SealPacketWithSeq error: %v", err)
	}

	// На проводе нет открытых байт версии или типа кадра после 16-байтной соли
	fType, gotSalt, gotSeq, gotPlain, err := OpenPacketWithKey(frame, rightPSK, rightKey)
	if err != nil {
		t.Fatalf("OpenPacketWithKey error: %v", err)
	}
	if fType != FrameTypeData {
		t.Fatalf("expected FrameTypeData, got %d", fType)
	}
	if gotSeq != 7 {
		t.Fatalf("expected seq=7, got %d", gotSeq)
	}
	if !bytes.Equal(gotSalt, salt) {
		t.Fatalf("salt mismatch")
	}
	if !bytes.Equal(gotPlain, payload) {
		t.Fatalf("plaintext mismatch")
	}

	// Неверный мастер-пароль отсекается уже на проверке заголовка PSK без расшифровки тела
	if _, _, _, _, err := OpenPacketWithKey(frame, wrongPSK, wrongKey); err == nil {
		t.Fatalf("expected error with wrong PSK/key, got nil")
	}

	// Подмена любого байта в замаскированном заголовке ломает проверку заголовка / AAD
	tampered := append([]byte(nil), frame...)
	tampered[SaltSize+1] ^= 0x01
	if _, _, _, _, err := OpenPacketWithKey(tampered, rightPSK, rightKey); err == nil {
		t.Fatalf("expected error when masked header is tampered, got nil")
	}
}

func TestReplayWindow(t *testing.T) {
	var w ReplayWindow
	if w.Accept(0) {
		t.Fatal("seq 0 must be rejected")
	}
	if !w.Accept(1) || !w.Accept(2) || !w.Accept(65) {
		t.Fatal("fresh seq must be accepted")
	}
	// Повтор уже принятых номеров
	if w.Accept(1) || w.Accept(2) || w.Accept(65) {
		t.Fatal("replayed seq must be rejected")
	}
	// Пакет внутри окна [2..65], который ещё не приходил
	if !w.Accept(40) {
		t.Fatal("unseen seq inside window must be accepted")
	}
	if w.Accept(40) {
		t.Fatal("second time seq 40 must be rejected")
	}
	// Сдвиг окна далеко вперёд отсекает старые номера
	if !w.Accept(200) {
		t.Fatal("seq 200 must be accepted")
	}
	if w.Accept(100) {
		t.Fatal("seq 100 outside 64-packet window must be rejected")
	}
}

func TestServerHandshakePFSAndSecurityChecks(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	tunDev, tunReadFromSrv, tunWriteToSrv := newTestPipeTun()
	defer tunDev.Close()

	password := "общий-мастер-пароль-сервера"
	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:51820",
		TunName:        "argon0",
		SubnetCIDR:     "10.44.0.1/24",
		MasterPassword: password,
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
	attackerAddr := &net.UDPAddr{IP: net.IPv4(203, 0, 113, 99), Port: 55555}
	salt := []byte("client-salt-16b!")
	psk := DerivePSK(password, params)
	hsKey := DeriveKey(password, salt, params)

	// Попытка отправить Data-пакет без предварительного рукопожатия отбрасывается
	unauthData, _ := SealPacketWithSeq(FrameTypeData, salt, psk, hsKey, 1, []byte("unauth"))
	if resp := srv.HandlePacket(unauthData, clientAddr); resp != nil {
		t.Fatalf("expected nil on Data before handshake")
	}

	// 1. Рукопожатие (Handshake) с эфемерным X25519
	cliPriv, cliPub, err := GenerateX25519Keypair()
	if err != nil {
		t.Fatalf("GenerateX25519Keypair: %v", err)
	}
	clientAuth := ClientAuthToken(psk, "android-device-1", "secret-install-1")
	hsReq := HandshakeRequest{
		ClientID:   "android-device-1",
		ClientAuth: hex.EncodeToString(clientAuth),
		ClientPub:  hex.EncodeToString(cliPub),
		Apps:       []string{"com.example.contactsync"},
		Timestamp:  time.Now().Unix(),
	}
	hsReqBytes, _ := json.Marshal(hsReq)
	wireReq, err := SealPacketWithSeq(FrameTypeHandshakeReq, salt, psk, hsKey, 1, hsReqBytes)
	if err != nil {
		t.Fatalf("SealPacketWithSeq handshake: %v", err)
	}
	respWire := srv.HandlePacket(wireReq, clientAddr)
	if respWire == nil {
		t.Fatalf("Expected handshake response packet, got nil")
	}

	// Повтор (replay) того же пакета рукопожатия с другого адреса отклоняется и не угоняет сессию
	if replayResp := srv.HandlePacket(wireReq, attackerAddr); replayResp != nil {
		t.Fatalf("Expected replayed handshake to be rejected, got response")
	}

	fType, _, _, respPlain, err := OpenPacketWithKey(respWire, psk, hsKey)
	if err != nil {
		t.Fatalf("OpenPacketWithKey handshake response: %v", err)
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
	srvPub, err := hex.DecodeString(hsResp.ServerPub)
	if err != nil || len(srvPub) != X25519KeySize {
		t.Fatalf("invalid serverPub in response")
	}
	shared, err := ComputeX25519Shared(cliPriv, srvPub)
	if err != nil {
		t.Fatalf("ComputeX25519Shared: %v", err)
	}
	c2sKey, s2cKey := DeriveSessionKeys(hsKey, shared, salt)

	// 2. Клиент отправляет зашифрованный IPv4-пакет -> сервер проверяет src IP и пишет в TUN
	fakeIPv4 := make([]byte, 28)
	fakeIPv4[0] = 0x45 // IPv4, IHL=5
	copy(fakeIPv4[12:16], net.IPv4(10, 44, 0, 2).To4())
	copy(fakeIPv4[16:20], net.IPv4(10, 44, 0, 1).To4())
	copy(fakeIPv4[20:28], []byte("helloTUN"))

	wireData, err := SealPacketWithSeq(FrameTypeData, salt, psk, c2sKey, 1, fakeIPv4)
	if err != nil {
		t.Fatalf("SealPacketWithSeq data: %v", err)
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

	// Попытка злоумышленника повторить перехваченный Data-пакет со своего адреса (attackerAddr) не меняет RemoteAddr клиента
	srv.HandlePacket(wireData, attackerAddr)

	// Попытка подделать внутренний src IP (например, 10.44.0.99 вместо 10.44.0.2) отклоняется
	spoofedIPv4 := append([]byte(nil), fakeIPv4...)
	copy(spoofedIPv4[12:16], net.IPv4(10, 44, 0, 99).To4())
	wireSpoof, _ := SealPacketWithSeq(FrameTypeData, salt, psk, c2sKey, 2, spoofedIPv4)
	srv.HandlePacket(wireSpoof, clientAddr)

	// 3. Ответный IPv4-пакет из TUN сервера -> сервер шифрует ключом s2cKey и отправляет на настоящий clientAddr
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
		t.Fatalf("SealTunPacket failed or hijacked destination: got %v, want %v", dstAddr, clientAddr)
	}

	fType, _, replySeq, gotReply, err := OpenPacketWithKey(wireReply, psk, s2cKey)
	if err != nil {
		t.Fatalf("Client OpenPacketWithKey reply: %v", err)
	}
	if fType != FrameTypeData || replySeq != 1 || !bytes.Equal(gotReply, replyIPv4) {
		t.Fatalf("Client received reply mismatch")
	}
}
