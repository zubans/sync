package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func buildIPv4UDPPacket(srcIP, dstIP net.IP, payloadLen int) []byte {
	if payloadLen < 28 {
		payloadLen = 28
	}
	pkt := make([]byte, payloadLen)
	pkt[0] = 0x45
	pkt[2] = byte(payloadLen >> 8)
	pkt[3] = byte(payloadLen)
	pkt[8] = 64
	pkt[9] = 17
	copy(pkt[12:16], srcIP.To4())
	copy(pkt[16:20], dstIP.To4())
	return pkt
}

func TestResolveTransportOptionsValidation(t *testing.T) {
	if _, err := NewClient(ClientConfig{
		Server:          "127.0.0.1:51820",
		MasterPassword:  "pw",
		ProtocolVersion: 3,
	}); !errors.Is(err, ErrBadVersion) {
		t.Fatalf("ожидался отказ для неподдерживаемой версии: %v", err)
	}
	if _, err := NewClient(ClientConfig{
		Server:         "127.0.0.1:51820",
		MasterPassword: "pw",
		Padding:        &PaddingConfig{Profile: PaddingProfileCustom, MinPad: 32, MaxPad: 32},
	}); err == nil {
		t.Fatal("ожидался отказ при вырожденной фиксированной длине padding")
	}
	if _, err := NewClient(ClientConfig{
		Server:         "127.0.0.1:51820",
		MasterPassword: "pw",
		PathMTU:        300,
	}); !errors.Is(err, ErrMTUExceeded) {
		t.Fatalf("ожидался отказ по path MTU: %v", err)
	}
	if _, err := NewClient(ClientConfig{
		Server:             "127.0.0.1:51820",
		MasterPassword:     "pw",
		FirstPacketProfile: "unknown",
	}); err == nil {
		t.Fatal("ожидался отказ для неизвестного first-packet профиля")
	}
}

func TestEnvelopeV5ValidationAndRandomPadding(t *testing.T) {
	psk := DerivePSK("test-secret", DefaultParams)
	key := make([]byte, KeySize)
	salt := make([]byte, SaltSize)
	_, _ = rand.Read(key)
	_, _ = rand.Read(salt)

	payload := bytes.Repeat([]byte{0x45}, 64)
	cfg := ResolvePaddingConfig(PaddingConfig{Profile: PaddingProfileBalanced}, true)
	seenSizes := make(map[int]struct{})
	for i := 0; i < 32; i++ {
		wire, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, uint64(i+1), payload, SealOptions{
			Version:       ProtocolVersionV5,
			Padding:       cfg,
			MaxOuterBytes: DefaultMaxOuterUDPSize,
		})
		if err != nil {
			t.Fatalf("SealPacketWithOptions(%d): %v", i, err)
		}
		if len(wire) > DefaultMaxOuterUDPSize {
			t.Fatalf("превышен UDP MTU: %d > %d", len(wire), DefaultMaxOuterUDPSize)
		}
		minOuter := OverheadV5 + len(payload) + cfg.SmallMinPad
		maxOuter := OverheadV5 + len(payload) + cfg.SmallMaxPad
		if len(wire) < minOuter || len(wire) > maxOuter {
			t.Fatalf("размер %d вне [%d..%d]", len(wire), minOuter, maxOuter)
		}
		seenSizes[len(wire)] = struct{}{}
	}
	if len(seenSizes) < 4 {
		t.Fatalf("ожидалась переменная длина шифртекста, получено %d вариантов", len(seenSizes))
	}

	// Повреждение padding (ненулевой байт), trailing bytes и несогласованные длины внутри конверта.
	env, err := PackEnvelopeV5([]byte("abc"), 8, DefaultMaxOuterUDPSize)
	if err != nil {
		t.Fatal(err)
	}
	badPad := append([]byte(nil), env...)
	badPad[len(badPad)-1] = 0x7f
	if _, err := UnpackEnvelopeV5(badPad, 1500); !errors.Is(err, ErrInvalidPadding) {
		t.Fatalf("ожидался ErrInvalidPadding, получено %v", err)
	}
	trailing := append(append([]byte(nil), env...), 0x00)
	if _, err := UnpackEnvelopeV5(trailing, 1500); !errors.Is(err, ErrTrailingData) {
		t.Fatalf("ожидался ErrTrailingData, получено %v", err)
	}
	badLen := append([]byte(nil), env...)
	badLen[3] = 0x50
	if _, err := UnpackEnvelopeV5(badLen, 1500); !errors.Is(err, ErrInvalidEnvelope) {
		t.Fatalf("ожидался ErrInvalidEnvelope, получено %v", err)
	}
	mtuBadLen := append([]byte(nil), env...)
	mtuBadLen[0] = 0x10
	if _, err := UnpackEnvelopeV5(mtuBadLen, 1500); !errors.Is(err, ErrMTUExceeded) {
		t.Fatalf("ожидался ErrMTUExceeded, получено %v", err)
	}

	// Попытка downgrade v4 -> v5 отклоняется.
	v4Wire, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, 1, payload, SealOptions{
		Version:       ProtocolVersionV4,
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := OpenPacketWithOptions(v4Wire, psk, key, OpenOptions{
		ExpectedVersion: ProtocolVersionV5,
		MaxOuterBytes:   DefaultMaxOuterUDPSize,
	}); !errors.Is(err, ErrDowngradeRejected) {
		t.Fatalf("ожидался ErrDowngradeRejected, получено %v", err)
	}
}

func buildTestE2EServer(t *testing.T) string {
	t.Helper()
	bin := filepath.Join(t.TempDir(), "e2e-server")
	serverDir, err := filepath.Abs(filepath.Join("..", "..", "tunnel-server"))
	if err != nil {
		t.Fatal(err)
	}
	cmd := exec.Command("go", "build", "-o", bin, "./internal/tunnel/testdata/e2e-server")
	cmd.Dir = serverDir
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("сборка e2e-server: %v\n%s", err, out)
	}
	return bin
}

func startTestE2EServer(t *testing.T, bin string, extraArgs ...string) (addr string, stop func()) {
	t.Helper()
	addrFile := filepath.Join(t.TempDir(), "addr.txt")
	args := append([]string{"-listen", "127.0.0.1:0", "-password", "shared-secret", "-addr-file", addrFile}, extraArgs...)
	ctx, cancel := context.WithCancel(context.Background())
	cmd := exec.CommandContext(ctx, bin, args...)
	var stderr bytes.Buffer
	cmd.Stderr = &stderr
	if err := cmd.Start(); err != nil {
		cancel()
		t.Fatal(err)
	}

	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		if data, err := os.ReadFile(addrFile); err == nil {
			if value := strings.TrimSpace(string(data)); value != "" {
				return value, func() {
					cancel()
					_ = cmd.Wait()
				}
			}
		}
		time.Sleep(25 * time.Millisecond)
	}
	cancel()
	_ = cmd.Wait()
	t.Fatalf("e2e-server не запустился: %s", stderr.String())
	return "", nil
}

func TestE2EV5PaddingObfuscationRetryAndV4Compat(t *testing.T) {
	bin := buildTestE2EServer(t)
	wireLog := filepath.Join(t.TempDir(), "wire.log")

	addr, stop := startTestE2EServer(
		t, bin,
		"-drop-responses", "1",
		"-echo-data",
		"-protocol-version", "5",
		"-allow-v4=true",
		"-padding-profile", PaddingProfileBalanced,
		"-wire-log", wireLog,
	)
	defer stop()

	// Клиент v5 с профилем wide на первый пакет и retry (handshakeCache должен вернуть кэшированный ответ).
	client, err := NewClient(ClientConfig{
		Server:             addr,
		MasterPassword:     "shared-secret",
		ClientID:           "cep-v5",
		ClientSecret:       "owner-v5",
		PaddingProfile:     PaddingProfileBalanced,
		FirstPacketProfile: PaddingProfileWide,
		PathMTU:            1500,
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := client.Connect(ctx); err != nil {
		t.Fatalf("v5 Connect (с retry): %v", err)
	}
	resp := client.Response()
	if resp.ProtocolVersion != int(ProtocolVersionV5) {
		t.Fatalf("ожидалась версия 5, получено %d", resp.ProtocolVersion)
	}
	if resp.MTU > DefaultMaxOuterUDPSize-OverheadV5 {
		t.Fatalf("MTU клиента %d не ограничен path MTU", resp.MTU)
	}

	src := net.ParseIP(resp.AssignedIP).To4()
	dst := net.ParseIP(resp.ServerIP).To4()
	if dst == nil {
		dst = net.IPv4(10, 44, 0, 1)
	}
	pkt := buildIPv4UDPPacket(src, dst, 64)
	for i := 0; i < 16; i++ {
		if err := client.WritePacket(pkt); err != nil {
			t.Fatalf("WritePacket(%d): %v", i, err)
		}
		got, err := client.ReadPacket()
		if err != nil {
			t.Fatalf("ReadPacket(%d): %v", i, err)
		}
		if len(got) != len(pkt) {
			t.Fatalf("ожидался payload %d байт, получено %d", len(pkt), len(got))
		}
		if err := client.WriteKeepalive(); err != nil {
			t.Fatalf("WriteKeepalive(%d): %v", i, err)
		}
	}
	_ = client.Close()

	// Проверяем лог длины кадров v5 на проводе до запуска v4-клиента:
	// 1) handshake retry отправляет байт-в-байт идентичный кадр (rx[0] == rx[1]);
	// 2) одинаковые 64-байтные data-пакеты имеют переменную длину >= OverheadV5+64+16 и не превышают UDP MTU.
	rawLog, err := os.ReadFile(wireLog)
	if err != nil {
		t.Fatal(err)
	}
	var rxHandshakes []int
	v5DataSizes := make(map[int]struct{})
	minDataOuter := OverheadV5 + len(pkt) + 16
	maxDataOuter := OverheadV5 + len(pkt) + 192
	for _, line := range strings.Split(strings.TrimSpace(string(rawLog)), "\n") {
		parts := strings.Fields(line)
		if len(parts) != 2 {
			continue
		}
		dir := parts[0]
		n, _ := strconv.Atoi(parts[1])
		if n > DefaultMaxOuterUDPSize {
			t.Fatalf("кадр на проводе %d превысил UDP path MTU %d", n, DefaultMaxOuterUDPSize)
		}
		if dir == "rx" && len(rxHandshakes) < 2 {
			rxHandshakes = append(rxHandshakes, n)
			continue
		}
		if dir == "tx" && n >= minDataOuter && n <= maxDataOuter {
			v5DataSizes[n] = struct{}{}
		}
	}
	if len(rxHandshakes) != 2 || rxHandshakes[0] != rxHandshakes[1] {
		t.Fatalf("повтор v5 handshake должен отправлять идентичные байты кэшируемого кадра, получено %v", rxHandshakes)
	}
	if len(v5DataSizes) < 4 {
		t.Fatalf("v5 data-пакеты должны иметь переменную длину на проводе, получено %v", v5DataSizes)
	}

	// Проверка совместимости v4 при allow-v4=true.
	v4Client, err := NewClient(ClientConfig{
		Server:          addr,
		MasterPassword:  "shared-secret",
		ClientID:        "cep-v4",
		ClientSecret:    "owner-v4",
		ProtocolVersion: int(ProtocolVersionV4),
	})
	if err != nil {
		t.Fatal(err)
	}
	if err := v4Client.Connect(ctx); err != nil {
		t.Fatalf("v4 Connect: %v", err)
	}
	_ = v4Client.Close()

	// Сервер с allow-v4=false должен отклонять v4-клиента.
	strictAddr, stopStrict := startTestE2EServer(t, bin, "-protocol-version", "5", "-allow-v4=false")
	defer stopStrict()
	strictV4, err := NewClient(ClientConfig{
		Server:          strictAddr,
		MasterPassword:  "shared-secret",
		ClientID:        "cep-v4-strict",
		ProtocolVersion: int(ProtocolVersionV4),
	})
	if err != nil {
		t.Fatal(err)
	}
	strictCtx, strictCancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer strictCancel()
	if err := strictV4.Connect(strictCtx); err == nil {
		_ = strictV4.Close()
		t.Fatal("сервер с allow-v4=false не должен принимать v4")
	}
}

func TestV5ClientRejectsDowngradeAndDropsInjectedV4(t *testing.T) {
	pw := "downgrade-secret"
	psk := DerivePSK(pw, DefaultParams)

	// 1. Ложный сервер отвечает на v5 handshake кадром v4 — клиент обязан вернуть ErrDowngradeRejected.
	udpLn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer udpLn.Close()

	go func() {
		buf := make([]byte, 4096)
		n, addr, rerr := udpLn.ReadFromUDP(buf)
		if rerr != nil {
			return
		}
		_, ft, salt, _, _, _, herr := ParseAndVerifyHeader(buf[:n], psk)
		if herr != nil || ft != FrameTypeHandshakeReq {
			return
		}
		hsKey := DeriveKey(pw, salt, DefaultParams)
		_, pub, _ := GenerateX25519Keypair()
		respBytes, _ := json.Marshal(HandshakeResponse{
			AssignedIP: "10.44.0.2",
			ServerIP:   "10.44.0.1",
			SubnetCidr: 24,
			MTU:        1380,
			ServerTime: time.Now().Unix(),
			ServerPub:  hex.EncodeToString(pub),
		})
		v4Resp, _ := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, SealOptions{
			Version:       ProtocolVersionV4,
			MaxOuterBytes: DefaultMaxOuterUDPSize,
		})
		_, _ = udpLn.WriteToUDP(v4Resp, addr)
	}()

	c, err := NewClient(ClientConfig{
		Server:          udpLn.LocalAddr().String(),
		MasterPassword:  pw,
		ClientID:        "anti-downgrade",
		ProtocolVersion: int(ProtocolVersionV5),
	})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := c.Connect(ctx); !errors.Is(err, ErrDowngradeRejected) {
		t.Fatalf("ожидался ErrDowngradeRejected при ответе v4 на v5-рукопожатие, получено: %v", err)
	}

	// 2. Активная v5 TCP transit-сессия отклоняет впрыснутый v4 кадр.
	tcpLn, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcpLn.Close()

	go func() {
		conn, aerr := tcpLn.Accept()
		if aerr != nil {
			return
		}
		defer conn.Close()
		wireReq, rerr := ReadTCPFrame(conn)
		if rerr != nil {
			return
		}
		_, ft, salt, _, _, _, herr := ParseAndVerifyHeader(wireReq, psk)
		if herr != nil || ft != FrameTypeHandshakeReq {
			return
		}
		hsKey := DeriveKey(pw, salt, DefaultParams)
		opened, oerr := OpenPacketWithOptions(wireReq, psk, hsKey, OpenOptions{
			ExpectedVersion: ProtocolVersionV5,
			MaxOuterBytes:   MaxTCPFrameSize,
		})
		if oerr != nil {
			return
		}
		var req HandshakeRequest
		_ = json.Unmarshal(opened.Plaintext, &req)
		cliPub, _ := hex.DecodeString(req.ClientPub)
		srvPriv, srvPub, _ := GenerateX25519Keypair()
		shared, _ := ComputeX25519Shared(srvPriv, cliPub)
		_, s2c := DeriveSessionKeys(hsKey, shared, salt)

		respBytes, _ := json.Marshal(HandshakeResponse{
			ServerTime:      time.Now().Unix(),
			ServerPub:       hex.EncodeToString(srvPub),
			Mode:            ModeTransit,
			ProtocolVersion: int(ProtocolVersionV5),
		})
		wireResp, _ := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, SealOptions{
			Version:       ProtocolVersionV5,
			Padding:       DefaultPaddingConfig(),
			MaxOuterBytes: MaxTCPFrameSize,
		})
		_ = WriteTCPFrame(conn, wireResp)

		// Внедряем кадр v4 внутри v5 TCP-потока.
		v4Frame, _ := SealPacketWithOptions(FrameTypeData, salt, psk, s2c, 1, []byte("downgrade-attempt"), SealOptions{
			Version:       ProtocolVersionV4,
			MaxOuterBytes: MaxTCPFrameSize,
		})
		_ = WriteTCPFrame(conn, v4Frame)
	}()

	stream, _, err := DialTransitTCP(ctx, ClientConfig{
		Server:          tcpLn.Addr().String(),
		MasterPassword:  pw,
		Target:          "127.0.0.1:443",
		Proto:           ProtoTCP,
		ProtocolVersion: int(ProtocolVersionV5),
	})
	if err != nil {
		t.Fatalf("DialTransitTCP: %v", err)
	}
	defer stream.Close()
	buf := make([]byte, 128)
	if _, err := io.ReadFull(stream, buf[:4]); !errors.Is(err, ErrDowngradeRejected) {
		t.Fatalf("ожидался ErrDowngradeRejected при чтении v4-кадра в v5 TCP-сессии, получено: %v", err)
	}
}
