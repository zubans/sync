package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"log"
	"net"
	"net/netip"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// ClientConfig — параметры одного Argon4-туннеля. UDP-соединение использует
// системный маршрут: cep-engine заранее направляет адрес сервера в нужный узел.
type ClientConfig struct {
	Server             string
	MasterPassword     string
	ClientID           string
	ClientSecret       string
	Mode               string
	Target             string
	Proto              string
	ProtocolVersion    int
	PaddingProfile     string
	FirstPacketProfile string
	Padding            *PaddingConfig
	PathMTU            int
	Timing             TimingConfig
	Profile            ProfileConfig
	Clock              Clock
}

// Client — клиентская Argon4-сессия с раздельными ключами направлений и anti-replay.
// После Connect поля сессии неизменны; записывать пакеты можно из разных горутин.
type Client struct {
	config             ClientConfig
	protocolVersion    byte
	padding            PaddingConfig
	firstPacketPadding PaddingConfig
	maxOuterUDP        int
	timing             TimingConfig
	clock              Clock
	tracker            *ActivityTracker
	reqProfileName     string
	reqProfileVer      int
	profile            SessionProfile
	shaper             *TrafficShaper

	conn        net.Conn
	response    HandshakeResponse
	transitMode bool
	salt        [SaltSize]byte
	psk         []byte
	c2sKey      []byte
	s2cKey      []byte
	seq         atomic.Uint64
	replay      ReplayWindow
	readMu      sync.Mutex
	pendingRead [][]byte
	shaperErrMu sync.Mutex
	shaperErr   error
	closeOnce   sync.Once
}

func resolveTransportOptions(config ClientConfig, isUDP bool) (version byte, pad PaddingConfig, firstPad PaddingConfig, maxOuterUDP int, err error) {
	switch config.ProtocolVersion {
	case 0, int(ProtocolVersionV5):
		version = ProtocolVersionV5
	case int(ProtocolVersionV4):
		version = ProtocolVersionV4
	default:
		return 0, PaddingConfig{}, PaddingConfig{}, 0, fmt.Errorf("%w: %d", ErrBadVersion, config.ProtocolVersion)
	}

	var basePad PaddingConfig
	if config.Padding != nil {
		basePad = *config.Padding
	}
	if strings.TrimSpace(config.PaddingProfile) != "" {
		basePad.Profile = strings.TrimSpace(config.PaddingProfile)
	}
	if config.PathMTU != 0 {
		basePad.PathMTU = config.PathMTU
	}

	pad, err = NormalizePaddingConfig(basePad, isUDP)
	if err != nil {
		return 0, PaddingConfig{}, PaddingConfig{}, 0, err
	}
	firstPad = pad
	if fp := strings.TrimSpace(config.FirstPacketProfile); fp != "" {
		preset, perr := PresetPaddingConfig(fp, pad.PathMTU)
		if perr != nil {
			return 0, PaddingConfig{}, PaddingConfig{}, 0, perr
		}
		preset, perr = NormalizePaddingConfig(preset, isUDP)
		if perr != nil {
			return 0, PaddingConfig{}, PaddingConfig{}, 0, perr
		}
		firstPad = preset
	}
	maxOuterUDP = MaxOuterBytesForPathMTU(pad.PathMTU)
	return version, pad, firstPad, maxOuterUDP, nil
}

func capabilitiesForVersion(version byte, profileCapable bool) []string {
	return CapabilitiesForVersion(version, profileCapable)
}

func validateHandshakeProtocol(expectedVersion byte, response HandshakeResponse) error {
	if response.ProtocolVersion == 0 {
		return nil
	}
	if expectedVersion == ProtocolVersionV5 && byte(response.ProtocolVersion) == ProtocolVersionV4 {
		return ErrDowngradeRejected
	}
	if byte(response.ProtocolVersion) != expectedVersion {
		return fmt.Errorf("%w: %d", ErrBadVersion, response.ProtocolVersion)
	}
	return nil
}

func NewClient(config ClientConfig) (*Client, error) {
	if config.Server == "" {
		return nil, fmt.Errorf("не указан сервер Argon4")
	}
	if config.MasterPassword == "" {
		return nil, fmt.Errorf("не задан мастер-пароль Argon4")
	}
	ver, pad, firstPad, maxOuter, err := resolveTransportOptions(config, true)
	if err != nil {
		return nil, err
	}
	timingCfg, err := NormalizeTimingConfig(config.Timing)
	if err != nil {
		return nil, err
	}
	reqProfName, reqProfVer, err := ValidateProfileRequest(ver, config.Mode, config.Proto, config.Profile)
	if err != nil {
		return nil, err
	}
	if reqProfName == ProfileVideoCall {
		log.Printf("[argon4] ПРЕДУПРЕЖДЕНИЕ: %s", VideoCallWarning)
	}
	clk := config.Clock
	if clk == nil {
		clk = RealClock{}
	}
	return &Client{
		config:             config,
		protocolVersion:    ver,
		padding:            pad,
		firstPacketPadding: firstPad,
		maxOuterUDP:        maxOuter,
		timing:             timingCfg,
		clock:              clk,
		tracker:            NewActivityTracker(clk),
		reqProfileName:     reqProfName,
		reqProfileVer:      reqProfVer,
	}, nil
}

// Connect выполняет X25519-handshake. При потере ответа повторяется тот же зашифрованный
// запрос: серверный handshake-cache возвращает прежний ответ, не создавая вторую сессию.
func (c *Client) Connect(ctx context.Context) error {
	destination, err := net.ResolveUDPAddr("udp", c.config.Server)
	if err != nil || destination.IP == nil || destination.Port == 0 {
		return fmt.Errorf("неверный UDP-адрес сервера Argon4 %q", c.config.Server)
	}
	conn, err := (&net.Dialer{}).DialContext(ctx, "udp", destination.String())
	if err != nil {
		return fmt.Errorf("UDP-подключение к Argon4: %w", err)
	}
	connected := false
	defer func() {
		if !connected {
			_ = conn.Close()
		}
	}()

	if _, err := rand.Read(c.salt[:]); err != nil {
		return fmt.Errorf("соль Argon4: %w", err)
	}
	c.psk = DerivePSK(c.config.MasterPassword, DefaultParams)
	handshakeKey := DeriveKey(c.config.MasterPassword, c.salt[:], DefaultParams)
	defer clear(handshakeKey)
	privateKey, publicKey, err := GenerateX25519Keypair()
	if err != nil {
		return err
	}
	defer clear(privateKey)

	request := HandshakeRequest{
		ClientID:        c.config.ClientID,
		ClientAuth:      hex.EncodeToString(ClientAuthToken(c.psk, c.config.ClientID, c.config.ClientSecret)),
		ClientPub:       hex.EncodeToString(publicKey),
		Timestamp:       time.Now().Unix(),
		Mode:            c.config.Mode,
		Target:          c.config.Target,
		Proto:           c.config.Proto,
		ProtocolVersion: int(c.protocolVersion),
		Capabilities:    capabilitiesForVersion(c.protocolVersion, c.reqProfileVer > 0),
		PaddingProfile:  c.padding.Profile,
		BehaviorProfile: c.reqProfileName,
		ProfileVersion:  c.reqProfileVer,
	}
	plaintext, err := json.Marshal(request)
	if err != nil {
		return err
	}
	wireRequest, err := SealPacketWithOptions(FrameTypeHandshakeReq, c.salt[:], c.psk, handshakeKey, 1, plaintext, SealOptions{
		Version:       c.protocolVersion,
		Padding:       c.firstPacketPadding,
		MaxOuterBytes: c.maxOuterUDP,
	})
	if err != nil {
		return err
	}

	response, err := c.handshake(ctx, conn, wireRequest, handshakeKey)
	if err != nil {
		return err
	}
	sessProf, err := VerifyClientProfileResponse(c.reqProfileName, c.reqProfileVer, response, c.padding, c.timing, c.config.Profile, rand.Reader)
	if err != nil {
		return err
	}
	effectiveMode := response.Mode
	if effectiveMode == "" {
		if c.config.Mode == ModeTransit {
			effectiveMode = ModeTransit
		} else {
			effectiveMode = ModeTunnel
		}
		response.Mode = effectiveMode
	}
	if effectiveMode != ModeTransit {
		if err := validateHandshakeResponse(response); err != nil {
			return err
		}
		response.MTU = EffectiveProfileTunMTU(response.MTU, c.padding.PathMTU, c.protocolVersion, sessProf)
	}
	if response.ProtocolVersion == 0 {
		response.ProtocolVersion = int(c.protocolVersion)
	}
	serverPublic, err := hex.DecodeString(response.ServerPub)
	if err != nil || len(serverPublic) != X25519KeySize {
		return fmt.Errorf("Argon4: некорректный открытый ключ сервера")
	}
	sharedSecret, err := ComputeX25519Shared(privateKey, serverPublic)
	if err != nil {
		return fmt.Errorf("Argon4 X25519: %w", err)
	}
	c.c2sKey, c.s2cKey = DeriveSessionKeys(handshakeKey, sharedSecret, c.salt[:])
	clear(sharedSecret)
	if err := conn.SetDeadline(time.Time{}); err != nil {
		return err
	}
	c.conn = conn
	c.response = response
	c.transitMode = effectiveMode == ModeTransit
	c.profile = sessProf
	if sessProf.IsActive() {
		shaper := NewTrafficShaper(ShaperOptions{
			Profile:          sessProf,
			Salt:             c.salt[:],
			PSK:              c.psk,
			Key:              c.c2sKey,
			Version:          c.protocolVersion,
			MaxOuterBytes:    c.maxOuterUDP,
			NextSeq:          func() uint64 { return c.seq.Add(1) },
			WriteWire:        func(w []byte) error { _, werr := conn.Write(w); return werr },
			Clock:            c.clock,
			Tracker:          c.tracker,
			KeepaliveEnabled: true,
			IsServer:         false,
			OnFatalError: func(ferr error) {
				c.shaperErrMu.Lock()
				if c.shaperErr == nil {
					c.shaperErr = ferr
				}
				c.shaperErrMu.Unlock()
				_ = c.Close()
			},
		})
		shaper.Start()
		c.shaper = shaper
	}
	connected = true
	return nil
}

// IsTransit сообщает, перешла ли сессия в режим L4-транзита без виртуального TUN IP.
func (c *Client) IsTransit() bool {
	return c.transitMode
}

func (c *Client) handshake(ctx context.Context, conn net.Conn, wireRequest, handshakeKey []byte) (HandshakeResponse, error) {
	var parsed HandshakeResponse
	err := RunHandshakeLoop(ctx, c.timing, c.clock, nil, conn, wireRequest, func(packet []byte) (bool, error) {
		version, frameType, salt, _, _, _, herr := ParseAndVerifyHeader(packet, c.psk)
		if herr != nil || frameType != FrameTypeHandshakeResp || !bytes.Equal(salt, c.salt[:]) {
			return false, nil
		}
		opened, oerr := OpenPacketWithOptions(packet, c.psk, handshakeKey, OpenOptions{
			ExpectedVersion: c.protocolVersion,
			MaxOuterBytes:   c.maxOuterUDP,
		})
		if oerr != nil {
			if version != c.protocolVersion || IsNonRetryableHandshakeError(oerr) {
				return false, WrapNonRetryableHandshake(oerr)
			}
			return false, nil
		}
		var response HandshakeResponse
		if jerr := json.Unmarshal(opened.Plaintext, &response); jerr != nil {
			return false, WrapNonRetryableHandshake(fmt.Errorf("Argon4: неверный ответ handshake: %w", jerr))
		}
		if perr := validateHandshakeProtocol(c.protocolVersion, response); perr != nil {
			return false, WrapNonRetryableHandshake(perr)
		}
		parsed = response
		return true, nil
	})
	if err != nil {
		return HandshakeResponse{}, err
	}
	c.tracker.Touch()
	return parsed, nil
}

func validateHandshakeResponse(response HandshakeResponse) error {
	address, err := netip.ParseAddr(response.AssignedIP)
	if err != nil || !address.Is4() {
		return fmt.Errorf("Argon4: сервер выдал неверный IPv4-адрес %q", response.AssignedIP)
	}
	if response.SubnetCidr < 0 || response.SubnetCidr > 32 {
		return fmt.Errorf("Argon4: неверная маска /%d", response.SubnetCidr)
	}
	if response.MTU < 576 || response.MTU > 65535 {
		return fmt.Errorf("Argon4: неверный MTU %d", response.MTU)
	}
	return nil
}

func (c *Client) Response() HandshakeResponse {
	response := c.response
	response.DNS = append([]string(nil), c.response.DNS...)
	response.Routes = append([]string(nil), c.response.Routes...)
	return response
}

// WritePacket шифрует один исходящий пакет (IPv4 или L4-транзит) ключом c2s.
func (c *Client) WritePacket(plaintext []byte) error {
	if c.conn == nil {
		return net.ErrClosed
	}
	if !c.transitMode {
		if _, _, ok := ParseIPv4Addrs(plaintext); !ok {
			return fmt.Errorf("Argon4 переносит только IPv4-пакеты")
		}
	}
	if c.shaper != nil {
		err := c.shaper.EnqueueReal(plaintext)
		if errors.Is(err, ErrQueueFull) {
			return nil
		}
		return err
	}
	packet, err := SealPacketWithOptions(FrameTypeData, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), plaintext, SealOptions{
		Version:       c.protocolVersion,
		Padding:       c.padding,
		MaxOuterBytes: c.maxOuterUDP,
	})
	if err != nil {
		return err
	}
	_, err = c.conn.Write(packet)
	if err == nil {
		c.tracker.Touch()
	}
	return err
}

// Profile возвращает активный поведенческий профиль клиентской сессии.
func (c *Client) Profile() SessionProfile {
	if c.shaper != nil {
		return c.shaper.EffectiveProfile()
	}
	return c.profile
}

// ShaperStats возвращает агрегированную статистику шейпера (если профиль включён).
func (c *Client) ShaperStats() ShaperStats {
	if c.shaper == nil {
		return ShaperStats{}
	}
	return c.shaper.Stats()
}

// Timing возвращает нормализованную конфигурацию таймингов клиента.
func (c *Client) Timing() TimingConfig {
	return c.timing
}

// Tracker возвращает потокобезопасный трекер исходящей активности клиента.
func (c *Client) Tracker() *ActivityTracker {
	return c.tracker
}

// RunKeepalive запускает idle-keepalive с jitter и сбросом по исходящим данным до отмены ctx или ошибки сокета.
func (c *Client) RunKeepalive(ctx context.Context, onError func(error)) {
	if c.shaper != nil {
		select {
		case <-ctx.Done():
			return
		case <-c.shaper.Done():
			c.shaperErrMu.Lock()
			ferr := c.shaperErr
			c.shaperErrMu.Unlock()
			if ferr != nil && ctx.Err() == nil && onError != nil {
				onError(ferr)
			}
			return
		}
	}
	RunIdleKeepalive(ctx, KeepaliveOptions{
		Clock:   c.clock,
		Timing:  c.timing,
		Tracker: c.tracker,
		Send:    c.WriteKeepalive,
		OnError: onError,
	})
}

// WriteKeepalive поддерживает UDP NAT и серверную сессию во время простоя.
func (c *Client) WriteKeepalive() error {
	if c.conn == nil {
		return net.ErrClosed
	}
	if c.shaper != nil {
		return c.shaper.RequestControl(KeepalivePingPayload)
	}
	packet, err := SealPacketWithOptions(FrameTypeKeepalive, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), []byte("ping"), SealOptions{
		Version:       c.protocolVersion,
		Padding:       c.padding,
		MaxOuterBytes: c.maxOuterUDP,
	})
	if err != nil {
		return err
	}
	_, err = c.conn.Write(packet)
	return err
}

// ReadPacket возвращает следующий подлинный входящий IPv4-пакет. Мусор, чужие сессии,
// keepalive и повторы отбрасываются без завершения цикла приёма.
func (c *Client) ReadPacket() ([]byte, error) {
	if c.conn == nil {
		return nil, net.ErrClosed
	}
	c.readMu.Lock()
	if len(c.pendingRead) > 0 {
		next := c.pendingRead[0]
		c.pendingRead = c.pendingRead[1:]
		c.readMu.Unlock()
		return next, nil
	}
	c.readMu.Unlock()

	maxPayload := c.maxOuterUDP - OverheadV4
	if c.protocolVersion == ProtocolVersionV5 {
		maxPayload = c.maxOuterUDP - OverheadV5
	}
	buffer := make([]byte, 65535)
	for {
		n, err := c.conn.Read(buffer)
		if err != nil {
			return nil, err
		}
		version, _, salt, _, seq, _, err := ParseAndVerifyHeader(buffer[:n], c.psk)
		if err != nil || version != c.protocolVersion || !bytes.Equal(salt, c.salt[:]) || !c.replay.Check(seq) {
			continue
		}
		opened, err := OpenPacketWithOptions(buffer[:n], c.psk, c.s2cKey, OpenOptions{
			ExpectedVersion: c.protocolVersion,
			MaxOuterBytes:   c.maxOuterUDP,
			MaxPayloadBytes: maxPayload,
		})
		if err != nil || !c.replay.Accept(opened.Seq) {
			continue
		}
		if opened.FrameType == FrameTypeKeepalive {
			if c.shaper != nil && string(opened.Plaintext) == KeepalivePingPayload {
				_ = c.shaper.RequestControl(KeepalivePongPayload)
			}
			continue
		}
		if opened.FrameType == FrameTypeBatch {
			items, berr := UnpackBatchPlaintext(opened.Plaintext, maxPayload)
			if berr != nil {
				continue
			}
			valid := items[:0]
			for _, item := range items {
				if !c.transitMode {
					if _, _, ok := ParseIPv4Addrs(item); !ok {
						continue
					}
				}
				valid = append(valid, item)
			}
			if len(valid) == 0 {
				continue
			}
			if c.shaper != nil {
				c.shaper.RecordInboundActivity()
			}
			if len(valid) > 1 {
				c.readMu.Lock()
				c.pendingRead = append(c.pendingRead, valid[1:]...)
				c.readMu.Unlock()
			}
			return valid[0], nil
		}
		if opened.FrameType != FrameTypeData {
			continue
		}
		plaintext := opened.Plaintext
		if !c.transitMode {
			if _, _, ok := ParseIPv4Addrs(plaintext); !ok {
				continue
			}
		}
		if c.shaper != nil {
			c.shaper.RecordInboundActivity()
		}
		return plaintext, nil
	}
}

func (c *Client) Close() error {
	var err error
	c.closeOnce.Do(func() {
		if c.shaper != nil {
			c.shaper.Close()
		}
		if c.conn != nil {
			err = c.conn.Close()
		}
	})
	return err
}
