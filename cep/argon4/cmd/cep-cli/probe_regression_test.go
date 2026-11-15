package main

import (
	"bytes"
	"encoding/json"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
	"time"

	"cep/argon4"
)

func TestRunProbeMTUCommandAuthenticatedTransitPassesNextTarget(t *testing.T) {
	tunnelDir := filepath.Clean("../../../../tunnel-server")
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

	password := "cli-transit-secret"
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

	store := Store{
		Profiles: []Profile{
			{
				ID:       "t1",
				Name:     "Auth Transit",
				Proto:    "transit",
				Server:   transitAddr,
				Password: password,
			},
			{
				ID:    "n2",
				Name:  "Exit Node",
				Proto: "vless",
				URI:   "vless://00000000-0000-4000-8000-000000000001@" + allowedTarget + "?type=tcp#exit",
			},
		},
		Chains: []Chain{
			{
				ID:   "c1",
				Name: "Transit Chain",
				Hops: []string{"t1", "n2"},
			},
		},
	}

	profilesPath := filepath.Join(t.TempDir(), "profiles.json")
	raw, err := json.MarshalIndent(store, "", "  ")
	if err != nil {
		t.Fatalf("marshal store: %v", err)
	}
	if err := os.WriteFile(profilesPath, raw, 0o600); err != nil {
		t.Fatalf("write profiles.json: %v", err)
	}

	// Вызываем без -chain: CLI обязан сам найти цепочку в profiles.json и подставить next-hop Target.
	var out bytes.Buffer
	updatedProfile, updatedStore, changed, err := runProbeMTUCommand(
		profilesPath, "", "t1", "",
		"", "", "", "", "", "", 0,
		true, true, &out,
	)
	if err != nil {
		t.Fatalf("runProbeMTUCommand вернула ошибку: %v (output=%s)", err, out.String())
	}

	var parsed argon4.ProbeNodeMTUResult
	if uerr := json.Unmarshal(out.Bytes(), &parsed); uerr != nil {
		t.Fatalf("некорректный JSON-вывод %q: %v", out.String(), uerr)
	}
	if !parsed.OK || parsed.Method != "argon4_plpmtud" || parsed.OptimalPathMTU < 1280 {
		t.Fatalf("ожидали argon4_plpmtud с MTU >= 1280, получили: %+v", parsed)
	}
	if !changed || updatedStore == nil || updatedProfile.ArgonPathMTU != parsed.OptimalPathMTU || updatedStore.Profiles[0].ArgonPathMTU != parsed.OptimalPathMTU {
		t.Fatalf("профиль не обновил ArgonPathMTU: changed=%v profile=%+v store=%+v", changed, updatedProfile, updatedStore)
	}
}

func TestHysteria2UserpassURIParsing(t *testing.T) {
	link, err := parseProxyLink("hysteria2://alice:s3cr%40t@hy2.example.net:8443?sni=hy2.example.net#Hy2")
	if err != nil {
		t.Fatalf("parseLink(hysteria2): %v", err)
	}
	out, err := singBoxOutbound(link, "198.51.100.10")
	if err != nil {
		t.Fatalf("singBoxOutbound: %v", err)
	}
	if got, _ := out["password"].(string); got != "alice:s3cr@t" {
		t.Fatalf("ожидали user:password в outbound[password], получили %q", got)
	}
}
