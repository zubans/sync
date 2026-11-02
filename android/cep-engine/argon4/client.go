package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"net"
	"net/netip"
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
	Server         string
	MasterPassword string
	ClientID       string
	ClientSecret   string
}

// Client — клиентская Argon4-сессия с раздельными ключами направлений и anti-replay.
// После Connect поля сессии неизменны; записывать пакеты можно из разных горутин.
type Client struct {
	config ClientConfig
	dialer N.Dialer

	conn      net.Conn
	response  HandshakeResponse
	salt      [SaltSize]byte
	psk       []byte
	c2sKey    []byte
	s2cKey    []byte
	seq       atomic.Uint64
	replay    ReplayWindow
	closeOnce sync.Once
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
	return &Client{config: config, dialer: dialer}, nil
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
		ClientID:   c.config.ClientID,
		ClientAuth: hex.EncodeToString(ClientAuthToken(c.psk, c.config.ClientID, c.config.ClientSecret)),
		ClientPub:  hex.EncodeToString(publicKey),
		Timestamp:  time.Now().Unix(),
	}
	plaintext, err := json.Marshal(request)
	if err != nil {
		return err
	}
	wireRequest, err := SealPacketWithSeq(FrameTypeHandshakeReq, c.salt[:], c.psk, handshakeKey, 1, plaintext)
	if err != nil {
		return err
	}

	response, err := c.handshake(ctx, conn, wireRequest, handshakeKey)
	if err != nil {
		return err
	}
	if err := validateHandshakeResponse(response); err != nil {
		return err
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
	connected = true
	return nil
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
			frameType, salt, _, plaintext, err := OpenPacketWithKey(buffer[:n], c.psk, handshakeKey)
			if err != nil || frameType != FrameTypeHandshakeResp || !bytes.Equal(salt, c.salt[:]) {
				continue
			}
			var response HandshakeResponse
			if err := json.Unmarshal(plaintext, &response); err != nil {
				return HandshakeResponse{}, fmt.Errorf("Argon4: неверный ответ handshake: %w", err)
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

// WritePacket шифрует один исходящий IPv4-пакет ключом c2s.
func (c *Client) WritePacket(plaintext []byte) error {
	if c.conn == nil {
		return net.ErrClosed
	}
	if _, _, ok := ParseIPv4Addrs(plaintext); !ok {
		return fmt.Errorf("Argon4 переносит только IPv4-пакеты")
	}
	packet, err := SealPacketWithSeq(FrameTypeData, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), plaintext)
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
	packet, err := SealPacketWithSeq(FrameTypeKeepalive, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), []byte("ping"))
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
	buffer := make([]byte, 65535)
	for {
		n, err := c.conn.Read(buffer)
		if err != nil {
			return nil, err
		}
		_, frameType, salt, _, seq, _, err := ParseAndVerifyHeader(buffer[:n], c.psk)
		if err != nil || !bytes.Equal(salt, c.salt[:]) || !c.replay.Check(seq) {
			continue
		}
		frameType, _, seq, plaintext, err := OpenPacketWithKey(buffer[:n], c.psk, c.s2cKey)
		if err != nil || !c.replay.Accept(seq) {
			continue
		}
		if frameType != FrameTypeData {
			continue
		}
		if _, _, ok := ParseIPv4Addrs(plaintext); !ok {
			continue
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
