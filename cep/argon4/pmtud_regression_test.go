package argon4

import (
	"context"
	"net"
	"os/exec"
	"path/filepath"
	"testing"
	"time"
)

func buildTransitServerBinary(t *testing.T, serverDir string) string {
	t.Helper()
	bin := filepath.Join(t.TempDir(), "argon-tunnel-server")
	cmd := exec.Command("go", "build", "-o", bin, "./cmd/argon-tunnel-server")
	cmd.Dir = serverDir
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("не удалось собрать argon-tunnel-server из %s: %v\n%s", serverDir, err, string(out))
	}
	return bin
}

func reserveFreeLoopbackAddr(t *testing.T) string {
	t.Helper()
	pc, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("не удалось зарезервировать UDP-порт: %v", err)
	}
	addr := pc.LocalAddr().String()
	_ = pc.Close()
	return addr
}

func startUDPBottleneckEchoServer(t *testing.T, maxPayload int) string {
	t.Helper()
	pc, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("не удалось поднять UDP echo-сервер: %v", err)
	}
	t.Cleanup(func() { _ = pc.Close() })

	go func() {
		buf := make([]byte, 65535)
		for {
			n, addr, err := pc.ReadFrom(buf)
			if err != nil {
				return
			}
			if n > maxPayload {
				// Отбрасываем крупные пакеты без ответа, моделируя blackhole-ограничение пути.
				continue
			}
			_, _ = pc.WriteTo(buf[:n], addr)
		}
	}()

	return pc.LocalAddr().String()
}

func TestProbeUDPDFRejectsBlackholeAndDetectsExact1280(t *testing.T) {
	silentPC, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(silent): %v", err)
	}
	defer silentPC.Close()
	silentAddr := silentPC.LocalAddr().String()

	ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
	defer cancel()

	// Тихий UDP-порт обязан завершиться ошибкой, а не вернуть неподтверждённый MTU=1500.
	silentRes := probeUDPDFEndpoint(ctx, "amneziawg", silentAddr, 0)
	if silentRes.OK || silentRes.OptimalPathMTU != 0 {
		t.Fatalf("ожидали отказ для тихого UDP-порта, получили OK=%v MTU=%d msg=%q err=%q",
			silentRes.OK, silentRes.OptimalPathMTU, silentRes.Message, silentRes.Error)
	}
	silentTransit := probeUDPDFEndpoint(ctx, "transit", silentAddr, 0)
	if silentTransit.OK || silentTransit.OptimalPathMTU != 0 {
		t.Fatalf("ожидали отказ для тихого transit UDP-порта, получили OK=%v MTU=%d", silentTransit.OK, silentTransit.OptimalPathMTU)
	}

	// Порог UDP payload 1252 Б (+28 Б IPv4/UDP) соответствует внешнему Path MTU 1280 Б.
	bottleneckAddr := startUDPBottleneckEchoServer(t, 1252)
	res := probeUDPDFEndpoint(ctx, "amneziawg", bottleneckAddr, 0)
	if !res.OK {
		t.Fatalf("ожидали успешный подбор при bottleneck=1280, получили ошибку: %q / %q", res.Message, res.Error)
	}
	if res.OptimalPathMTU != 1280 {
		t.Fatalf("ожидали OptimalPathMTU=1280, получили %d", res.OptimalPathMTU)
	}
	if res.InnerTunMTU != 1200 {
		t.Fatalf("ожидали InnerTunMTU=1200 (1280-80 для AmneziaWG), получили %d", res.InnerTunMTU)
	}
}

func TestProbeNodeMTUAuthenticatedTransitRequiresTargetWithoutTCPFallback(t *testing.T) {
	serverDir := filepath.Clean("../../tunnel-server")
	bin := buildTransitServerBinary(t, serverDir)

	nextHopSock, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket(nextHop): %v", err)
	}
	defer nextHopSock.Close()
	allowedTarget := nextHopSock.LocalAddr().String()

	transitAddr := reserveFreeLoopbackAddr(t)
	password := "pmtud-transit-secret"

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
		t.Fatalf("argon-tunnel-server не открыл %s за отведённое время", transitAddr)
	}

	ctx, cancel := context.WithTimeout(context.Background(), 6*time.Second)
	defer cancel()

	// Без Target сервер должен отклонить рукопожатие транзита; fallback к TCP MSS запрещён.
	failRes := ProbeNodeMTU(ctx, ProbeNodeMTURequest{
		Proto:        "transit",
		Server:       transitAddr,
		Password:     password,
		Mode:         ModeTransit,
		TransitProto: ProtoUDP,
		TimeoutMs:    1200,
	})
	if failRes.OK || failRes.TCPOverheadMSS != 0 || failRes.Method == "tcp-mss" {
		t.Fatalf("ожидали отказ без TCP fallback при пустом Target, получили: %+v", failRes)
	}

	// С переданным Target аутентифицированный транзит успешно проходит in-band Argon4 PLPMTUD.
	okRes := ProbeNodeMTU(ctx, ProbeNodeMTURequest{
		Proto:        "transit",
		Server:       transitAddr,
		Password:     password,
		Mode:         ModeTransit,
		TransitProto: ProtoUDP,
		Target:       allowedTarget,
		TimeoutMs:    3000,
	})
	if !okRes.OK || okRes.Method != "argon4_plpmtud" || okRes.OptimalPathMTU < 1280 {
		t.Fatalf("ожидали успешный argon4_plpmtud с Target=%s, получили: %+v", allowedTarget, okRes)
	}
}
