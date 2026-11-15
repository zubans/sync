package cepcore

import (
	"context"
	"encoding/json"
	"net"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"contactsync/cep-engine/argon4"
)

func TestProbeProfileMTUAuthenticatedTransitPassesNextTargetAndRejectsMissingTarget(t *testing.T) {
	tunnelDir := filepath.Clean("../../tunnel-server")
	bin := filepath.Join(t.TempDir(), "argon-tunnel-server")
	buildCmd := exec.Command("go", "build", "-o", bin, "./cmd/argon-tunnel-server")
	buildCmd.Dir = tunnelDir
	if out, err := buildCmd.CombinedOutput(); err != nil {
		t.Fatalf("сборка argon-tunnel-server: %v\n%s", err, string(out))
	}

	nextHopSock, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(nextHop): %v", err)
	}
	defer nextHopSock.Close()
	allowedTarget := nextHopSock.LocalAddr().String()

	udpReserve, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(transit): %v", err)
	}
	transitAddr := udpReserve.LocalAddr().String()
	_ = udpReserve.Close()

	password := "android-transit-secret"
	srvCmd := exec.Command(bin,
		"-listen", transitAddr,
		"-mode", "transit",
		"-password", password,
		"-protocol-version", "5",
		"-transit-allow", allowedTarget,
	)
	if err := srvCmd.Start(); err != nil {
		t.Fatalf("запуск argon-tunnel-server: %v", err)
	}
	t.Cleanup(func() {
		if srvCmd.Process != nil {
			_ = srvCmd.Process.Kill()
			_, _ = srvCmd.Process.Wait()
		}
	})

	deadline := time.Now().Add(5 * time.Second)
	ready := false
	for time.Now().Before(deadline) {
		conn, derr := net.DialTimeout("tcp", transitAddr, 100*time.Millisecond)
		if derr == nil {
			_ = conn.Close()
			ready = true
			break
		}
		time.Sleep(50 * time.Millisecond)
	}
	if !ready {
		t.Fatalf("argon-tunnel-server не открыл %s", transitAddr)
	}

	t1 := Profile{
		ID:       "t1",
		Name:     "Android Transit",
		Proto:    "transit",
		Server:   transitAddr,
		Password: password,
	}
	n2 := Profile{
		ID:    "n2",
		Name:  "Exit",
		Proto: "vless",
		URI:   "vless://00000000-0000-4000-8000-000000000001@" + allowedTarget + "?type=tcp#exit",
	}

	t1JSON, err := json.Marshal(t1)
	if err != nil {
		t.Fatalf("marshal t1: %v", err)
	}
	chainJSON, err := json.Marshal([]Profile{t1, n2})
	if err != nil {
		t.Fatalf("marshal chain: %v", err)
	}

	var res argon4.ProbeNodeMTUResult
	rawOK := ProbeProfileMTU(string(t1JSON), string(chainJSON))
	if err := json.Unmarshal([]byte(rawOK), &res); err != nil {
		t.Fatalf("unmarshal ProbeProfileMTU(chain): %v (%s)", err, rawOK)
	}
	if !res.OK || res.Method != "argon4_plpmtud" || res.OptimalPathMTU < 1280 {
		t.Fatalf("ожидали успешный argon4_plpmtud с next-hop Target, получили: %+v", res)
	}

	var failRes argon4.ProbeNodeMTUResult
	rawFail := ProbeProfileMTU(string(t1JSON), "")
	if err := json.Unmarshal([]byte(rawFail), &failRes); err != nil {
		t.Fatalf("unmarshal ProbeProfileMTU(no chain): %v (%s)", err, rawFail)
	}
	if failRes.OK || failRes.TCPOverheadMSS != 0 || failRes.OptimalPathMTU != 0 {
		t.Fatalf("ожидали отказ без fallback на TCP MSS при отсутствии Target, получили: %+v", failRes)
	}
}

func TestProbeNodeMTUSilentUDPReturnsErrorAnd1280Bottleneck(t *testing.T) {
	silentPC, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(silent): %v", err)
	}
	defer silentPC.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	silentRes := argon4.ProbeNodeMTU(ctx, argon4.ProbeNodeMTURequest{
		Proto:     "amneziawg",
		Server:    silentPC.LocalAddr().String(),
		TimeoutMs: 900,
	})
	if silentRes.OK || silentRes.OptimalPathMTU != 0 {
		t.Fatalf("тихий UDP-порт не должен возвращать 1500: %+v", silentRes)
	}

	echoPC, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(echo): %v", err)
	}
	defer echoPC.Close()
	go func() {
		buf := make([]byte, 65535)
		for {
			n, addr, rerr := echoPC.ReadFrom(buf)
			if rerr != nil {
				return
			}
			if n > 1252 {
				continue
			}
			_, _ = echoPC.WriteTo(buf[:n], addr)
		}
	}()

	okRes := argon4.ProbeNodeMTU(ctx, argon4.ProbeNodeMTURequest{
		Proto:     "amneziawg",
		Server:    echoPC.LocalAddr().String(),
		TimeoutMs: 2500,
	})
	if !okRes.OK || okRes.OptimalPathMTU != 1280 || okRes.InnerTunMTU != 1200 {
		t.Fatalf("ожидали exact MTU 1280 / inner 1200, получили: %+v", okRes)
	}
}

func TestHysteria2UserpassOutboundPreservesUsernameAndPassword(t *testing.T) {
	l, err := parseLink("hysteria2://bob:pw%21123@hy2.example.org:443?sni=hy2.example.org#hy2")
	if err != nil {
		t.Fatalf("parseLink(hysteria2): %v", err)
	}
	out, err := singBoxOutbound(l, "198.51.100.2")
	if err != nil {
		t.Fatalf("singBoxOutbound: %v", err)
	}
	if got, _ := out["password"].(string); got != "bob:pw!123" {
		t.Fatalf("ожидали username:password в outbound[password], получили %q", got)
	}
}
