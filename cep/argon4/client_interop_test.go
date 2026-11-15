package argon4

import (
	"bytes"
	"context"
	"encoding/binary"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"sync"
	"testing"
	"time"
)

func buildE2EServerBinary(t *testing.T) string {
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
	return serverBinary
}

func startE2EServerWithBinary(t *testing.T, serverBinary string, extraArgs ...string) string {
	t.Helper()
	addrFile := filepath.Join(t.TempDir(), "server.addr")
	args := []string{"-listen", "127.0.0.1:0", "-addr-file", addrFile}
	args = append(args, extraArgs...)
	command := exec.Command(serverBinary, args...)
	var serverLog bytes.Buffer
	command.Stdout = &serverLog
	command.Stderr = &serverLog
	if err := command.Start(); err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() {
		if command.Process != nil {
			_ = command.Process.Kill()
		}
		_ = command.Wait()
		if t.Failed() {
			t.Logf("журнал сервера (%v):\n%s", extraArgs, serverLog.String())
		}
	})
	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		if content, err := os.ReadFile(addrFile); err == nil && len(content) > 0 {
			return string(content)
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("Go-сервер (%v) не сообщил UDP-адрес", extraArgs)
	return ""
}

func startGoServer(t *testing.T, password string) string {
	t.Helper()
	return startE2EServerWithBinary(t, buildE2EServerBinary(t),
		"-password", password,
		"-drop-responses", "1",
		"-echo-data",
	)
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

type udpWireCaptureProxy struct {
	mu       sync.Mutex
	outbound [][]byte
	inbound  [][]byte
	conn     *net.UDPConn
	target   *net.UDPAddr
}

func startUDPWireCaptureProxy(t *testing.T, targetAddr string) (*udpWireCaptureProxy, string) {
	t.Helper()
	dst, err := net.ResolveUDPAddr("udp4", targetAddr)
	if err != nil {
		t.Fatal(err)
	}
	conn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	p := &udpWireCaptureProxy{conn: conn, target: dst}
	t.Cleanup(func() { _ = conn.Close() })

	go func() {
		buf := make([]byte, 65535)
		var clientMu sync.Mutex
		var clientAddr *net.UDPAddr
		for {
			n, from, rerr := conn.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			pkt := append([]byte(nil), buf[:n]...)
			if from.Port == dst.Port && from.IP.Equal(dst.IP) {
				p.mu.Lock()
				p.inbound = append(p.inbound, pkt)
				p.mu.Unlock()
				clientMu.Lock()
				cAddr := clientAddr
				clientMu.Unlock()
				if cAddr != nil {
					_, _ = conn.WriteToUDP(pkt, cAddr)
				}
				continue
			}
			clientMu.Lock()
			clientAddr = from
			clientMu.Unlock()
			p.mu.Lock()
			p.outbound = append(p.outbound, pkt)
			p.mu.Unlock()
			_, _ = conn.WriteToUDP(pkt, dst)
		}
	}()
	return p, conn.LocalAddr().String()
}

func (p *udpWireCaptureProxy) snapshot() (out [][]byte, in [][]byte) {
	p.mu.Lock()
	defer p.mu.Unlock()
	out = append(out, p.outbound...)
	in = append(in, p.inbound...)
	return out, in
}

func makeTestIPv4EchoProbe(srcIP, dstIP net.IP, srcPort, dstPort uint16, payloadLen int, tag byte) []byte {
	totalLen := 28 + payloadLen
	pkt := make([]byte, totalLen)
	pkt[0] = 0x45
	binary.BigEndian.PutUint16(pkt[2:4], uint16(totalLen))
	pkt[8] = 64
	pkt[9] = 17
	copy(pkt[12:16], srcIP.To4())
	copy(pkt[16:20], dstIP.To4())
	var sum uint32
	for i := 0; i < 20; i += 2 {
		sum += uint32(binary.BigEndian.Uint16(pkt[i : i+2]))
	}
	for sum>>16 != 0 {
		sum = (sum & 0xffff) + (sum >> 16)
	}
	binary.BigEndian.PutUint16(pkt[10:12], ^uint16(sum))
	binary.BigEndian.PutUint16(pkt[20:22], srcPort)
	binary.BigEndian.PutUint16(pkt[22:24], dstPort)
	binary.BigEndian.PutUint16(pkt[24:26], uint16(8+payloadLen))
	for i := 28; i < totalLen; i++ {
		pkt[i] = tag ^ byte(i)
	}
	return pkt
}

// Проверяет, что Argon4 v5 с video-call, baseline и FrameTypeBatch проходит через
// слепой (без пароля) и авторизованный промежуточные транзитные узлы так, что
// промежуточные узлы и наблюдатель на проводе не видят содержимое внутреннего трафика.
func TestV5AndVideoCallThroughIntermediateTransitNodes(t *testing.T) {
	serverBin := buildE2EServerBinary(t)

	t.Run("BlindIntermediateZeroKnowledgeV5VideoCallAndBatch", func(t *testing.T) {
		const exitPassword = "exit-v5-videocall-secret"
		exitAddr := startE2EServerWithBinary(t, serverBin,
			"-password", exitPassword,
			"-protocol-version", "5",
			"-behavior-profile", "video-call",
			"-allow-video-call",
			"-echo-data",
		)
		wireBeforeExit, wireBeforeExitAddr := startUDPWireCaptureProxy(t, exitAddr)
		blindAddr := startE2EServerWithBinary(t, serverBin,
			"-mode", "transit",
			"-transit-blind",
			"-transit-target", wireBeforeExitAddr,
		)
		wireBeforeBlind, wireBeforeBlindAddr := startUDPWireCaptureProxy(t, blindAddr)

		client, err := NewClient(ClientConfig{
			Server:          wireBeforeBlindAddr,
			MasterPassword:  exitPassword,
			ClientID:        "blind-transit-e2e",
			ClientSecret:    "sec-blind",
			ProtocolVersion: 5,
			PathMTU:         DefaultPathMTU,
			Profile: ProfileConfig{
				Name: ProfileVideoCall,
			},
		})
		if err != nil {
			t.Fatal(err)
		}
		defer client.Close()

		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		if err := client.Connect(ctx); err != nil {
			t.Fatalf("Connect через слепой транзит: %v", err)
		}
		resp := client.Response()
		if resp.ProtocolVersion != int(ProtocolVersionV5) || resp.BehaviorProfile != ProfileVideoCall {
			t.Fatalf("ожидались v5 + video-call через слепой транзит, получено v=%d profile=%q", resp.ProtocolVersion, resp.BehaviorProfile)
		}

		// Серия малых пакетов для запуска FrameTypeBatch в video-call + echo-проверки.
		const burst = 6
		for i := 0; i < burst; i++ {
			probe := makeTestIPv4EchoProbe(net.ParseIP(resp.AssignedIP), net.ParseIP(resp.ServerIP), uint16(4100+i), 5353, 32, byte(i+1))
			if err := client.WritePacket(probe); err != nil {
				t.Fatalf("WritePacket #%d: %v", i, err)
			}
		}
		readErr := make(chan error, 1)
		go func() {
			for i := 0; i < burst; i++ {
				got, rerr := client.ReadPacket()
				if rerr != nil {
					readErr <- fmt.Errorf("ReadPacket #%d: %w", i, rerr)
					return
				}
				if len(got) < 28 || !bytes.Equal(got[12:16], net.ParseIP(resp.ServerIP).To4()) {
					readErr <- fmt.Errorf("некорректный echo #%d: %x", i, got)
					return
				}
			}
			readErr <- nil
		}()
		select {
		case err := <-readErr:
			if err != nil {
				t.Fatal(err)
			}
		case <-time.After(8 * time.Second):
			t.Fatal("таймаут чтения echo-ответов через слепой транзит")
		}

		if client.shaper == nil {
			t.Fatal("ожидался активный шейпер v5")
		}
		stats := client.shaper.Stats()
		if stats.ActiveProfile != ProfileVideoCall {
			t.Fatalf("ожидался ActiveProfile=video-call, получено %q", stats.ActiveProfile)
		}
		if stats.SentBatchFrames == 0 {
			t.Fatalf("ожидалась агрегация FrameTypeBatch при burst отправке: %+v", stats)
		}

		// Инспектируем провод: слепой транзит не имеет пароля и видит лишь зашифрованные v5-кадры без открытых маркеров.
		outBeforeBlind, inBeforeBlind := wireBeforeBlind.snapshot()
		outBeforeExit, _ := wireBeforeExit.snapshot()
		if len(outBeforeBlind) == 0 || len(inBeforeBlind) == 0 || len(outBeforeExit) == 0 {
			t.Fatalf("пустой трафик на проводе: beforeBlind=%d/%d beforeExit=%d", len(outBeforeBlind), len(inBeforeBlind), len(outBeforeExit))
		}
		blindFakePSK := DerivePSK("wrong-or-no-password-at-blind-relay", DefaultParams)
		exitPSK := DerivePSK(exitPassword, DefaultParams)
		maxWire := MaxOuterBytesForPathMTU(DefaultPathMTU)
		for idx, pkt := range outBeforeExit {
			if len(pkt) > maxWire {
				t.Fatalf("кадр #%d превысил maxOuterUDP %d > %d", idx, len(pkt), maxWire)
			}
			if _, _, _, _, _, _, perr := ParseAndVerifyHeader(pkt, blindFakePSK); perr == nil {
				t.Fatalf("слепой узел не должен расшифровывать заголовок кадра #%d чужим ключом", idx)
			}
			ver, _, _, _, _, _, verr := ParseAndVerifyHeader(pkt, exitPSK)
			if verr != nil || ver != ProtocolVersionV5 {
				t.Fatalf("слепой транзит исказил v5-заголовок кадра #%d: ver=%d err=%v", idx, ver, verr)
			}
			if bytes.Contains(pkt, []byte("argon")) || bytes.Contains(pkt, []byte("video-call")) {
				t.Fatalf("обнаружены открытые сигнатуры протокола на проводе в кадре #%d", idx)
			}
		}
	})

	t.Run("AuthTransitUDPToNestedArgon4V5WithMTUBudgetAndZeroInnerLeak", func(t *testing.T) {
		const transitPassword = "outer-transit-password-A"
		const exitPassword = "inner-exit-password-B"

		exitAddr := startE2EServerWithBinary(t, serverBin,
			"-password", exitPassword,
			"-protocol-version", "5",
			"-behavior-profile", "baseline",
			"-allow-video-call",
			"-path-mtu", "1286",
			"-echo-data",
		)
		wireInner, wireInnerAddr := startUDPWireCaptureProxy(t, exitAddr)

		authTransitAddr := startE2EServerWithBinary(t, serverBin,
			"-mode", "transit",
			"-password", transitPassword,
			"-protocol-version", "5",
			"-allow-video-call",
			"-path-mtu", "1350",
			"-transit-allow", wireInnerAddr,
		)
		wireOuter, wireOuterAddr := startUDPWireCaptureProxy(t, authTransitAddr)

		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		proxy, err := StartTransitProxy(ctx, ClientConfig{
			Server:          wireOuterAddr,
			MasterPassword:  transitPassword,
			ClientID:        "outer-auth-transit",
			ClientSecret:    "outer-sec",
			Mode:            ModeTransit,
			Target:          wireInnerAddr,
			Proto:           ProtoUDP,
			ProtocolVersion: 5,
			PathMTU:         1350,
			Profile: ProfileConfig{
				Name: ProfileVideoCall,
			},
		}, "127.0.0.1:0")
		if err != nil {
			t.Fatalf("StartTransitProxy: %v", err)
		}
		defer proxy.Close()

		// Вложенный Argon4 работает внутри UDP-транзита с уменьшенным path_mtu = 1350 - 64 = 1286.
		const nestedMTU = 1350 - 64
		innerClient, err := NewClient(ClientConfig{
			Server:          proxy.ListenAddr(),
			MasterPassword:  exitPassword,
			ClientID:        "inner-argon-v5",
			ClientSecret:    "inner-sec",
			ProtocolVersion: 5,
			PathMTU:         nestedMTU,
			Profile: ProfileConfig{
				Name: ProfileBaseline,
			},
		})
		if err != nil {
			t.Fatal(err)
		}
		defer innerClient.Close()
		if err := innerClient.Connect(ctx); err != nil {
			t.Fatalf("Connect вложенного клиента через Auth Transit: %v", err)
		}
		resp := innerClient.Response()

		// 1. Малый burst -> срабатывает FrameTypeBatch (baseline).
		for i := 0; i < 4; i++ {
			probe := makeTestIPv4EchoProbe(net.ParseIP(resp.AssignedIP), net.ParseIP(resp.ServerIP), uint16(5100+i), 8080, 24, byte(0x50+i))
			if err := innerClient.WritePacket(probe); err != nil {
				t.Fatalf("burst WritePacket #%d: %v", i, err)
			}
		}
		// 2. Пакет на пределе вложенного path_mtu (1170B payload + 28B IP/UDP = 1198B <= MaxPayload(1286)) -> без ErrMTUExceeded!
		largeProbe := makeTestIPv4EchoProbe(net.ParseIP(resp.AssignedIP), net.ParseIP(resp.ServerIP), 5200, 8443, 1170, 0xA5)
		if err := innerClient.WritePacket(largeProbe); err != nil {
			t.Fatalf("large WritePacket: %v", err)
		}

		received := make(chan int, 5)
		go func() {
			for i := 0; i < 5; i++ {
				pkt, rerr := innerClient.ReadPacket()
				if rerr != nil {
					t.Errorf("inner ReadPacket #%d: %v", i, rerr)
					return
				}
				received <- len(pkt)
			}
		}()
		gotLarge := false
		for i := 0; i < 5; i++ {
			select {
			case sz := <-received:
				if sz == len(largeProbe) {
					gotLarge = true
				}
			case <-time.After(10 * time.Second):
				t.Fatalf("таймаут получения вложенного echo #%d", i)
			}
		}
		if !gotLarge {
			t.Fatal("максимальный MTU-пакет не дошёл через Auth Transit -> Exit Argon4")
		}
		if innerClient.shaper.Stats().SentBatchFrames == 0 {
			t.Fatalf("ожидались FrameTypeBatch во вложенном туннеле: %+v", innerClient.shaper.Stats())
		}

		// Проверяем развязку ключей:
		// - На участке Client -> AuthTransit заголовок подходит ТОЛЬКО к паролю транзита (Password A), НЕ к Password B.
		// - На участке AuthTransit -> Exit трафик остаётся зашифрован Password B; пароль транзита (Password A) не подходит!
		pskTransit := DerivePSK(transitPassword, DefaultParams)
		pskExit := DerivePSK(exitPassword, DefaultParams)
		outOuter, _ := wireOuter.snapshot()
		outInner, _ := wireInner.snapshot()
		if len(outOuter) == 0 || len(outInner) == 0 {
			t.Fatalf("недостаточно пакетов на проводе: outer=%d inner=%d", len(outOuter), len(outInner))
		}
		for idx, pkt := range outOuter {
			if _, _, _, _, _, _, err := ParseAndVerifyHeader(pkt, pskTransit); err != nil {
				t.Fatalf("внешний кадр #%d должен проверяться PSK транзита: %v", idx, err)
			}
			if _, _, _, _, _, _, err := ParseAndVerifyHeader(pkt, pskExit); err == nil {
				t.Fatalf("внешний кадр #%d не должен читаться ключом выходного узла", idx)
			}
		}
		for idx, pkt := range outInner {
			if _, _, _, _, _, _, err := ParseAndVerifyHeader(pkt, pskExit); err != nil {
				t.Fatalf("внутренний кадр #%d за Auth Transit должен оставаться v5-кадром Exit: %v", idx, err)
			}
			if _, _, _, _, _, _, err := ParseAndVerifyHeader(pkt, pskTransit); err == nil {
				t.Fatalf("промежуточный узел не должен видеть внутренний слой в открытом виде (кадр #%d)", idx)
			}
		}
	})
}
