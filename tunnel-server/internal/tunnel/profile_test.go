package tunnel

import (
	"bytes"
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

func makeTestIPv4Packet(src, dst net.IP, payload []byte) []byte {
	pkt := make([]byte, 20+len(payload))
	pkt[0] = 0x45
	copy(pkt[12:16], src.To4())
	copy(pkt[16:20], dst.To4())
	copy(pkt[20:], payload)
	return pkt
}

func TestProfileHandshakeNegotiationAndStrictRejection(t *testing.T) {
	// Запрет профилей поверх устаревшего v4 и неизвестных имён/версий
	if _, _, err := ValidateProfileRequest(ProtocolVersionV4, ModeTunnel, ProtoUDP, ProfileConfig{Name: ProfileBaseline}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("ожидалась ошибка ErrProfileUnsupported для v4+profile, получено: %v", err)
	}
	if _, _, err := ValidateProfileRequest(ProtocolVersionV5, ModeTunnel, ProtoUDP, ProfileConfig{Name: "custom-weird"}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("ожидалась ошибка для неизвестного профиля, получено: %v", err)
	}
	if _, _, err := ValidateProfileRequest(ProtocolVersionV5, ModeTransit, ProtoTCP, ProfileConfig{Name: ProfileVideoCall}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("ожидался отказ для video-call поверх TCP-транзита, получено: %v", err)
	}
	disallow := false
	if _, _, err := ValidateProfileRequest(ProtocolVersionV5, ModeTunnel, ProtoUDP, ProfileConfig{Name: ProfileVideoCall, AllowVideoCall: &disallow}); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("ожидался отказ по локальному запрету video-call, получено: %v", err)
	}

	// Запрос без профиля допустим как явный off по умолчанию
	name, ver, err := ValidateProfileRequest(ProtocolVersionV5, ModeTunnel, ProtoUDP, ProfileConfig{})
	if err != nil || name != "" || ver != 0 {
		t.Fatalf("неожиданный результат для пустого профиля: name=%q ver=%d err=%v", name, ver, err)
	}

	// Серверное согласование политики
	policyAllow := ServerProfilePolicy{AllowVideoCall: true, PathMTU: DefaultPathMTU, MaxDuration: DefaultVideoCallMaxDuration}
	policyDeny := ServerProfilePolicy{AllowVideoCall: false, PathMTU: DefaultPathMTU}

	prof, err := NegotiateServerProfile(ProtocolVersionV5, ModeTunnel, ProtoUDP, HandshakeRequest{
		BehaviorProfile: ProfileVideoCall,
		ProfileVersion:  BehaviorProfileVersion,
		Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
	}, policyAllow, nil)
	if err != nil || prof.Name != ProfileVideoCall || prof.Version != BehaviorProfileVersion {
		t.Fatalf("ожидалось согласование video-call v1, получено %+v (err=%v)", prof, err)
	}
	if _, err := NegotiateServerProfile(ProtocolVersionV5, ModeTunnel, ProtoUDP, HandshakeRequest{
		BehaviorProfile: ProfileVideoCall,
		ProfileVersion:  BehaviorProfileVersion,
	}, policyDeny, nil); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("сервер должен отклонять video-call при запрете в политике: %v", err)
	}
	if _, err := NegotiateServerProfile(ProtocolVersionV5, ModeTransit, ProtoTCP, HandshakeRequest{
		BehaviorProfile: ProfileVideoCall,
		ProfileVersion:  BehaviorProfileVersion,
	}, policyAllow, nil); !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("сервер должен отклонять video-call поверх TCP-транзита: %v", err)
	}

	// Клиент отвергает молчаливый откат или несовместимый профиль сервера
	_, err = VerifyClientProfileResponse(ProfileVideoCall, BehaviorProfileVersion, HandshakeResponse{
		ProtocolVersion: int(ProtocolVersionV5),
		Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
		BehaviorProfile: ProfileBaseline,
		ProfileVersion:  BehaviorProfileVersion,
	}, DefaultPaddingConfig(), DefaultTimingConfig(), ProfileConfig{Name: ProfileVideoCall}, nil)
	if !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("клиент обязан отклонять молчаливый откат профиля с video-call на baseline: %v", err)
	}
	_, err = VerifyClientProfileResponse(ProfileBaseline, BehaviorProfileVersion, HandshakeResponse{
		ProtocolVersion: int(ProtocolVersionV5),
		Capabilities:    []string{CapabilityPadV5},
		BehaviorProfile: "",
		ProfileVersion:  0,
	}, DefaultPaddingConfig(), DefaultTimingConfig(), ProfileConfig{Name: ProfileBaseline}, nil)
	if !errors.Is(err, ErrProfileUnsupported) {
		t.Fatalf("клиент обязан отклонять сервер без profile-v1 при явно запрошенном профиле: %v", err)
	}

	// Успешная проверка ответа сервера клиентом
	matched, err := VerifyClientProfileResponse(ProfileVideoCall, BehaviorProfileVersion, HandshakeResponse{
		ProtocolVersion: int(ProtocolVersionV5),
		Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
		BehaviorProfile: ProfileVideoCall,
		ProfileVersion:  BehaviorProfileVersion,
	}, DefaultPaddingConfig(), DefaultTimingConfig(), ProfileConfig{Name: ProfileVideoCall}, nil)
	if err != nil || matched.Name != ProfileVideoCall || matched.RecommendedTunMTU > 1160 {
		t.Fatalf("ожидался валидный SessionProfile video-call с уменьшенным TunMTU, получено %+v err=%v", matched, err)
	}
}

func TestProfileBatchPackUnpackAndServerHandlePacket(t *testing.T) {
	// Граничные проверки PackBatchPlaintext / UnpackBatchPlaintext
	if _, err := PackBatchPlaintext(nil); err == nil {
		t.Fatalf("пустой батч должен отклоняться")
	}
	tooMany := make([][]byte, MaxBatchCount+1)
	for i := range tooMany {
		tooMany[i] = []byte{1}
	}
	if _, err := PackBatchPlaintext(tooMany); err == nil {
		t.Fatalf("батч больше MaxBatchCount должен отклоняться")
	}

	pkts := [][]byte{[]byte("pkt-one-1234567890"), []byte("pkt-two-abcdefghij")}
	packed, err := PackBatchPlaintext(pkts)
	if err != nil {
		t.Fatalf("PackBatchPlaintext: %v", err)
	}
	unpacked, err := UnpackBatchPlaintext(packed, 2048)
	if err != nil {
		t.Fatalf("UnpackBatchPlaintext: %v", err)
	}
	if len(unpacked) != 2 || !bytes.Equal(unpacked[0], pkts[0]) || !bytes.Equal(unpacked[1], pkts[1]) {
		t.Fatalf("искажение при распаковке батча: %+v", unpacked)
	}
	if _, err := UnpackBatchPlaintext(append(append([]byte(nil), packed...), 0x99), 2048); err == nil {
		t.Fatalf("лишний хвост в батче должен отклоняться")
	}
	if _, err := UnpackBatchPlaintext(packed[:len(packed)-3], 2048); err == nil {
		t.Fatalf("усечённый батч должен отклоняться")
	}

	// Интеграционная проверка Server.HandlePacket с profile-v1, FrameTypeBatch, anti-spoofing и fb
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	tunDev, tunReadFromSrv, _ := newTestPipeTun()
	defer tunDev.Close()

	password := "profile-test-secret-key"
	srv, err := NewServer(ServerConfig{
		ListenAddr:         "127.0.0.1:0",
		TunName:            "argon-prof",
		SubnetCIDR:         "10.58.0.1/24",
		MasterPassword:     password,
		ArgonParams:        params,
		MTU:                1380,
		DNS:                []string{"1.1.1.1"},
		Routes:             []string{"0.0.0.0/0"},
		BehaviorProfile:    ProfileVideoCall,
		DisallowVideoCall:  false,
		SessionIdleTimeout: 5 * time.Second,
	}, tunDev)
	if err != nil {
		t.Fatalf("NewServer: %v", err)
	}
	defer srv.Close()

	clientAddr := &net.UDPAddr{IP: net.IPv4(198, 51, 100, 44), Port: 51234}
	salt := []byte("prof-salt-16b!01")
	psk := DerivePSK(password, params)
	hsKey := DeriveKey(password, salt, params)
	cliPriv, cliPub, _ := GenerateX25519Keypair()
	clientAuth := ClientAuthToken(psk, "prof-client", "install-prof-1")

	hsReq := HandshakeRequest{
		ClientID:        "prof-client",
		ClientAuth:      hex.EncodeToString(clientAuth),
		ClientPub:       hex.EncodeToString(cliPub),
		Timestamp:       time.Now().Unix(),
		Capabilities:    []string{CapabilityPadV5, CapabilityProfileV1},
		BehaviorProfile: ProfileVideoCall,
		ProfileVersion:  BehaviorProfileVersion,
	}
	hsReqBytes, _ := json.Marshal(hsReq)
	wireReq, err := SealPacketWithOptions(FrameTypeHandshakeReq, salt, psk, hsKey, 1, hsReqBytes, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: MaxTCPFrameSize,
	})
	if err != nil {
		t.Fatalf("SealPacketWithOptions hs: %v", err)
	}
	respWire := srv.HandlePacket(wireReq, clientAddr)
	if respWire == nil {
		t.Fatalf("сервер не ответил на v5 profile-v1 рукопожатие")
	}
	fType, _, _, respPlain, err := OpenPacketWithKey(respWire, psk, hsKey)
	if err != nil || fType != FrameTypeHandshakeResp {
		t.Fatalf("OpenPacketWithKey resp: fType=%d err=%v", fType, err)
	}
	var hsResp HandshakeResponse
	if err := json.Unmarshal(respPlain, &hsResp); err != nil {
		t.Fatalf("Unmarshal resp: %v", err)
	}
	if hsResp.BehaviorProfile != ProfileVideoCall || hsResp.ProfileVersion != BehaviorProfileVersion || !HasCapability(hsResp.Capabilities, CapabilityProfileV1) {
		t.Fatalf("неожиданные поля профиля в HandshakeResponse: %+v", hsResp)
	}
	if hsResp.MTU <= 0 || hsResp.MTU > 1160 {
		t.Fatalf("ожидался скорректированный MTU под video-call (<=1160), получено %d", hsResp.MTU)
	}

	srvPub, _ := hex.DecodeString(hsResp.ServerPub)
	shared, _ := ComputeX25519Shared(cliPriv, srvPub)
	c2sKey, _ := DeriveSessionKeys(hsKey, shared, salt)

	assignedIP := net.ParseIP(hsResp.AssignedIP).To4()
	dstIP := net.IPv4(10, 58, 0, 1)
	p1 := makeTestIPv4Packet(assignedIP, dstIP, []byte("alpha-frame"))
	p2 := makeTestIPv4Packet(assignedIP, dstIP, []byte("bravo-frame"))
	batchPlain, _ := PackBatchPlaintext([][]byte{p1, p2})

	batchWire, err := SealPacketWithOptions(FrameTypeBatch, salt, psk, c2sKey, 2, batchPlain, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	if err != nil {
		t.Fatalf("SealPacketWithOptions batch: %v", err)
	}
	gotPackets := make([][]byte, 0, 2)
	readDone := make(chan struct{})
	go func() {
		defer close(readDone)
		buf := make([]byte, 2048)
		for i := 0; i < 2; i++ {
			n, rerr := tunReadFromSrv.Read(buf)
			if rerr != nil {
				return
			}
			cp := make([]byte, n)
			copy(cp, buf[:n])
			gotPackets = append(gotPackets, cp)
		}
	}()
	if resp := srv.HandlePacket(batchWire, clientAddr); resp != nil {
		t.Fatalf("FrameTypeBatch не должен генерировать обратный пакет, получено %d байт", len(resp))
	}
	select {
	case <-readDone:
	case <-time.After(2 * time.Second):
		t.Fatalf("таймаут чтения распакованных пакетов батча из TUN")
	}
	if len(gotPackets) != 2 || !bytes.Equal(gotPackets[0], p1) || !bytes.Equal(gotPackets[1], p2) {
		t.Fatalf("некорректная распаковка FrameTypeBatch в TUN: got=%d", len(gotPackets))
	}

	// Односторонняя нагрузка fb не должна вызывать ответ от сервера, в отличие от ping -> pong
	fbWire, _ := SealPacketWithOptions(FrameTypeKeepalive, salt, psk, c2sKey, 3, []byte(KeepaliveFeedbackPayload), SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	if resp := srv.HandlePacket(fbWire, clientAddr); resp != nil {
		t.Fatalf("feedback-пакет %q должен быть односторонним без ответа", KeepaliveFeedbackPayload)
	}
	pingWire, _ := SealPacketWithOptions(FrameTypeKeepalive, salt, psk, c2sKey, 4, []byte(KeepalivePingPayload), SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	pongResp := srv.HandlePacket(pingWire, clientAddr)
	if pongResp == nil {
		t.Fatalf("ping внутри video-call должен возвращать pong")
	}

	// Anti-spoofing внутри FrameTypeBatch: поддельный srcIP внутри батча отбрасывается
	spoofed := makeTestIPv4Packet(net.IPv4(10, 58, 0, 250), dstIP, []byte("spoof"))
	badBatch, _ := PackBatchPlaintext([][]byte{spoofed})
	badWire, _ := SealPacketWithOptions(FrameTypeBatch, salt, psk, c2sKey, 5, badBatch, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	srv.HandlePacket(badWire, clientAddr)
	tunReadCh := make(chan []byte, 1)
	go func() {
		b := make([]byte, 512)
		n, rerr := tunReadFromSrv.Read(b)
		if rerr == nil {
			tunReadCh <- b[:n]
		}
	}()
	select {
	case unexpected := <-tunReadCh:
		t.Fatalf("поддельный пакет из батча просочился в TUN (%d байт)", len(unexpected))
	case <-time.After(60 * time.Millisecond):
	}
}

func TestTrafficShaperBoundedQueuePriorityCoalesceDelayAndDowngrade(t *testing.T) {
	base := time.Date(2026, 11, 14, 12, 0, 0, 0, time.UTC)
	clk := newFakeClock(base)
	salt := []byte("0123456789abcdef")
	psk := make([]byte, 32)
	key := make([]byte, 32)
	var seq atomic.Uint64

	type capturedWire struct {
		at        time.Time
		frameType byte
		seq       uint64
		outerLen  int
		plain     []byte
	}
	sentCh := make(chan capturedWire, 64)

	// Управляемый барьер в WriteWire позволяет детерминированно заполнить очередь без гонок.
	var gateMu sync.Mutex
	var gateActive bool
	var gateWaiters chan struct{}
	var gateEntered chan struct{}

	prof := SessionProfile{
		Name:                 ProfileVideoCall,
		Version:              BehaviorProfileVersion,
		Archetype:            "test-vc",
		Padding:              DefaultPaddingConfig(),
		TargetMaxOuter:       1210,
		RecommendedTunMTU:    1128,
		CoalesceEnabled:      true,
		CoalesceThreshold:    180,
		CoalesceMaxPackets:   4,
		CoalesceMaxBytes:     900,
		CoalesceMinWindow:    5 * time.Millisecond,
		CoalesceMaxWindow:    5 * time.Millisecond,
		MaxAddedDelay:        18 * time.Millisecond,
		MaxQueuePackets:      4,
		MaxQueueBytes:        512,
		BurstPackets:         2,
		SlotMinInterval:      30 * time.Millisecond,
		SlotMaxInterval:      30 * time.Millisecond,
		KeyframeBurstPackets: 4,
		KeyframeMinInterval:  2 * time.Second,
		KeyframeMaxInterval:  2 * time.Second,
		FeedbackMinInterval:  100 * time.Millisecond,
		FeedbackMaxInterval:  100 * time.Millisecond,
		FeedbackIdleMin:      400 * time.Millisecond,
		FeedbackIdleMax:      400 * time.Millisecond,
		KeepaliveMinIdle:     2500 * time.Millisecond,
		KeepaliveMaxIdle:     2500 * time.Millisecond,
		DeepIdleMinKeepalive: 15 * time.Second,
		DeepIdleMaxKeepalive: 15 * time.Second,
		MaxDuration:          1 * time.Second,
		SmallBins:            []PadBin{{Weight: 100, MinOuter: 150, MaxOuter: 170}},
		MediumBins:           []PadBin{{Weight: 100, MinOuter: 1050, MaxOuter: 1120}},
	}

	tracker := NewActivityTracker(clk)
	shaper := NewTrafficShaper(ShaperOptions{
		Profile:          prof,
		Salt:             salt,
		PSK:              psk,
		Key:              key,
		Version:          ProtocolVersionV5,
		MaxOuterBytes:    1210,
		NextSeq:          func() uint64 { return seq.Add(1) },
		Clock:            clk,
		Tracker:          tracker,
		RandUnit:         func() float64 { return 0.5 },
		KeepaliveEnabled: true,
		IsServer:         false,
		WriteWire: func(wire []byte) error {
			gateMu.Lock()
			active := gateActive
			waiters := gateWaiters
			entered := gateEntered
			if active {
				gateActive = false
			}
			gateMu.Unlock()
			if active {
				close(entered)
				<-waiters
			}
			fType, _, s, plain, err := OpenPacketWithKey(wire, psk, key)
			if err != nil {
				return err
			}
			sentCh <- capturedWire{
				at:        clk.Now(),
				frameType: fType,
				seq:       s,
				outerLen:  len(wire),
				plain:     plain,
			}
			return nil
		},
	})
	shaper.Start()
	clk.WaitUntilTimerArmed(t, clk.Now())

	// 1. Включаем одноразовый барьер на WriteWire и инициируем прогревочный ping,
	// чтобы детерминированно положить в очереди: ctrl("ping") и 2 малых реальных пакета.
	gateMu.Lock()
	gateActive = true
	gateWaiters = make(chan struct{})
	gateEntered = make(chan struct{})
	waitCh := gateWaiters
	enteredCh := gateEntered
	gateMu.Unlock()

	_ = shaper.RequestControl(KeepalivePingPayload)
	<-enteredCh

	// Пока runLoop заблокирован в WriteWire, ставим в очередь control + 2 малых real-пакета
	_ = shaper.RequestControl(KeepalivePingPayload)
	if err := shaper.EnqueueReal(bytes.Repeat([]byte{'a'}, 60)); err != nil {
		t.Fatalf("EnqueueReal 1: %v", err)
	}
	if err := shaper.EnqueueReal(bytes.Repeat([]byte{'b'}, 70)); err != nil {
		t.Fatalf("EnqueueReal 2: %v", err)
	}
	close(waitCh)

	warm := <-sentCh
	if warm.frameType != FrameTypeKeepalive {
		t.Fatalf("ожидался прогревочный ping, получено %d", warm.frameType)
	}
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(6 * time.Millisecond)

	first := <-sentCh
	second := <-sentCh
	if first.frameType != FrameTypeBatch {
		t.Fatalf("ожидалась отправка FrameTypeBatch раньше управляющего кадра, получено frameType=%d", first.frameType)
	}
	if delta := first.at.Sub(base); delta > prof.MaxAddedDelay {
		t.Fatalf("добавленная задержка %v превысила MaxAddedDelay %v", delta, prof.MaxAddedDelay)
	}
	items, err := UnpackBatchPlaintext(first.plain, 2048)
	if err != nil || len(items) != 2 {
		t.Fatalf("ожидались 2 пакета внутри FrameTypeBatch, получено %d (err=%v)", len(items), err)
	}
	if second.frameType != FrameTypeKeepalive || string(second.plain) != KeepalivePingPayload {
		t.Fatalf("ожидался управляющий кадр ping вторым, получено frameType=%d plain=%q", second.frameType, second.plain)
	}

	// 2. Ограниченная очередь и backpressure при переполнении (> MaxQueueBytes=512)
	// Фиксируем барьер на первом крупном пакете (400B -> не попадает в coalesce), чтобы остальные остались в очереди.
	gateMu.Lock()
	gateActive = true
	gateWaiters = make(chan struct{})
	gateEntered = make(chan struct{})
	waitCh2 := gateWaiters
	enteredCh2 := gateEntered
	gateMu.Unlock()

	if err := shaper.EnqueueReal(bytes.Repeat([]byte{'w'}, 210)); err != nil {
		t.Fatalf("EnqueueReal warmup: %v", err)
	}
	<-enteredCh2

	big := bytes.Repeat([]byte{'c'}, 200)
	if err := shaper.EnqueueReal(big); err != nil {
		t.Fatalf("EnqueueReal big 1: %v", err)
	}
	if err := shaper.EnqueueReal(big); err != nil {
		t.Fatalf("EnqueueReal big 2: %v", err)
	}
	if err := shaper.EnqueueReal(big); !errors.Is(err, ErrQueueFull) {
		t.Fatalf("ожидалась ошибка переполнения очереди ErrQueueFull, получено: %v", err)
	}
	close(waitCh2)

	for i := 0; i < 3; i++ {
		if i > 0 {
			clk.Advance(25 * time.Millisecond)
		}
		ev := <-sentCh
		if ev.frameType != FrameTypeData {
			t.Fatalf("ожидался FrameTypeData[%d] при сбросе очереди, получено %d", i, ev.frameType)
		}
	}

	// 3. Односторонний feedback "fb" при входящей активности и автоснижение после MaxDuration
	clk.WaitUntilTimerArmed(t, clk.Now())
	shaper.RecordInboundActivity()
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(110 * time.Millisecond)
	fbEv := <-sentCh
	if fbEv.frameType != FrameTypeKeepalive || string(fbEv.plain) != KeepaliveFeedbackPayload {
		t.Fatalf("ожидался односторонний кадр %q, получено frameType=%d plain=%q", KeepaliveFeedbackPayload, fbEv.frameType, fbEv.plain)
	}

	// Перешагиваем MaxDuration (1s от base) и проверяем переход на baseline idle keepalive (>=10s) без "fb"
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(1100 * time.Millisecond)
	clk.WaitUntilTimerArmed(t, clk.Now())
	deadline, has := clk.NextDeadline()
	if !has {
		t.Fatalf("ожидался активный таймер после снижения профиля")
	}
	if wait := deadline.Sub(clk.Now()); wait < 10*time.Second {
		t.Fatalf("после истечения MaxDuration ожидался редкий baseline keepalive >=10s, а запланирован %v", wait)
	}
	stats := shaper.Stats()
	if !stats.ProfileDowngraded || stats.DroppedBackpressure == 0 || stats.SentBatchFrames == 0 {
		t.Fatalf("некорректная статистика шейпера: %+v", stats)
	}

	shaper.Close()
	<-shaper.Done()
	if active := clk.ActiveTimers(); active != 0 {
		t.Fatalf("после Close() остались висящие таймеры: %d", active)
	}
}

func TestProfileUDPReorderWithinReplayWindowAndWireSizes(t *testing.T) {
	// Последовательный номер seq присваивается при финальном запечатывании перед записью в сокет,
	// а перестановка пакетов по UDP в пределах ReplayWindow расшифровывается корректно.
	salt := []byte("reorder-salt-16!")
	psk := make([]byte, 32)
	key := make([]byte, 32)
	var seq atomic.Uint64
	var wires [][]byte
	var mu sync.Mutex

	base := time.Date(2026, 11, 14, 13, 0, 0, 0, time.UTC)
	clk := newFakeClock(base)

	prof, err := BuildSessionProfile(ProfileConfig{Name: ProfileVideoCall}, DefaultPaddingConfig(), DefaultTimingConfig(), nil)
	if err != nil {
		t.Fatalf("BuildSessionProfile video-call: %v", err)
	}
	prof.CoalesceEnabled = false
	prof.BurstPackets = 4

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
		KeepaliveEnabled: false,
		WriteWire: func(w []byte) error {
			cp := make([]byte, len(w))
			copy(cp, w)
			mu.Lock()
			wires = append(wires, cp)
			mu.Unlock()
			return nil
		},
	})
	shaper.Start()
	defer shaper.Close()

	for i := 0; i < 4; i++ {
		pkt := makeTestIPv4Packet(net.IPv4(10, 44, 0, 2), net.IPv4(10, 44, 0, 1), []byte{byte('0' + i), 'x'})
		if err := shaper.EnqueueReal(pkt); err != nil {
			t.Fatalf("EnqueueReal %d: %v", i, err)
		}
	}
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		n := len(wires)
		mu.Unlock()
		if n == 4 {
			break
		}
		clk.Advance(5 * time.Millisecond)
		time.Sleep(2 * time.Millisecond)
	}
	mu.Lock()
	captured := append([][]byte(nil), wires...)
	mu.Unlock()
	if len(captured) != 4 {
		t.Fatalf("ожидалось 4 исходящих пакета, получено %d", len(captured))
	}

	// Доставляем серверу в перемешанном порядке: 2, 0, 3, 1
	order := []int{2, 0, 3, 1}
	rw := &ReplayWindow{}
	for _, idx := range order {
		if len(captured[idx]) > 1210 {
			t.Fatalf("внешний размер пакета %d (%d Б) превысил потолок video-call 1210 Б", idx, len(captured[idx]))
		}
		_, _, pktSeq, plain, oerr := OpenPacketWithKey(captured[idx], psk, key)
		if oerr != nil {
			t.Fatalf("OpenPacketWithKey[%d]: %v", idx, oerr)
		}
		if !rw.Accept(pktSeq) {
			t.Fatalf("ReplayWindow отверг законную перестановку пакета seq=%d", pktSeq)
		}
		if rw.Accept(pktSeq) {
			t.Fatalf("ReplayWindow повторно принял seq=%d", pktSeq)
		}
		if plain[len(plain)-2] != byte('0'+idx) {
			t.Fatalf("нарушен порядок шифрования/нумерации для пакета %d", idx)
		}
	}

	// Проверяем различие характеристик baseline и video-call (через EffectiveProfileTunMTU)
	baseProf, err := BuildSessionProfile(ProfileConfig{Name: ProfileBaseline}, DefaultPaddingConfig(), DefaultTimingConfig(), nil)
	if err != nil {
		t.Fatalf("BuildSessionProfile baseline: %v", err)
	}
	baseMTU := EffectiveProfileTunMTU(1380, DefaultPathMTU, ProtocolVersionV5, baseProf)
	vcMTU := EffectiveProfileTunMTU(1380, DefaultPathMTU, ProtocolVersionV5, prof)
	if baseMTU <= vcMTU {
		t.Fatalf("ожидался меньший EffectiveProfileTunMTU для video-call (%d) чем для baseline (%d)", vcMTU, baseMTU)
	}
	// Проверяем, что для каждого профиля определено несколько эталонных вариантов
	seenVC := map[string]bool{}
	for i := 0; i < 32; i++ {
		p, perr := BuildSessionProfile(ProfileConfig{Name: ProfileVideoCall}, DefaultPaddingConfig(), DefaultTimingConfig(), nil)
		if perr != nil {
			t.Fatalf("BuildSessionProfile[%d]: %v", i, perr)
		}
		seenVC[p.Archetype] = true
	}
	if len(seenVC) < 2 {
		t.Fatalf("ожидалось разнообразие эталонов video-call (>=2), получено %v", seenVC)
	}
	for i := 0; i < 64; i++ {
		padLen, serr := prof.SamplePaddingLen(40, DefaultMaxOuterUDPSize)
		if serr != nil {
			t.Fatalf("SamplePaddingLen: %v", serr)
		}
		totalWire := OverheadV5 + 40 + padLen
		if totalWire > 1210 {
			t.Fatalf("SamplePaddingLen выдал внешний пакет %d Б > 1210 Б", totalWire)
		}
	}
}

func TestVideoCallExpiryDowngradesPaddingCoalescingAndStats(t *testing.T) {
	salt := []byte("expiry-salt-16!!")
	psk := make([]byte, 32)
	key := make([]byte, 32)
	var seq atomic.Uint64

	base := time.Unix(1700001000, 0)
	clk := newFakeClock(base)
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
		IsServer:         true,
		WriteWire: func(wire []byte) error {
			fType, _, _, plain, oerr := OpenPacketWithKey(wire, psk, key)
			if oerr != nil {
				return oerr
			}
			sentCh <- wireEv{
				frameType: fType,
				outerLen:  len(wire),
				plain:     plain,
			}
			return nil
		},
	})
	shaper.Start()
	defer func() {
		shaper.Close()
		<-shaper.Done()
		if active := clk.ActiveTimers(); active != 0 {
			t.Fatalf("после Close() остались висящие таймеры: %d", active)
		}
	}()
	clk.WaitUntilTimerArmed(t, clk.Now())

	// 1. До истечения maxDuration пакет 350 Б попадает в MediumBins video-call (>= 620 Б), а статистика показывает video-call.
	if st := shaper.Stats(); st.ActiveProfile != ProfileVideoCall || st.ProfileDowngraded {
		t.Fatalf("до истечения срока ожидался профиль video-call, получено: %+v", st)
	}
	medPktBefore := makeTestIPv4Packet(net.IPv4(10, 44, 0, 2), net.IPv4(10, 44, 0, 1), bytes.Repeat([]byte{'m'}, 330))
	if err := shaper.EnqueueReal(medPktBefore); err != nil {
		t.Fatalf("EnqueueReal before expiry: %v", err)
	}
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(30 * time.Millisecond)
	evBefore := <-sentCh
	if evBefore.frameType != FrameTypeData || evBefore.outerLen < 620 {
		t.Fatalf("до истечения срока пакет 350 Б должен был дополниться корзиной MediumBins (>=620 Б): %+v", evBefore)
	}

	// 2. Перешагиваем MaxDuration (2s): таймер срабатывает даже без keepalive.
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(2100 * time.Millisecond)
	clk.WaitUntilTimerArmed(t, clk.Now())

	stAfter := shaper.Stats()
	if !stAfter.ProfileDowngraded || stAfter.ActiveProfile != ProfileBaseline || !strings.HasPrefix(stAfter.ActiveArchetype, "baseline-") {
		t.Fatalf("после истечения срока статистика должна перейти на baseline: %+v", stAfter)
	}
	eff := shaper.EffectiveProfile()
	if eff.Name != ProfileBaseline || len(eff.MediumBins) != 0 {
		t.Fatalf("EffectiveProfile должен быть снижен до baseline без MediumBins: %+v", eff)
	}

	// PeerSession.sealOptions (прямой путь ответа сервера) также обязан использовать сниженный padding baseline.
	sess := &PeerSession{ProtocolVersion: ProtocolVersionV5, Shaper: shaper}
	sessOpts := sess.sealOptions(DefaultMaxOuterUDPSize)
	if sessOpts.SamplePad == nil {
		t.Fatalf("ожидалась функция SamplePad для активного baseline после снижения")
	}
	directPad, err := sessOpts.SamplePad(350, DefaultMaxOuterUDPSize)
	if err != nil {
		t.Fatalf("PeerSession SamplePad after expiry: %v", err)
	}
	if OverheadV5+350+directPad >= 620 {
		t.Fatalf("PeerSession.sealOptions продолжил применять video-call padding: pad=%d (outer=%d)", directPad, OverheadV5+350+directPad)
	}

	// 3. После истечения срока пакет 350 Б должен получать умеренный padding baseline (< 620 Б).
	medPktAfter := makeTestIPv4Packet(net.IPv4(10, 44, 0, 2), net.IPv4(10, 44, 0, 1), bytes.Repeat([]byte{'n'}, 330))
	if err := shaper.EnqueueReal(medPktAfter); err != nil {
		t.Fatalf("EnqueueReal after expiry: %v", err)
	}
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(25 * time.Millisecond)
	evAfter := <-sentCh
	if evAfter.frameType != FrameTypeData || evAfter.outerLen >= 620 {
		t.Fatalf("после истечения срока video-call пакет 350 Б не должен попадать в MediumBins (>=620 Б), получено %d Б", evAfter.outerLen)
	}

	// 4. Мелкие пакеты после истечения срока снова агрегируются в FrameTypeBatch.
	small1 := makeTestIPv4Packet(net.IPv4(10, 44, 0, 2), net.IPv4(10, 44, 0, 1), bytes.Repeat([]byte{'s'}, 50))
	small2 := makeTestIPv4Packet(net.IPv4(10, 44, 0, 2), net.IPv4(10, 44, 0, 1), bytes.Repeat([]byte{'t'}, 50))
	if err := shaper.EnqueueReal(small1); err != nil {
		t.Fatalf("EnqueueReal small1: %v", err)
	}
	if err := shaper.EnqueueReal(small2); err != nil {
		t.Fatalf("EnqueueReal small2: %v", err)
	}
	clk.WaitUntilTimerArmed(t, clk.Now())
	clk.Advance(20 * time.Millisecond)
	batchEv := <-sentCh
	if batchEv.frameType != FrameTypeBatch {
		t.Fatalf("после перехода в baseline малые пакеты должны объединяться в FrameTypeBatch, получено frameType=%d", batchEv.frameType)
	}
	items, err := UnpackBatchPlaintext(batchEv.plain, DefaultMaxOuterUDPSize)
	if err != nil || len(items) != 2 || !bytes.Equal(items[0], small1) || !bytes.Equal(items[1], small2) {
		t.Fatalf("некорректное содержимое FrameTypeBatch после снижения до baseline: len=%d err=%v", len(items), err)
	}
	if stFinal := shaper.Stats(); stFinal.SentBatchFrames < 1 || stFinal.CoalescedPackets < 2 {
		t.Fatalf("статистика агрегации после снижения должна отражать batch: %+v", stFinal)
	}
}
