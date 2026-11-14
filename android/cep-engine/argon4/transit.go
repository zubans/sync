package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"sync"
	"sync/atomic"
	"time"

	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
)

const (
	// TransitTCPChunkSize задаёт максимальный размер полезной нагрузки в одном TCP-кадре FrameTypeData.
	TransitTCPChunkSize = 16384
	// MaxTCPFrameSize ограничивает размер одного зашифрованного кадра Argon4 в TCP-потоке.
	MaxTCPFrameSize = 65535
)

// WriteTCPFrame записывает Argon4-кадр с 2-байтовым big-endian префиксом длины.
func WriteTCPFrame(w io.Writer, frame []byte) error {
	if len(frame) == 0 || len(frame) > MaxTCPFrameSize {
		return fmt.Errorf("некорректная длина TCP-кадра Argon4: %d", len(frame))
	}
	pkt := make([]byte, 2+len(frame))
	binary.BigEndian.PutUint16(pkt[:2], uint16(len(frame)))
	copy(pkt[2:], frame)
	_, err := w.Write(pkt)
	return err
}

// ReadTCPFrame считывает один Argon4-кадр по 2-байтовому префиксу длины.
func ReadTCPFrame(r io.Reader) ([]byte, error) {
	var hdr [2]byte
	if _, err := io.ReadFull(r, hdr[:]); err != nil {
		return nil, err
	}
	n := int(binary.BigEndian.Uint16(hdr[:]))
	if n == 0 {
		return nil, fmt.Errorf("пустой TCP-кадр Argon4")
	}
	buf := make([]byte, n)
	if _, err := io.ReadFull(r, buf); err != nil {
		return nil, err
	}
	return buf, nil
}

// TransitStreamConn реализует net.Conn поверх зашифрованного TCP-потока Argon4 L4-транзита.
type TransitStreamConn struct {
	raw       net.Conn
	response  HandshakeResponse
	salt      [SaltSize]byte
	psk       []byte
	c2sKey    []byte
	s2cKey    []byte
	version   byte
	padding   PaddingConfig
	timing    TimingConfig
	clock     Clock
	tracker   *ActivityTracker
	seq       atomic.Uint64
	replay    ReplayWindow
	readMu    sync.Mutex
	pending   []byte
	writeMu   sync.Mutex
	closeOnce sync.Once
}

// Timing возвращает нормализованную конфигурацию таймингов потокового транзита.
func (c *TransitStreamConn) Timing() TimingConfig {
	return c.timing
}

// Tracker возвращает трекер исходящей активности TCP-транзита.
func (c *TransitStreamConn) Tracker() *ActivityTracker {
	return c.tracker
}

// DialTransitTCP устанавливает зашифрованное TCP-соединение через переданный dialer sing-box.
func DialTransitTCP(ctx context.Context, config ClientConfig, dialer N.Dialer) (*TransitStreamConn, HandshakeResponse, error) {
	if config.Server == "" {
		return nil, HandshakeResponse{}, fmt.Errorf("не указан сервер Argon4")
	}
	if config.MasterPassword == "" {
		return nil, HandshakeResponse{}, fmt.Errorf("не задан мастер-пароль Argon4")
	}
	if dialer == nil {
		return nil, HandshakeResponse{}, fmt.Errorf("не задан dialer Argon4")
	}
	ver, pad, firstPad, _, err := resolveTransportOptions(config, false)
	if err != nil {
		return nil, HandshakeResponse{}, err
	}
	timingCfg, err := NormalizeTimingConfig(config.Timing)
	if err != nil {
		return nil, HandshakeResponse{}, err
	}
	clk := config.Clock
	if clk == nil {
		clk = RealClock{}
	}
	destination := M.ParseSocksaddr(config.Server)
	if !destination.IsValid() || destination.Port == 0 {
		return nil, HandshakeResponse{}, fmt.Errorf("неверный TCP-адрес сервера Argon4 %q", config.Server)
	}

	raw, err := dialer.DialContext(ctx, N.NetworkTCP, destination)
	if err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("TCP-подключение к Argon4-транзиту %s: %w", config.Server, err)
	}
	if tc, ok := raw.(*net.TCPConn); ok {
		_ = tc.SetNoDelay(true)
	}

	ok := false
	defer func() {
		if !ok {
			_ = raw.Close()
		}
	}()

	var salt [SaltSize]byte
	if _, err := rand.Read(salt[:]); err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("соль Argon4: %w", err)
	}
	psk := DerivePSK(config.MasterPassword, DefaultParams)
	hsKey := DeriveKey(config.MasterPassword, salt[:], DefaultParams)
	defer clear(hsKey)

	privKey, pubKey, err := GenerateX25519Keypair()
	if err != nil {
		return nil, HandshakeResponse{}, err
	}
	defer clear(privKey)

	mode := config.Mode
	if mode == "" || mode == ModeAuto {
		mode = ModeTransit
	}
	req := HandshakeRequest{
		ClientID:        config.ClientID,
		ClientAuth:      hex.EncodeToString(ClientAuthToken(psk, config.ClientID, config.ClientSecret)),
		ClientPub:       hex.EncodeToString(pubKey),
		Timestamp:       time.Now().Unix(),
		Mode:            mode,
		Target:          config.Target,
		Proto:           ProtoTCP,
		ProtocolVersion: int(ver),
		Capabilities:    capabilitiesForVersion(ver),
		PaddingProfile:  pad.Profile,
	}
	reqBytes, err := json.Marshal(req)
	if err != nil {
		return nil, HandshakeResponse{}, err
	}
	wireReq, err := SealPacketWithOptions(FrameTypeHandshakeReq, salt[:], psk, hsKey, 1, reqBytes, SealOptions{
		Version:       ver,
		Padding:       firstPad,
		MaxOuterBytes: MaxTCPFrameSize,
	})
	if err != nil {
		return nil, HandshakeResponse{}, err
	}

	deadline := clk.Now().Add(timingCfg.HandshakeMaxElapsed)
	if ctxDeadline, hasDeadline := ctx.Deadline(); hasDeadline && ctxDeadline.Before(deadline) {
		deadline = ctxDeadline
	}
	_ = raw.SetDeadline(deadline)
	if err := WriteTCPFrame(raw, wireReq); err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("отправка TCP handshake Argon4: %w", err)
	}
	wireResp, err := ReadTCPFrame(raw)
	if err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("чтение ответа TCP handshake Argon4: %w", err)
	}
	_ = raw.SetDeadline(time.Time{})

	respVer, ft, respSalt, _, _, _, herr := ParseAndVerifyHeader(wireResp, psk)
	if herr != nil || ft != FrameTypeHandshakeResp || !bytes.Equal(respSalt, salt[:]) {
		return nil, HandshakeResponse{}, fmt.Errorf("Argon4 TCP transit: неверный ответ handshake")
	}
	if ver == ProtocolVersionV5 && respVer == ProtocolVersionV4 {
		return nil, HandshakeResponse{}, ErrDowngradeRejected
	}
	opened, err := OpenPacketWithOptions(wireResp, psk, hsKey, OpenOptions{
		ExpectedVersion: ver,
		MaxOuterBytes:   MaxTCPFrameSize,
	})
	if err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("Argon4 TCP transit: %w", err)
	}
	var resp HandshakeResponse
	if err := json.Unmarshal(opened.Plaintext, &resp); err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("Argon4 TCP transit: ошибка JSON handshake: %w", err)
	}
	if err := validateHandshakeProtocol(ver, resp); err != nil {
		return nil, HandshakeResponse{}, err
	}
	srvPub, err := hex.DecodeString(resp.ServerPub)
	if err != nil || len(srvPub) != X25519KeySize {
		return nil, HandshakeResponse{}, fmt.Errorf("Argon4 TCP transit: некорректный открытый ключ сервера")
	}
	shared, err := ComputeX25519Shared(privKey, srvPub)
	if err != nil {
		return nil, HandshakeResponse{}, fmt.Errorf("Argon4 TCP X25519: %w", err)
	}
	c2sKey, s2cKey := DeriveSessionKeys(hsKey, shared, salt[:])
	clear(shared)

	if resp.Mode == "" {
		resp.Mode = ModeTransit
	}
	if resp.ProtocolVersion == 0 {
		resp.ProtocolVersion = int(ver)
	}
	stream := &TransitStreamConn{
		raw:      raw,
		response: resp,
		salt:     salt,
		psk:      psk,
		c2sKey:   c2sKey,
		s2cKey:   s2cKey,
		version:  ver,
		padding:  pad,
		timing:   timingCfg,
		clock:    clk,
		tracker:  NewActivityTracker(clk),
	}
	ok = true
	return stream, resp, nil
}

func (c *TransitStreamConn) Response() HandshakeResponse {
	return c.response
}

func (c *TransitStreamConn) Read(p []byte) (int, error) {
	if len(p) == 0 {
		return 0, nil
	}
	c.readMu.Lock()
	defer c.readMu.Unlock()

	for len(c.pending) == 0 {
		frame, err := ReadTCPFrame(c.raw)
		if err != nil {
			return 0, err
		}
		version, _, salt, _, seq, _, err := ParseAndVerifyHeader(frame, c.psk)
		if err != nil {
			return 0, err
		}
		ver := c.version
		if ver == 0 {
			ver = ProtocolVersionV5
		}
		if version != ver {
			if ver == ProtocolVersionV5 && version == ProtocolVersionV4 {
				return 0, ErrDowngradeRejected
			}
			return 0, ErrBadVersion
		}
		if !bytes.Equal(salt, c.salt[:]) || !c.replay.Check(seq) {
			continue
		}
		opened, err := OpenPacketWithOptions(frame, c.psk, c.s2cKey, OpenOptions{
			ExpectedVersion: ver,
			MaxOuterBytes:   MaxTCPFrameSize,
		})
		if err != nil {
			return 0, err
		}
		if !c.replay.Accept(opened.Seq) {
			continue
		}
		if opened.FrameType == FrameTypeKeepalive {
			continue
		}
		if opened.FrameType != FrameTypeData || len(opened.Plaintext) == 0 {
			continue
		}
		c.pending = opened.Plaintext
	}

	n := copy(p, c.pending)
	c.pending = c.pending[n:]
	return n, nil
}

func (c *TransitStreamConn) writeFrameLocked(frameType byte, payload []byte) error {
	ver := c.version
	if ver == 0 {
		ver = ProtocolVersionV5
	}
	wire, err := SealPacketWithOptions(frameType, c.salt[:], c.psk, c.c2sKey, c.seq.Add(1), payload, SealOptions{
		Version:       ver,
		Padding:       c.padding,
		MaxOuterBytes: MaxTCPFrameSize,
	})
	if err != nil {
		return err
	}
	return WriteTCPFrame(c.raw, wire)
}

func (c *TransitStreamConn) Write(p []byte) (int, error) {
	if len(p) == 0 {
		return 0, nil
	}
	c.writeMu.Lock()
	defer c.writeMu.Unlock()

	written := 0
	for len(p) > 0 {
		chunk := p
		if len(chunk) > TransitTCPChunkSize {
			chunk = p[:TransitTCPChunkSize]
		}
		if err := c.writeFrameLocked(FrameTypeData, chunk); err != nil {
			return written, err
		}
		c.tracker.Touch()
		written += len(chunk)
		p = p[len(chunk):]
	}
	return written, nil
}

// WriteKeepalive отправляет защищённый keepalive-кадр поверх TCP-потока транзита.
func (c *TransitStreamConn) WriteKeepalive() error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()
	return c.writeFrameLocked(FrameTypeKeepalive, []byte("ping"))
}

// CloseWrite сигнализирует удалённому серверу о завершении записи потока (half-close).
func (c *TransitStreamConn) CloseWrite() error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()
	return c.writeFrameLocked(FrameTypeKeepalive, []byte("fin"))
}

func (c *TransitStreamConn) Close() error {
	var err error
	c.closeOnce.Do(func() {
		err = c.raw.Close()
	})
	return err
}

func (c *TransitStreamConn) LocalAddr() net.Addr                { return c.raw.LocalAddr() }
func (c *TransitStreamConn) RemoteAddr() net.Addr               { return c.raw.RemoteAddr() }
func (c *TransitStreamConn) SetDeadline(t time.Time) error      { return c.raw.SetDeadline(t) }
func (c *TransitStreamConn) SetReadDeadline(t time.Time) error  { return c.raw.SetReadDeadline(t) }
func (c *TransitStreamConn) SetWriteDeadline(t time.Time) error { return c.raw.SetWriteDeadline(t) }
