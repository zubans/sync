package argon4

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"net"
	"runtime"
	"sync"
	"testing"
	"time"
)

func TestClientConnectRetryIdempotentAndBackoff(t *testing.T) {
	const password = "test-retry-password"
	psk := DerivePSK(password, DefaultParams)

	udpLn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer udpLn.Close()

	var (
		mu       sync.Mutex
		attempts [][]byte
		times    []time.Time
		hsOnce   sync.Once
		hsReady  = make(chan struct{})
		hsKey    []byte
	)
	doneSrv := make(chan struct{})
	go func() {
		defer close(doneSrv)
		buf := make([]byte, 65535)
		for {
			n, raddr, rerr := udpLn.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			rawCopy := append([]byte(nil), buf[:n]...)
			ver, ft, salt, _, _, _, perr := ParseAndVerifyHeader(rawCopy, psk)
			if perr != nil || ft != FrameTypeHandshakeReq {
				continue
			}

			saltCopy := append([]byte(nil), salt...)
			hsOnce.Do(func() {
				go func() {
					hsKey = DeriveKey(password, saltCopy, DefaultParams)
					close(hsReady)
				}()
			})

			mu.Lock()
			attempts = append(attempts, rawCopy)
			times = append(times, time.Now())
			count := len(attempts)
			mu.Unlock()

			// Пропускаем первые 2 попытки, отвечаем на 3-ю.
			if count < 3 {
				continue
			}
			<-hsReady
			opened, oerr := OpenPacketWithOptions(rawCopy, psk, hsKey, OpenOptions{
				ExpectedVersion: ver,
			})
			if oerr != nil {
				continue
			}
			var req HandshakeRequest
			if jerr := json.Unmarshal(opened.Plaintext, &req); jerr != nil {
				continue
			}
			_, srvPub, _ := GenerateX25519Keypair()
			resp := HandshakeResponse{
				ServerTime:      time.Now().Unix(),
				ServerPub:       hex.EncodeToString(srvPub),
				MTU:             1380,
				Mode:            ModeTransit,
				Target:          req.Target,
				ProtocolVersion: int(ProtocolVersionV5),
			}
			rb, _ := json.Marshal(resp)
			wr, _ := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, rb, SealOptions{
				Version: ProtocolVersionV5,
			})
			_, _ = udpLn.WriteToUDP(wr, raddr)
			return
		}
	}()

	cfg := ClientConfig{
		Server:          udpLn.LocalAddr().String(),
		MasterPassword:  password,
		ClientID:        "retry-client",
		ClientSecret:    "retry-secret",
		Proto:           ProtoUDP,
		Mode:            ModeTransit,
		Target:          "127.0.0.1:9999",
		ProtocolVersion: int(ProtocolVersionV5),
		Timing: TimingConfig{
			HandshakeMaxAttempts:    4,
			HandshakeInitialTimeout: 500 * time.Millisecond,
			HandshakeMaxTimeout:     2500 * time.Millisecond,
			HandshakeMaxElapsed:     10 * time.Second,
			HandshakeBackoffFactor:  1.6,
			HandshakeRetryMinPause:  20 * time.Millisecond,
			HandshakeRetryMaxPause:  50 * time.Millisecond,
			HandshakeJitterRatio:    0.20,
			KeepaliveMinIdle:        100 * time.Millisecond,
			KeepaliveMaxIdle:        200 * time.Millisecond,
			SessionIdleTimeout:      5 * time.Second,
		},
	}

	client, err := NewClient(cfg)
	if err != nil {
		t.Fatal(err)
	}
	if err := client.Connect(context.Background()); err != nil {
		t.Fatalf("ожидалось успешное подключение после ретраев: %v", err)
	}
	_ = client.Close()
	_ = udpLn.Close()
	<-doneSrv

	mu.Lock()
	defer mu.Unlock()
	if len(attempts) != 3 {
		t.Fatalf("ожидались ровно 3 попытки handshake, получено %d", len(attempts))
	}
	for i := 1; i < len(attempts); i++ {
		if !bytes.Equal(attempts[0], attempts[i]) {
			t.Fatalf("повторный HandshakeReq #%d отличается побайтно от первого", i)
		}
	}
	if gap1 := times[1].Sub(times[0]); gap1 < 380*time.Millisecond {
		t.Fatalf("первый backoff слишком короткий: %s", gap1)
	}
	if gap2 := times[2].Sub(times[1]); gap2 < 600*time.Millisecond {
		t.Fatalf("второй backoff должен вырасти по экспоненте, получено %s", gap2)
	}
}

func TestTransitProxyCloseNoGoroutineLeak(t *testing.T) {
	const password = "test-leak-password"
	psk := DerivePSK(password, DefaultParams)

	udpEcho, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer udpEcho.Close()

	// Локальный mock UDP-сервер Argon4 v5.
	srvLn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer srvLn.Close()

	srvDone := make(chan struct{})
	go func() {
		defer close(srvDone)
		buf := make([]byte, 65535)
		var c2s, s2c []byte
		var salt [SaltSize]byte
		var seq uint64
		for {
			n, raddr, rerr := srvLn.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			ver, ft, frameSalt, _, _, _, perr := ParseAndVerifyHeader(buf[:n], psk)
			if perr != nil {
				continue
			}
			if ft == FrameTypeHandshakeReq {
				hsKey := DeriveKey(password, frameSalt, DefaultParams)
				opened, oerr := OpenPacketWithOptions(buf[:n], psk, hsKey, OpenOptions{ExpectedVersion: ver})
				if oerr != nil {
					continue
				}
				var req HandshakeRequest
				_ = json.Unmarshal(opened.Plaintext, &req)
				cliPub, _ := hex.DecodeString(req.ClientPub)
				srvPriv, srvPub, _ := GenerateX25519Keypair()
				shared, _ := ComputeX25519Shared(srvPriv, cliPub)
				c2s, s2c = DeriveSessionKeys(hsKey, shared, frameSalt)
				copy(salt[:], frameSalt)
				resp := HandshakeResponse{
					ServerTime:      time.Now().Unix(),
					ServerPub:       hex.EncodeToString(srvPub),
					MTU:             1380,
					Mode:            ModeTransit,
					Target:          req.Target,
					ProtocolVersion: int(ProtocolVersionV5),
				}
				rb, _ := json.Marshal(resp)
				wr, _ := SealPacketWithOptions(FrameTypeHandshakeResp, frameSalt, psk, hsKey, 1, rb, SealOptions{
					Version: ProtocolVersionV5,
				})
				_, _ = srvLn.WriteToUDP(wr, raddr)
				continue
			}
			if len(c2s) == 0 {
				continue
			}
			opened, oerr := OpenPacketWithOptions(buf[:n], psk, c2s, OpenOptions{ExpectedVersion: ProtocolVersionV5})
			if oerr != nil {
				continue
			}
			if opened.FrameType == FrameTypeKeepalive {
				seq++
				pong, _ := SealPacketWithOptions(FrameTypeKeepalive, salt[:], psk, s2c, seq, []byte("pong"), SealOptions{
					Version: ProtocolVersionV5,
				})
				_, _ = srvLn.WriteToUDP(pong, raddr)
			}
		}
	}()

	// Прогреваем планировщик Go и фиксируем базовое число горутин.
	runtime.GC()
	baseline := runtime.NumGoroutine()

	proxy, err := StartTransitProxy(context.Background(), ClientConfig{
		Server:          srvLn.LocalAddr().String(),
		MasterPassword:  password,
		ClientID:        "leak-client",
		ClientSecret:    "leak-secret",
		Proto:           ProtoUDP,
		Mode:            ModeTransit,
		Target:          udpEcho.LocalAddr().String(),
		ProtocolVersion: int(ProtocolVersionV5),
		Timing: TimingConfig{
			KeepaliveMinIdle:   30 * time.Millisecond,
			KeepaliveMaxIdle:   50 * time.Millisecond,
			SessionIdleTimeout: 5 * time.Second,
		},
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}

	// Ждём пару keepalive-циклов в простое, затем закрываем прокси при активном Background ctx.
	time.Sleep(110 * time.Millisecond)
	if err := proxy.Close(); err != nil {
		t.Fatal(err)
	}
	_ = srvLn.Close()
	<-srvDone

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		runtime.GC()
		if runtime.NumGoroutine() <= baseline+1 {
			return
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("утечка горутин после Close TransitProxy: baseline=%d, стало=%d", baseline, runtime.NumGoroutine())
}
