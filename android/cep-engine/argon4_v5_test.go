package cepcore

import (
	"context"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"contactsync/cep-engine/argon4"
	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/option"
	sjson "github.com/sagernet/sing/common/json"
	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"github.com/sagernet/sing/service"
)

type directDialer struct{}

func (directDialer) DialContext(ctx context.Context, network string, destination M.Socksaddr) (net.Conn, error) {
	return (&net.Dialer{Timeout: 5 * time.Second}).DialContext(ctx, network, destination.String())
}

func (directDialer) ListenPacket(ctx context.Context, destination M.Socksaddr) (net.PacketConn, error) {
	return net.ListenPacket("udp4", ":0")
}

var _ N.Dialer = directDialer{}

func buildAndroidE2EServer(t *testing.T) string {
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

func startAndroidE2EServer(t *testing.T, bin string, extraArgs ...string) (string, func()) {
	t.Helper()
	addrFile := filepath.Join(t.TempDir(), "addr.txt")
	args := append([]string{"-listen", "127.0.0.1:0", "-password", "android-secret", "-addr-file", addrFile}, extraArgs...)
	ctx, cancel := context.WithCancel(context.Background())
	cmd := exec.CommandContext(ctx, bin, args...)
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
	t.Fatal("e2e-server не запустился")
	return "", nil
}

func TestAndroidArgon4V5E2EPaddingAndRetry(t *testing.T) {
	bin := buildAndroidE2EServer(t)
	wireLog := filepath.Join(t.TempDir(), "wire.log")
	addr, stop := startAndroidE2EServer(
		t, bin,
		"-drop-responses", "1",
		"-echo-data",
		"-protocol-version", "5",
		"-allow-v4=true",
		"-wire-log", wireLog,
	)
	defer stop()

	client, err := argon4.NewClient(argon4.ClientConfig{
		Server:             addr,
		MasterPassword:     "android-secret",
		ClientID:           "android-v5",
		ClientSecret:       "owner",
		PaddingProfile:     argon4.PaddingProfileBalanced,
		FirstPacketProfile: argon4.PaddingProfileWide,
		PathMTU:            1460,
	}, directDialer{})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	if err := client.Connect(ctx); err != nil {
		t.Fatalf("Connect v5: %v", err)
	}
	resp := client.Response()
	if resp.ProtocolVersion != int(argon4.ProtocolVersionV5) {
		t.Fatalf("ожидалась версия 5, получено %d", resp.ProtocolVersion)
	}
	if resp.MTU > argon4.MaxOuterBytesForPathMTU(1460)-argon4.OverheadV5 {
		t.Fatalf("MTU не ограничен под path_mtu=1460: %d", resp.MTU)
	}

	pkt := make([]byte, 64)
	pkt[0] = 0x45
	binary.BigEndian.PutUint16(pkt[2:4], uint16(len(pkt)))
	pkt[8] = 64
	pkt[9] = 17
	copy(pkt[12:16], net.ParseIP(resp.AssignedIP).To4())
	copy(pkt[16:20], net.ParseIP(resp.ServerIP).To4())

	for i := 0; i < 12; i++ {
		if err := client.WritePacket(pkt); err != nil {
			t.Fatalf("WritePacket(%d): %v", i, err)
		}
		got, err := client.ReadPacket()
		if err != nil {
			t.Fatalf("ReadPacket(%d): %v", i, err)
		}
		if len(got) != len(pkt) {
			t.Fatalf("неверная длина echo: %d", len(got))
		}
		if err := client.WriteKeepalive(); err != nil {
			t.Fatalf("WriteKeepalive(%d): %v", i, err)
		}
	}
	_ = client.Close()

	rawLog, err := os.ReadFile(wireLog)
	if err != nil {
		t.Fatal(err)
	}
	maxOuter := argon4.MaxOuterBytesForPathMTU(1460)
	sizes := make(map[int]struct{})
	for _, line := range strings.Split(strings.TrimSpace(string(rawLog)), "\n") {
		fields := strings.Fields(line)
		if len(fields) != 2 {
			continue
		}
		n, _ := strconv.Atoi(fields[1])
		if n > maxOuter {
			t.Fatalf("превышен path MTU %d: кадр %d", maxOuter, n)
		}
		if fields[0] == "tx" && n >= argon4.OverheadV5+len(pkt) && n <= argon4.OverheadV5+len(pkt)+192 {
			sizes[n] = struct{}{}
		}
	}
	if len(sizes) < 3 {
		t.Fatalf("ожидалась переменная длина v5 data-кадров, получено %v", sizes)
	}
}

func TestAndroidArgon4RejectsV4DowngradeAndStrictServerRejectsV4(t *testing.T) {
	pw := "android-downgrade"
	psk := argon4.DerivePSK(pw, argon4.DefaultParams)

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
		_, ft, salt, _, _, _, herr := argon4.ParseAndVerifyHeader(buf[:n], psk)
		if herr != nil || ft != argon4.FrameTypeHandshakeReq {
			return
		}
		hsKey := argon4.DeriveKey(pw, salt, argon4.DefaultParams)
		_, pub, _ := argon4.GenerateX25519Keypair()
		respBytes, _ := json.Marshal(argon4.HandshakeResponse{
			AssignedIP: "10.44.0.2",
			ServerIP:   "10.44.0.1",
			SubnetCidr: 24,
			MTU:        1380,
			ServerTime: time.Now().Unix(),
			ServerPub:  hex.EncodeToString(pub),
		})
		v4Resp, _ := argon4.SealPacketWithOptions(argon4.FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, argon4.SealOptions{
			Version:       argon4.ProtocolVersionV4,
			MaxOuterBytes: argon4.DefaultMaxOuterUDPSize,
		})
		_, _ = udpLn.WriteToUDP(v4Resp, addr)
	}()

	cli, err := argon4.NewClient(argon4.ClientConfig{
		Server:          udpLn.LocalAddr().String(),
		MasterPassword:  pw,
		ClientID:        "android-strict",
		ProtocolVersion: int(argon4.ProtocolVersionV5),
	}, directDialer{})
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if err := cli.Connect(ctx); !errors.Is(err, argon4.ErrDowngradeRejected) {
		t.Fatalf("ожидался ErrDowngradeRejected, получено %v", err)
	}

	// Проверяем через sing-box endpoint, что v5 конфигурация поднимает рабочий стек и обменивается данными.
	bin := buildAndroidE2EServer(t)
	strictAddr, stop := startAndroidE2EServer(t, bin, "-echo-data", "-protocol-version", "5", "-allow-v4=false", "-padding-profile", "compact")
	defer stop()

	cfg := map[string]any{
		"log": map[string]any{"level": "error"},
		"endpoints": []any{map[string]any{
			"type":             argon4Type,
			"tag":              "argon",
			"server":           strictAddr,
			"password":         "android-secret",
			"client_id":        "box-v5",
			"protocol_version": 5,
			"padding_profile":  "compact",
			"path_mtu":         1440,
		}},
		"outbounds": []any{map[string]any{"type": "direct", "tag": "direct"}},
		"route":     map[string]any{"final": "argon"},
	}
	rawCfg, _ := json.Marshal(cfg)
	boxCtx, boxCancel := context.WithCancel(engineContext(context.Background()))
	defer boxCancel()
	opts, err := sjson.UnmarshalExtendedContext[option.Options](boxCtx, rawCfg)
	if err != nil {
		t.Fatal(err)
	}
	service.MustRegister[adapter.PlatformInterface](boxCtx, &platform{p: fakePlatform{}})
	instance, err := box.New(box.Options{Context: boxCtx, Options: opts})
	if err != nil {
		t.Fatal(err)
	}
	defer instance.Close()
	if err := instance.Start(); err != nil {
		t.Fatal(err)
	}
}
