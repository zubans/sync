package argon4

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

func TestClientRejectsUnsupportedAndDowngradedProfile(t *testing.T) {
	if _, err := NewClient(ClientConfig{
		Server:          "127.0.0.1:51820",
		MasterPassword:  "secret",
		ProtocolVersion: int(ProtocolVersionV4),
		Profile:         ProfileConfig{Name: ProfileBaseline},
	}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("ожидался отказ для v4+profile, получено: %v", err)
	}

	if _, _, err := DialTransitTCP(context.Background(), ClientConfig{
		Server:         "127.0.0.1:51820",
		MasterPassword: "secret",
		Mode:           ModeTransit,
		Proto:          ProtoTCP,
		Target:         "127.0.0.1:443",
		Profile:        ProfileConfig{Name: ProfileVideoCall},
	}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("TCP-транзит обязан отклонять video-call: %v", err)
	}

	if _, err := StartTransitProxy(context.Background(), ClientConfig{
		Server:         "127.0.0.1:51820",
		MasterPassword: "secret",
		Mode:           ModeTransit,
		Proto:          "",
		Target:         "127.0.0.1:443",
		Profile:        ProfileConfig{Name: ProfileVideoCall},
	}, "127.0.0.1:0"); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("универсальный TCP+UDP транзит обязан отклонять video-call: %v", err)
	}

	// Запускаем локальный UDP-сервер, имитирующий откат профиля на ""
	pc, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket: %v", err)
	}
	defer pc.Close()

	pass := "client-profile-pass"
	psk := DerivePSK(pass, DefaultParams)

	go func() {
		buf := make([]byte, 4096)
		for {
			n, addr, rerr := pc.ReadFrom(buf)
			if rerr != nil {
				return
			}
			_, fType, salt, _, _, _, herr := ParseAndVerifyHeader(buf[:n], psk)
			if herr != nil || fType != FrameTypeHandshakeReq {
				continue
			}
			hsKey := DeriveKey(pass, salt, DefaultParams)
			opened, oerr := OpenPacketWithOptions(buf[:n], psk, hsKey, OpenOptions{AllowV4: false, MaxOuterBytes: 1500})
			if oerr != nil {
				continue
			}
			var req HandshakeRequest
			if err := json.Unmarshal(opened.Plaintext, &req); err != nil {
				continue
			}
			_, srvPub, _ := GenerateX25519Keypair()
			resp := HandshakeResponse{
				AssignedIP:      "10.44.0.2",
				ServerIP:        "10.44.0.1",
				SubnetCidr:      24,
				MTU:             1380,
				ServerTime:      time.Now().Unix(),
				ServerPub:       hex.EncodeToString(srvPub),
				Mode:            ModeTunnel,
				ProtocolVersion: int(ProtocolVersionV5),
				Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
				BehaviorProfile: ProfileBaseline, // Сервер откатил video-call до baseline!
				ProfileVersion:  BehaviorProfileVersion,
			}
			rb, _ := json.Marshal(resp)
			wire, _ := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, rb, SealOptions{
				Version:       ProtocolVersionV5,
				Padding:       DefaultPaddingConfig(),
				MaxOuterBytes: 1500,
			})
			_, _ = pc.WriteTo(wire, addr)
		}
	}()

	cli, err := NewClient(ClientConfig{
		Server:         pc.LocalAddr().String(),
		MasterPassword: pass,
		ClientID:       "cli-downgrade",
		Profile:        ProfileConfig{Name: ProfileVideoCall},
	})
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}
	defer cli.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	if err := cli.Connect(ctx); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("клиент обязан отвергнуть несогласованный откат профиля сервером, получено: %v", err)
	}
}

func TestClientVideoCallBatchWriteReadRoundTrip(t *testing.T) {
	pc, err := net.ListenPacket("udp4", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("ListenPacket: %v", err)
	}
	defer pc.Close()

	pass := "client-roundtrip-pass"
	psk := DerivePSK(pass, DefaultParams)

	var mu sync.Mutex
	var sawBatchToServer bool
	gotFromClient := make(chan [][]byte, 4)

	go func() {
		buf := make([]byte, 4096)
		var c2sKey, s2cKey, sessSalt []byte
		var srvSeq uint64
		for {
			n, addr, rerr := pc.ReadFrom(buf)
			if rerr != nil {
				return
			}
			_, fType, salt, _, _, _, herr := ParseAndVerifyHeader(buf[:n], psk)
			if herr != nil {
				continue
			}
			if fType == FrameTypeHandshakeReq {
				sessSalt = append([]byte(nil), salt...)
				hsKey := DeriveKey(pass, salt, DefaultParams)
				opened, oerr := OpenPacketWithOptions(buf[:n], psk, hsKey, OpenOptions{AllowV4: false, MaxOuterBytes: 1500})
				if oerr != nil {
					continue
				}
				var req HandshakeRequest
				if err := json.Unmarshal(opened.Plaintext, &req); err != nil {
					continue
				}
				cliPub, _ := hex.DecodeString(req.ClientPub)
				srvPriv, srvPub, _ := GenerateX25519Keypair()
				shared, _ := ComputeX25519Shared(srvPriv, cliPub)
				c2sKey, s2cKey = DeriveSessionKeys(hsKey, shared, salt)

				resp := HandshakeResponse{
					AssignedIP:      "10.66.0.2",
					ServerIP:        "10.66.0.1",
					SubnetCidr:      24,
					MTU:             1380,
					ServerTime:      time.Now().Unix(),
					ServerPub:       hex.EncodeToString(srvPub),
					Mode:            ModeTunnel,
					ProtocolVersion: int(ProtocolVersionV5),
					Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
					BehaviorProfile: ProfileVideoCall,
					ProfileVersion:  BehaviorProfileVersion,
				}
				rb, _ := json.Marshal(resp)
				srvSeq++
				wire, _ := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, srvSeq, rb, SealOptions{
					Version:       ProtocolVersionV5,
					Padding:       DefaultPaddingConfig(),
					MaxOuterBytes: 1500,
				})
				_, _ = pc.WriteTo(wire, addr)
				continue
			}
			if c2sKey == nil || !bytes.Equal(salt, sessSalt) {
				continue
			}
			opened, oerr := OpenPacketWithOptions(buf[:n], psk, c2sKey, OpenOptions{AllowV4: false, MaxOuterBytes: 1500})
			if oerr != nil {
				continue
			}
			if opened.FrameType == FrameTypeBatch {
				items, uerr := UnpackBatchPlaintext(opened.Plaintext, 1500)
				if uerr == nil && len(items) > 0 {
					mu.Lock()
					sawBatchToServer = true
					mu.Unlock()
					gotFromClient <- items
					// Сервер отвечает своим FrameTypeBatch обратно клиенту
					batchBack, _ := PackBatchPlaintext(items)
					srvSeq++
					backWire, _ := SealPacketWithOptions(FrameTypeBatch, sessSalt, psk, s2cKey, srvSeq, batchBack, SealOptions{
						Version:       ProtocolVersionV5,
						Padding:       DefaultPaddingConfig(),
						MaxOuterBytes: DefaultMaxOuterUDPSize,
					})
					_, _ = pc.WriteTo(backWire, addr)
				}
			}
		}
	}()

	cli, err := NewClient(ClientConfig{
		Server:         pc.LocalAddr().String(),
		MasterPassword: pass,
		ClientID:       "cli-vc",
		Profile:        ProfileConfig{Name: ProfileVideoCall},
	})
	if err != nil {
		t.Fatalf("NewClient: %v", err)
	}
	defer cli.Close()

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()
	if err := cli.Connect(ctx); err != nil {
		t.Fatalf("Connect: %v", err)
	}
	if cli.Response().MTU > 1160 || cli.Profile().Name != ProfileVideoCall {
		t.Fatalf("ожидался скорректированный MTU и активный профиль video-call: resp=%+v prof=%+v", cli.Response(), cli.Profile())
	}

	makeIP := func(tag byte) []byte {
		p := make([]byte, 32)
		p[0] = 0x45
		copy(p[12:16], net.IPv4(10, 66, 0, 2).To4())
		copy(p[16:20], net.IPv4(10, 66, 0, 1).To4())
		p[20] = tag
		return p
	}
	if err := cli.WritePacket(makeIP('X')); err != nil {
		t.Fatalf("WritePacket 1: %v", err)
	}
	if err := cli.WritePacket(makeIP('Y')); err != nil {
		t.Fatalf("WritePacket 2: %v", err)
	}

	select {
	case items := <-gotFromClient:
		if len(items) < 1 {
			t.Fatalf("ожидался непустой FrameTypeBatch на сервере")
		}
	case <-time.After(2 * time.Second):
		t.Fatalf("таймаут получения FrameTypeBatch от клиента")
	}

	// Читаем оба ответа из FrameTypeBatch (второй должен прийти из буфера ReadPacket без нового чтения сокета)
	p1, err := cli.ReadPacket()
	if err != nil || len(p1) != 32 || p1[20] != 'X' {
		t.Fatalf("ReadPacket 1: p1=%v err=%v", p1, err)
	}
	p2, err := cli.ReadPacket()
	if err != nil || len(p2) != 32 || p2[20] != 'Y' {
		t.Fatalf("ReadPacket 2: p2=%v err=%v", p2, err)
	}

	mu.Lock()
	batched := sawBatchToServer
	mu.Unlock()
	if !batched {
		t.Fatalf("малые пакеты должны были объединиться в FrameTypeBatch")
	}
}

func TestVideoCallExpiryDowngradesToBaselineBatchAndClientProfile(t *testing.T) {
	salt := []byte("cep-exp-salt-16!")
	psk := make([]byte, 32)
	key := make([]byte, 32)
	var seq atomic.Uint64

	clk := newFakeClock(time.Unix(1700002000, 0))
	prof, err := BuildSessionProfile(ProfileConfig{
		Name:        ProfileVideoCall,
		MaxDuration: 2 * time.Second,
	}, DefaultPaddingConfig(), DefaultTimingConfig(), nil)
	if err != nil {
		t.Fatalf("BuildSessionProfile: %v", err)
	}

	type wireEv struct {
		frameType byte
		outerLen  int
		plain     []byte
	}
	sentCh := make(chan wireEv, 16)
	shaper := NewTrafficShaper(ShaperOptions{
		Profile:          prof,
		Salt:             salt,
		PSK:              psk,
		Key:              key,
		Version:          ProtocolVersionV5,
		MaxOuterBytes:    DefaultMaxOuterUDPSize,
		NextSeq:          func() uint64 { return seq.Add(1) },
		Clock:            clk,
		Tracker:          NewActivityTracker(clk),
		RandUnit:         func() float64 { return 0.5 },
		KeepaliveEnabled: false,
		WriteWire: func(wire []byte) error {
			fType, _, _, plain, oerr := OpenPacketWithKey(wire, psk, key)
			if oerr != nil {
				return oerr
			}
			sentCh <- wireEv{frameType: fType, outerLen: len(wire), plain: plain}
			return nil
		},
	})
	shaper.Start()
	defer func() {
		shaper.Close()
		<-shaper.Done()
	}()

	cli := &Client{profile: prof, shaper: shaper}
	if cli.Profile().Name != ProfileVideoCall {
		t.Fatalf("до истечения срока Client.Profile() должен быть video-call, получено %q", cli.Profile().Name)
	}

	// До истечения maxDuration пакет 350 Б дополняется корзинами MediumBins (>= 620 Б).
	medBefore := make([]byte, 350)
	medBefore[0] = 0x45
	if err := shaper.EnqueueReal(medBefore); err != nil {
		t.Fatalf("EnqueueReal medBefore: %v", err)
	}
	clk.Advance(30 * time.Millisecond)
	evBefore := <-sentCh
	if evBefore.frameType != FrameTypeData || evBefore.outerLen < 620 {
		t.Fatalf("до истечения срока ожидался video-call padding >= 620 Б, получено %+v", evBefore)
	}

	// Истекает MaxDuration -> переключение статистики, Client.Profile(), padding и агрегации.
	clk.Advance(2100 * time.Millisecond)
	stAfter := shaper.Stats()
	if !stAfter.ProfileDowngraded || stAfter.ActiveProfile != ProfileBaseline || !strings.HasPrefix(stAfter.ActiveArchetype, "baseline-") {
		t.Fatalf("после истечения срока ожидался профиль baseline, получено %+v", stAfter)
	}
	if gotProf := cli.Profile(); gotProf.Name != ProfileBaseline {
		t.Fatalf("Client.Profile() после истечения срока должен возвращать baseline, получено %q", gotProf.Name)
	}

	medAfter := make([]byte, 350)
	medAfter[0] = 0x45
	if err := shaper.EnqueueReal(medAfter); err != nil {
		t.Fatalf("EnqueueReal medAfter: %v", err)
	}
	clk.Advance(25 * time.Millisecond)
	evAfter := <-sentCh
	if evAfter.frameType != FrameTypeData || evAfter.outerLen >= 620 {
		t.Fatalf("после снижения до baseline внешний пакет 350 Б должен быть < 620 Б, получено %d Б", evAfter.outerLen)
	}

	small1 := bytes.Repeat([]byte{'a'}, 70)
	small2 := bytes.Repeat([]byte{'b'}, 70)
	if err := shaper.EnqueueReal(small1); err != nil {
		t.Fatalf("EnqueueReal small1: %v", err)
	}
	if err := shaper.EnqueueReal(small2); err != nil {
		t.Fatalf("EnqueueReal small2: %v", err)
	}
	clk.Advance(20 * time.Millisecond)
	batchEv := <-sentCh
	if batchEv.frameType != FrameTypeBatch {
		t.Fatalf("ожидался FrameTypeBatch после снижения до baseline, получено frameType=%d", batchEv.frameType)
	}
	items, err := UnpackBatchPlaintext(batchEv.plain, DefaultMaxOuterUDPSize)
	if err != nil || len(items) != 2 || !bytes.Equal(items[0], small1) || !bytes.Equal(items[1], small2) {
		t.Fatalf("некорректный batch после снижения: items=%d err=%v", len(items), err)
	}
}
