package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"net/netip"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
)

const (
	handshakeAttempts = 3
	handshakeTimeout  = 3 * time.Second
)

// ClientConfig — параметры одного Argon4-туннеля. UDP-соединение создаётся через
// dialer sing-box, поэтому работают detour и VpnService.protect на Android.
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
}

// Client — клиентская Argon4-сессия с раздельными ключами направлений и anti-replay.
// После Connect поля сессии неизменны; записывать пакеты можно из разных горутин.
type Client struct {
	config             ClientConfig
	dialer             N.Dialer
	protocolVersion    byte
	padding            PaddingConfig
	firstPacketPadding PaddingConfig
	maxOuterUDP        int

	conn        net.Conn
	response    HandshakeResponse
	transitMode bool
	salt        [SaltSize]byte
	psk         []byte
	c2sKey      []byte
	s2cKey      []byte
	seq         atomic.Uint64
	replay      ReplayWindow
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

func capabilitiesForVersion(version byte) []string {
	if version == ProtocolVersionV5 {
		return []string{"pad-v5"}
	}
	return nil
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

func NewClient(config ClientConfig, dialer N.Dialer) (*Client, error) {
	if config.Server == "" {
		return nil, fmt.Errorf("не указан сервер Argon4")
	}
	if config.MasterPassword == "" {
		return nil, fmt.Errorf("не задан мастер-пароль Argon4")
	}
	if dialer == nil {
		return nil, fmt.Errorf("не задан dialer Argon4")
	}
	ver, pad, firstPad, maxOuter, err := resolveTransportOptions(config, true)
	if err != nil {
		return nil, err
	}
	return &Client{
		config:             config,
		dialer:             dialer,
		protocolVersion:    ver,
		padding:            pad,
		firstPacketPadding: firstPad,
		maxOuterUDP:        maxOuter,
	}, nil
}

// Connect выполняет X25519-handshake. При потере ответа повторяется тот же зашифрованный
// запрос: серверный handshake-cache возвращает прежний ответ, не создавая вторую сессию.
func (c *Client) Connect(ctx context.Context) error {
	destination := M.ParseSocksaddr(c.config.Server)
	if !destination.IsValid() || destination.Port == 0 {
		return fmt.Errorf("неверный UDP-адрес сервера Argon4 %q", c.config.Server)
	}
	conn, err := c.dialer.DialContext(ctx, N.NetworkUDP, destination)
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
		Capabilities:    capabilitiesForVersion(c.protocolVersion),
		PaddingProfile:  c.padding.Profile,
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
		response.MTU = EffectiveTunMTU(response.MTU, c.padding.PathMTU, c.protocolVersion)
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
	connected = true
	return nil
}

// IsTransit сообщает, перешла ли сессия в режим L4-транзита без виртуального TUN IP.
func (c *Client) IsTransit() bool {
	return c.transitMode
}

func (c *Client) handshake(ctx context.Context, conn net.Conn, wireRequest, handshakeKey []byte) (HandshakeResponse, error) {
	var lastErr error
	buffer := make([]byte, 65535)
	for attempt := 0; attempt < handshakeAttempts; attempt++ {
		if err := ctx.Err(); err != nil {
			return HandshakeResponse{}, err
		}
		deadline := time.Now().Add(handshakeTimeout)
		if contextDeadline, ok := ctx.Deadline(); ok && contextDeadline.Before(deadline) {
			deadline = contextDeadline
		}
		if err := conn.SetDeadline(deadline); err != nil {
			return HandshakeResponse{}, err
		}
		if _, err := conn.Write(wireRequest); err != nil {
			lastErr = err
			continue
		}
		for {
			n, err := conn.Read(buffer)
			if err != nil {
				lastErr = err
				break
			}
			version, frameType, salt, _, _, _, err := ParseAndVerifyHeader(buffer[:n], c.psk)
			if err != nil || frameType != FrameTypeHandshakeResp || !bytes.Equal(salt, c.salt[:]) {
				continue
			}
			opened, err := OpenPacketWithOptions(buffer[:n], c.psk, handshakeKey, OpenOptions{
				ExpectedVersion: c.protocolVersion,
				MaxOuterBytes:   c.maxOuterUDP,
			})
			if err != nil {
				if version != c.protocolVersion || errors.Is(err, ErrDowngradeRejected) || errors.Is(err, ErrBadVersion) ||
					errors.Is(err, ErrInvalidPadding) || errors.Is(err, ErrTrailingData) || errors.Is(err, ErrInvalidEnvelope) {
					return HandshakeResponse{}, err
				}
				continue
			}
			var response HandshakeResponse
			if err := json.Unmarshal(opened.Plaintext, &response); err != nil {
				return HandshakeResponse{}, fmt.Errorf("Argon4: неверный ответ handshake: %w", err)
			}
			if err := validateHandshakeProtocol(c.protocolVersion, response); err != nil {
				return HandshakeResponse{}, err
			}
			return response, nil
		}
	}
	if lastErr == nil {
		lastErr = context.DeadlineExceeded
	}
	return HandshakeResponse{}, fmt.Errorf("Argon4: сервер не ответил на handshake после %d попыток: %w", handshakeAttempts, lastErr)
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

func (c *Client) LocalAddr() net.Addr {
	if c.conn == nil {
		return nil
	}
	return c.conn.LocalAddr()
}

func (c *Client) RemoteAddr() net.Addr {
	if c.conn == nil {
		return nil
	}
	return c.conn.RemoteAddr()
}

func (c *Client) SetDeadline(t time.Time) error {
	if c.conn == nil {
		return net.ErrClosed
	}
	return c.conn.SetDeadline(t)
}

func (c *Client) SetReadDeadline(t time.Time) error {
	if c.conn == nil {
		return net.ErrClosed
	}
	return c.conn.SetReadDeadline(t)
}

func (c *Client) SetWriteDeadline(t time.Time) error {
	if c.conn == nil {
		return net.ErrClosed
	}
	return c.conn.SetWriteDeadline(t)
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
	packet, err := SealPacketWithOptions(FrameTypeData, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), plaintext, SealOptions{
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

// WriteKeepalive поддерживает UDP NAT и серверную сессию во время простоя.
func (c *Client) WriteKeepalive() error {
	if c.conn == nil {
		return net.ErrClosed
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
		if opened.FrameType != FrameTypeData {
			continue
		}
		plaintext := opened.Plaintext
		if !c.transitMode {
			if _, _, ok := ParseIPv4Addrs(plaintext); !ok {
				continue
			}
		}
		return plaintext, nil
	}
}

func (c *Client) Close() error {
	var err error
	c.closeOnce.Do(func() {
		if c.conn != nil {
			err = c.conn.Close()
		}
	})
	return err
}
