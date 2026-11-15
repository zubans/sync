package tunnel

import (
	"bytes"
	"crypto/aes"
	"crypto/cipher"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"errors"
	"net"
	"testing"
	"time"
)

func sealRawInnerForTest(t *testing.T, version, frameType byte, salt, psk, key []byte, seq uint64, rawPlain []byte) []byte {
	t.Helper()
	block, err := aes.NewCipher(key)
	if err != nil {
		t.Fatalf("aes.NewCipher: %v", err)
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		t.Fatalf("cipher.NewGCM: %v", err)
	}
	var meta [MetaSize]byte
	meta[0] = version
	meta[1] = frameType
	binary.BigEndian.PutUint64(meta[6:14], seq)
	chk := computeHeaderCheck(psk, salt, version, frameType, meta[6:14])
	copy(meta[2:6], chk[:])

	var nonce [NonceSize]byte
	copy(nonce[:], meta[2:14])
	mask := computeHeaderMask(psk, salt)
	out := make([]byte, HeaderSize, HeaderSize+len(rawPlain)+TagSize)
	copy(out[:SaltSize], salt)
	for i := 0; i < MetaSize; i++ {
		out[SaltSize+i] = meta[i] ^ mask[i]
	}
	aad := BuildAAD(version, frameType, salt, seq)
	return gcm.Seal(out, nonce[:], rawPlain, aad)
}

func TestV5EnvelopeRoundTripMinMaxRandom(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	salt := []byte("v5-test-salt-16!")
	psk := DerivePSK("test-pass-v5", params)
	key := DeriveKey("test-pass-v5", salt, params)

	payloads := [][]byte{
		{},
		[]byte("ping"),
		[]byte("pong"),
		bytes.Repeat([]byte{0x45}, 128),
		bytes.Repeat([]byte{0x45}, 1380),
	}

	// 1. Минимальный padding = 0 и максимальный доступный под path MTU padding
	for i, pl := range payloads {
		maxPad := DefaultMaxOuterUDPSize - OverheadV5 - len(pl)
		for _, padLen := range []int{0, 1, maxPad} {
			frame, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, uint64(i*10+padLen+1), pl, SealOptions{
				Version:       ProtocolVersionV5,
				UseFixedPad:   true,
				FixedPadding:  padLen,
				MaxOuterBytes: DefaultMaxOuterUDPSize,
			})
			if err != nil {
				t.Fatalf("SealPacketWithOptions (len=%d pad=%d): %v", len(pl), padLen, err)
			}
			wantWireLen := OverheadV5 + len(pl) + padLen
			if len(frame) != wantWireLen {
				t.Fatalf("unexpected wire len: got %d want %d", len(frame), wantWireLen)
			}
			opened, err := OpenPacketWithOptions(frame, psk, key, OpenOptions{
				ExpectedVersion: ProtocolVersionV5,
				MaxOuterBytes:   DefaultMaxOuterUDPSize,
			})
			if err != nil {
				t.Fatalf("OpenPacketWithOptions (len=%d pad=%d): %v", len(pl), padLen, err)
			}
			if opened.Version != ProtocolVersionV5 || !bytes.Equal(opened.Plaintext, pl) {
				t.Fatalf("round-trip mismatch for len=%d pad=%d", len(pl), padLen)
			}
		}
	}

	// 2. Случайный padding во всех профилях: размеры на проводе различаются и не превышают MTU
	for _, profileName := range []string{PaddingProfileBalanced, PaddingProfileCompact, PaddingProfileWide} {
		cfg, err := PresetPaddingConfig(profileName, DefaultPathMTU)
		if err != nil {
			t.Fatalf("PresetPaddingConfig(%s): %v", profileName, err)
		}
		seenLens := make(map[int]struct{})
		samplePayload := bytes.Repeat([]byte{0x45}, 64)
		for seq := uint64(1); seq <= 80; seq++ {
			frame, err := SealPacketWithOptions(FrameTypeKeepalive, salt, psk, key, seq, samplePayload, SealOptions{
				Version:       ProtocolVersionV5,
				Padding:       cfg,
				MaxOuterBytes: DefaultMaxOuterUDPSize,
			})
			if err != nil {
				t.Fatalf("random seal (%s): %v", profileName, err)
			}
			if len(frame) > DefaultMaxOuterUDPSize {
				t.Fatalf("wire frame exceeded path MTU budget: %d > %d", len(frame), DefaultMaxOuterUDPSize)
			}
			if len(frame) == len(samplePayload)+OverheadV4 {
				t.Fatalf("v5 frame matched legacy v4 constant +46 length")
			}
			seenLens[len(frame)] = struct{}{}

			opened, err := OpenPacketWithOptions(frame, psk, key, OpenOptions{
				ExpectedVersion: ProtocolVersionV5,
				MaxOuterBytes:   DefaultMaxOuterUDPSize,
			})
			if err != nil || !bytes.Equal(opened.Plaintext, samplePayload) {
				t.Fatalf("random open (%s): err=%v", profileName, err)
			}
		}
		if len(seenLens) < 5 {
			t.Fatalf("profile %s generated insufficient padding variance: %d unique lengths", profileName, len(seenLens))
		}
	}
}

func TestV5EnvelopeStrictValidationAndMTU(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	salt := []byte("v5-strict-salt16")
	psk := DerivePSK("strict-secret", params)
	key := DeriveKey("strict-secret", salt, params)

	// Ошибочные конфигурации padding должны отклоняться сразу
	badConfigs := []PaddingConfig{
		{Profile: PaddingProfileCustom, MinPad: -1, MaxPad: 10},
		{Profile: PaddingProfileCustom, MinPad: 16, MaxPad: 16, SmallThreshold: 100, SmallMinPad: 8, SmallMaxPad: 32},
		{Profile: PaddingProfileCustom, MinPad: 8, MaxPad: 32, PathMTU: 400},
		{Profile: PaddingProfileCustom, MinPad: 8, MaxPad: 1500, PathMTU: 1500},
		{Profile: "unknown-mode"},
	}
	for _, bad := range badConfigs {
		if _, err := NormalizePaddingConfig(bad, true); err == nil {
			t.Fatalf("expected error for invalid padding config: %+v", bad)
		}
	}

	// Отрицательные и переполняющие длины отклоняются
	if err := ValidateEnvelopeV5Lengths(10, -1, 4, 1500); !errors.Is(err, ErrInvalidEnvelope) {
		t.Fatalf("expected ErrInvalidEnvelope for negative payloadLen, got %v", err)
	}
	if err := ValidateEnvelopeV5Lengths(10, 4, -2, 1500); !errors.Is(err, ErrInvalidEnvelope) {
		t.Fatalf("expected ErrInvalidEnvelope for negative padLen, got %v", err)
	}
	if _, err := PackEnvelopeV5([]byte("abc"), -1, 1472); !errors.Is(err, ErrInvalidPadding) {
		t.Fatalf("expected ErrInvalidPadding for negative pad on pack, got %v", err)
	}

	// Выходящая за границы кадра длина внутри зашифрованного конверта -> отказ
	badOutOfFrame := []byte{0x00, 0x20, 0x00, 0x00, 'a', 'b'}
	wireOutOfFrame := sealRawInnerForTest(t, ProtocolVersionV5, FrameTypeData, salt, psk, key, 1, badOutOfFrame)
	if _, err := OpenPacketWithOptions(wireOutOfFrame, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrInvalidEnvelope) {
		t.Fatalf("expected ErrInvalidEnvelope for declared length exceeding frame, got %v", err)
	}

	// Лишние байты после payload + padding -> отказ
	badTrailing := []byte{0x00, 0x02, 0x00, 0x02, 'a', 'b', 0x00, 0x00, 0x99}
	wireTrailing := sealRawInnerForTest(t, ProtocolVersionV5, FrameTypeData, salt, psk, key, 2, badTrailing)
	if _, err := OpenPacketWithOptions(wireTrailing, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrTrailingData) {
		t.Fatalf("expected ErrTrailingData for trailing bytes, got %v", err)
	}

	// Повреждённый (ненулевой) байт padding внутри конверта -> отказ
	badPad := []byte{0x00, 0x02, 0x00, 0x03, 'o', 'k', 0x00, 0x01, 0x00}
	wireBadPad := sealRawInnerForTest(t, ProtocolVersionV5, FrameTypeData, salt, psk, key, 3, badPad)
	if _, err := OpenPacketWithOptions(wireBadPad, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrInvalidPadding) {
		t.Fatalf("expected ErrInvalidPadding for corrupted padding, got %v", err)
	}

	// Усечённый v5-кадр (< 50 байт) -> отказ
	truncPlain := []byte{0x00, 0x00}
	wireTrunc := sealRawInnerForTest(t, ProtocolVersionV5, FrameTypeKeepalive, salt, psk, key, 4, truncPlain)
	if _, err := OpenPacketWithOptions(wireTrunc, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrPacketTooShort) {
		t.Fatalf("expected ErrPacketTooShort for 48-byte v5 frame, got %v", err)
	}

	// Изменение байта шифротекста или заголовка -> отказ GCM/PSK
	good, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, 5, []byte("hello"), SealOptions{
		Version:       ProtocolVersionV5,
		UseFixedPad:   true,
		FixedPadding:  12,
		MaxOuterBytes: 1472,
	})
	if err != nil {
		t.Fatalf("Seal good packet: %v", err)
	}
	tamperCipher := append([]byte(nil), good...)
	tamperCipher[len(tamperCipher)-20] ^= 0x80
	if _, err := OpenPacketWithOptions(tamperCipher, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrAuthFailed) {
		t.Fatalf("expected ErrAuthFailed on ciphertext tamper, got %v", err)
	}
	tamperHdr := append([]byte(nil), good...)
	tamperHdr[SaltSize+2] ^= 0x04
	if _, err := OpenPacketWithOptions(tamperHdr, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: 1472}); !errors.Is(err, ErrHeaderAuthFailed) {
		t.Fatalf("expected ErrHeaderAuthFailed on header tamper, got %v", err)
	}

	// Превышение MTU при запечатывании и при разборе отклоняется
	oversizePayload := bytes.Repeat([]byte{0x45}, DefaultMaxOuterUDPSize-OverheadV5+1)
	if _, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, 6, oversizePayload, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	}); !errors.Is(err, ErrMTUExceeded) {
		t.Fatalf("expected ErrMTUExceeded on oversized payload seal, got %v", err)
	}
	// Превышение MaxPayloadBytes при разборе (при этом внешний кадр <= MaxTCPFrameSize)
	largeWire, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, 7, bytes.Repeat([]byte{0x45}, 1450), SealOptions{
		Version:       ProtocolVersionV5,
		UseFixedPad:   true,
		FixedPadding:  0,
		MaxOuterBytes: MaxTCPFrameSize,
	})
	if err != nil {
		t.Fatalf("seal largeWire: %v", err)
	}
	if _, err := OpenPacketWithOptions(largeWire, psk, key, OpenOptions{ExpectedVersion: ProtocolVersionV5, MaxOuterBytes: DefaultMaxOuterUDPSize}); !errors.Is(err, ErrMTUExceeded) {
		t.Fatalf("expected ErrMTUExceeded on incoming frame > MaxOuterUDPSize, got %v", err)
	}
}

func TestV5VersionPolicyCompatAntiDowngradeAndCache(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	password := "compat-and-antidowngrade-secret"

	// 1. Сервер с PathMTU 1420 уменьшает выдаваемый TUN MTU, чтобы не было внешней фрагментации
	srvLowMTU, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		SubnetCIDR:     "10.44.0.1/24",
		MasterPassword: password,
		ArgonParams:    params,
		MTU:            1380,
		Padding: PaddingConfig{
			Profile: PaddingProfileCompact,
			PathMTU: 1420,
		},
	}, nil)
	if err != nil {
		t.Fatalf("NewServer low PathMTU: %v", err)
	}
	defer srvLowMTU.Close()
	if wantMTU := 1420 - IPv4UDPOverhead - OverheadV5; srvLowMTU.cfg.MTU != wantMTU {
		t.Fatalf("expected clamped TUN MTU %d for pathMTU 1420, got %d", wantMTU, srvLowMTU.cfg.MTU)
	}

	// 2. Сервер v5 по умолчанию разрешает legacy v4, но не позволяет downgrade активной v5-сессии
	tunDev, tunReadFromSrv, _ := newTestPipeTun()
	defer tunDev.Close()
	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		SubnetCIDR:     "10.44.0.1/24",
		MasterPassword: password,
		ArgonParams:    params,
		MTU:            1380,
	}, tunDev)
	if err != nil {
		t.Fatalf("NewServer default: %v", err)
	}
	defer srv.Close()

	psk := DerivePSK(password, params)

	// Сессия v5
	saltV5 := []byte("salt-session-v5!")
	hsKeyV5 := DeriveKey(password, saltV5, params)
	cliPrivV5, cliPubV5, _ := GenerateX25519Keypair()
	addrV5 := &net.UDPAddr{IP: net.IPv4(198, 51, 100, 10), Port: 51001}
	reqV5 := HandshakeRequest{
		ClientID:        "client-v5",
		ClientAuth:      hex.EncodeToString(ClientAuthToken(psk, "client-v5", "sec-v5")),
		ClientPub:       hex.EncodeToString(cliPubV5),
		Timestamp:       time.Now().Unix(),
		ProtocolVersion: int(ProtocolVersionV5),
		Capabilities:    []string{"pad-v5"},
		PaddingProfile:  PaddingProfileWide,
	}
	reqV5Bytes, _ := json.Marshal(reqV5)
	wireReqV5, err := SealPacketWithOptions(FrameTypeHandshakeReq, saltV5, psk, hsKeyV5, 1, reqV5Bytes, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	if err != nil {
		t.Fatalf("seal v5 handshake: %v", err)
	}
	respV5Wire := srv.HandlePacket(wireReqV5, addrV5)
	if respV5Wire == nil {
		t.Fatal("expected v5 handshake response")
	}
	// Повтор того же v5-запроса возвращает идентичный кэшированный ответ без второй сессии
	respV5Retry := srv.HandlePacket(wireReqV5, addrV5)
	if !bytes.Equal(respV5Retry, respV5Wire) {
		t.Fatal("handshake-cache must return identical v5 response on UDP retry")
	}

	openedRespV5, err := OpenPacketWithOptions(respV5Wire, psk, hsKeyV5, OpenOptions{
		ExpectedVersion: ProtocolVersionV5,
		MaxOuterBytes:   DefaultMaxOuterUDPSize,
	})
	if err != nil {
		t.Fatalf("open v5 handshake response: %v", err)
	}
	var hsRespV5 HandshakeResponse
	if err := json.Unmarshal(openedRespV5.Plaintext, &hsRespV5); err != nil {
		t.Fatalf("unmarshal v5 resp: %v", err)
	}
	if hsRespV5.ProtocolVersion != int(ProtocolVersionV5) || hsRespV5.PaddingProfile != PaddingProfileWide {
		t.Fatalf("unexpected negotiated v5 params: %+v", hsRespV5)
	}
	srvPubV5, _ := hex.DecodeString(hsRespV5.ServerPub)
	sharedV5, _ := ComputeX25519Shared(cliPrivV5, srvPubV5)
	c2sV5, s2cV5 := DeriveSessionKeys(hsKeyV5, sharedV5, saltV5)

	// Попытка прислать валидно зашифрованный кадр v4 в активную v5-сессию (downgrade) отклоняется
	downgradeKeepalive, _ := SealPacketWithOptions(FrameTypeKeepalive, saltV5, psk, c2sV5, 1, []byte("ping"), SealOptions{
		Version:       ProtocolVersionV4,
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	if resp := srv.HandlePacket(downgradeKeepalive, addrV5); resp != nil {
		t.Fatal("active v5 session must reject downgraded v4 keepalive")
	}

	// Штатный v5 keepalive принимается и получает зашифрованный v5 pong с padding
	v5Keepalive, _ := SealPacketWithOptions(FrameTypeKeepalive, saltV5, psk, c2sV5, 2, []byte("ping"), SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	pongWire := srv.HandlePacket(v5Keepalive, addrV5)
	if pongWire == nil {
		t.Fatal("expected v5 pong")
	}
	if len(pongWire) < OverheadV5+4+16 { // SmallMinPad=16 для v5 Keepalive
		t.Fatalf("expected padded v5 pong, got compact length %d", len(pongWire))
	}
	pongOpened, err := OpenPacketWithOptions(pongWire, psk, s2cV5, OpenOptions{
		ExpectedVersion: ProtocolVersionV5,
		MaxOuterBytes:   DefaultMaxOuterUDPSize,
	})
	if err != nil || string(pongOpened.Plaintext) != "pong" {
		t.Fatalf("open v5 pong failed: %v %q", err, string(pongOpened.Plaintext))
	}

	// Legacy v4 клиент на том же сервере обслуживается в v4
	saltV4 := []byte("salt-session-v4!")
	hsKeyV4 := DeriveKey(password, saltV4, params)
	cliPrivV4, cliPubV4, _ := GenerateX25519Keypair()
	addrV4 := &net.UDPAddr{IP: net.IPv4(198, 51, 100, 11), Port: 51002}
	reqV4Bytes, _ := json.Marshal(HandshakeRequest{
		ClientID:   "client-v4",
		ClientAuth: hex.EncodeToString(ClientAuthToken(psk, "client-v4", "sec-v4")),
		ClientPub:  hex.EncodeToString(cliPubV4),
		Timestamp:  time.Now().Unix(),
	})
	wireReqV4, _ := SealPacketWithOptions(FrameTypeHandshakeReq, saltV4, psk, hsKeyV4, 1, reqV4Bytes, SealOptions{
		Version:       ProtocolVersionV4,
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	respV4Wire := srv.HandlePacket(wireReqV4, addrV4)
	if respV4Wire == nil {
		t.Fatal("expected v4 response when AllowV4 is enabled")
	}
	openedV4Resp, err := OpenPacketWithOptions(respV4Wire, psk, hsKeyV4, OpenOptions{
		ExpectedVersion: ProtocolVersionV4,
		AllowV4:         true,
		MaxOuterBytes:   DefaultMaxOuterUDPSize,
	})
	if err != nil {
		t.Fatalf("open v4 handshake response: %v", err)
	}
	var hsRespV4 HandshakeResponse
	_ = json.Unmarshal(openedV4Resp.Plaintext, &hsRespV4)
	srvPubV4, _ := hex.DecodeString(hsRespV4.ServerPub)
	sharedV4, _ := ComputeX25519Shared(cliPrivV4, srvPubV4)
	c2sV4, _ := DeriveSessionKeys(hsKeyV4, sharedV4, saltV4)

	// IPv4-пакет от v5-клиента доходит до TUN сервера, а отклик шифруется в v5
	pktV5 := make([]byte, 28)
	pktV5[0] = 0x45
	copy(pktV5[12:16], net.ParseIP(hsRespV5.AssignedIP).To4())
	copy(pktV5[16:20], net.ParseIP(hsRespV5.ServerIP).To4())
	wireDataV5, _ := SealPacketWithOptions(FrameTypeData, saltV5, psk, c2sV5, 3, pktV5, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	})
	go func() {
		srv.HandlePacket(wireDataV5, addrV5)
	}()
	tunBuf := make([]byte, 2048)
	n, err := tunReadFromSrv.Read(tunBuf)
	if err != nil || !bytes.Equal(tunBuf[:n], pktV5) {
		t.Fatalf("server TUN did not receive v5 packet cleanly: err=%v n=%d", err, n)
	}
	_ = c2sV4

	// Сервер с DisallowV4=true отклоняет handshake v4
	srvStrict, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: password,
		ArgonParams:    params,
		DisallowV4:     true,
	}, nil)
	if err != nil {
		t.Fatalf("NewServer strict v5: %v", err)
	}
	defer srvStrict.Close()
	if resp := srvStrict.HandlePacket(wireReqV4, addrV4); resp != nil {
		t.Fatal("server with DisallowV4=true must reject v4 handshake")
	}
}

func TestV5WireLengthsBreakConstant46Signature(t *testing.T) {
	params := ArgonParams{Time: 1, Memory: 64, Threads: 1}
	salt := []byte("wire-pcap-salt16")
	psk := DerivePSK("pcap-secret", params)
	key := DeriveKey("pcap-secret", salt, params)
	opts := SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: DefaultMaxOuterUDPSize,
	}

	// Проверяем как короткие IPv4/keepalive пакеты, так и максимальный TUN MTU 1380 байт
	for _, innerSize := range []int{4, 40, 256, 1380} {
		payload := bytes.Repeat([]byte{0x45}, innerSize)
		lengths := make(map[int]struct{})
		for seq := uint64(1); seq <= 60; seq++ {
			wire, err := SealPacketWithOptions(FrameTypeData, salt, psk, key, seq+uint64(innerSize)*100, payload, opts)
			if err != nil {
				t.Fatalf("seal innerSize=%d: %v", innerSize, err)
			}
			if len(wire) == innerSize+OverheadV4 {
				t.Fatalf("wire length %d leaked inner+46 signature", len(wire))
			}
			if len(wire) > DefaultMaxOuterUDPSize {
				t.Fatalf("wire length %d exceeds UDP path MTU limit %d", len(wire), DefaultMaxOuterUDPSize)
			}
			lengths[len(wire)] = struct{}{}
		}
		if len(lengths) < 10 {
			t.Fatalf("innerSize=%d produced only %d distinct outer wire lengths", innerSize, len(lengths))
		}
	}
}
