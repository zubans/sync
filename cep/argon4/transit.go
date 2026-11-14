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
	"log"
	"net"
	"strings"
	"sync"
	"sync/atomic"
	"time"
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
	seq       atomic.Uint64
	replay    ReplayWindow
	readMu    sync.Mutex
	pending   []byte
	writeMu   sync.Mutex
	closeOnce sync.Once
}

// DialTransitTCP устанавливает зашифрованное TCP-соединение с транзитным сервером Argon4 к config.Target.
func DialTransitTCP(ctx context.Context, config ClientConfig) (*TransitStreamConn, HandshakeResponse, error) {
	if config.Server == "" {
		return nil, HandshakeResponse{}, fmt.Errorf("не указан сервер Argon4")
	}
	if config.MasterPassword == "" {
		return nil, HandshakeResponse{}, fmt.Errorf("не задан мастер-пароль Argon4")
	}
	ver, pad, firstPad, _, err := resolveTransportOptions(config, false)
	if err != nil {
		return nil, HandshakeResponse{}, err
	}
	raw, err := (&net.Dialer{Timeout: 10 * time.Second}).DialContext(ctx, "tcp", config.Server)
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

	deadline := time.Now().Add(10 * time.Second)
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

// TransitProxy запускает локальный 127.0.0.1 TCP/UDP ретранслятор поверх Argon4-транзита.
type TransitProxy struct {
	config    ClientConfig
	response  HandshakeResponse
	tcpLn     net.Listener
	udpLn     *net.UDPConn
	udpClient *Client
	port      int
	listen    string
	done      chan struct{}
	closeOnce sync.Once
}

// ConnectAuto выполняет UDP-рукопожатие и определяет режим сервера ("tunnel" или "transit").
// Если сервер работает в режиме "tunnel", возвращает готовый UDP TUN Client и nil в качестве TransitProxy.
func ConnectAuto(ctx context.Context, config ClientConfig, listenAddr string) (*TransitProxy, *Client, error) {
	probeCfg := config
	if probeCfg.Mode == "" {
		probeCfg.Mode = ModeAuto
	}
	hsCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()

	client, err := NewClient(probeCfg)
	if err != nil {
		return nil, nil, err
	}
	if err := client.Connect(hsCtx); err != nil {
		return nil, nil, err
	}
	if !client.IsTransit() {
		return nil, client, nil
	}

	resp := client.Response()
	proxyCfg := config
	proxyCfg.Mode = ModeTransit
	if resp.Target != "" {
		proxyCfg.Target = resp.Target
	}

	proto := strings.ToLower(strings.TrimSpace(config.Proto))
	if proto == ProtoUDP {
		proxy, err := startTransitProxyWithUDP(ctx, proxyCfg, resp, listenAddr, client, false)
		if err != nil {
			_ = client.Close()
			return nil, nil, err
		}
		return proxy, nil, nil
	}
	_ = client.Close()
	proxy, err := startTransitProxyWithUDP(ctx, proxyCfg, resp, listenAddr, nil, proto == "")
	if err != nil {
		return nil, nil, err
	}
	return proxy, nil, nil
}

// StartTransitProxy запускает локальный 127.0.0.1 порт, туннелирующий TCP и/или UDP трафик через Argon4-транзит.
func StartTransitProxy(ctx context.Context, config ClientConfig, listenAddr string) (*TransitProxy, error) {
	config.Mode = ModeTransit
	proto := strings.ToLower(strings.TrimSpace(config.Proto))
	hsCtx, cancel := context.WithTimeout(ctx, 20*time.Second)
	defer cancel()

	switch proto {
	case ProtoUDP:
		udpCfg := config
		udpCfg.Proto = ProtoUDP
		client, err := NewClient(udpCfg)
		if err != nil {
			return nil, err
		}
		if err := client.Connect(hsCtx); err != nil {
			return nil, err
		}
		proxy, err := startTransitProxyWithUDP(ctx, udpCfg, client.Response(), listenAddr, client, false)
		if err != nil {
			_ = client.Close()
			return nil, err
		}
		return proxy, nil

	case ProtoTCP:
		firstConn, resp, err := DialTransitTCP(hsCtx, config)
		if err != nil {
			return nil, err
		}
		if resp.Target != "" {
			config.Target = resp.Target
		}
		proxy, err := startTCPProxy(ctx, config, resp, listenAddr, firstConn, nil)
		if err != nil {
			_ = firstConn.Close()
			return nil, err
		}
		return proxy, nil

	default:
		// Универсальный режим ("" — и TCP, и UDP на одном локальном порту): проверяем оба.
		udpCfg := config
		udpCfg.Proto = ProtoUDP
		client, err := NewClient(udpCfg)
		if err != nil {
			return nil, err
		}
		if err := client.Connect(hsCtx); err != nil {
			return nil, err
		}
		resp := client.Response()
		if resp.Target != "" {
			config.Target = resp.Target
		}
		proxy, err := startTransitProxyWithUDP(ctx, config, resp, listenAddr, client, true)
		if err != nil {
			_ = client.Close()
			return nil, err
		}
		return proxy, nil
	}
}

func startTransitProxyWithUDP(ctx context.Context, config ClientConfig, resp HandshakeResponse, listenAddr string, udpClient *Client, withTCP bool) (*TransitProxy, error) {
	if listenAddr == "" {
		listenAddr = "127.0.0.1:0"
	}
	if withTCP || strings.EqualFold(config.Proto, ProtoTCP) {
		return startTCPProxy(ctx, config, resp, listenAddr, nil, udpClient)
	}

	udpAddr, err := net.ResolveUDPAddr("udp4", listenAddr)
	if err != nil {
		return nil, err
	}
	udpLn, err := net.ListenUDP("udp4", udpAddr)
	if err != nil {
		return nil, err
	}
	port := udpLn.LocalAddr().(*net.UDPAddr).Port
	p := &TransitProxy{
		config:    config,
		response:  resp,
		udpLn:     udpLn,
		udpClient: udpClient,
		port:      port,
		listen:    udpLn.LocalAddr().String(),
		done:      make(chan struct{}),
	}
	go p.runUDPRelay()
	go func() {
		<-ctx.Done()
		_ = p.Close()
	}()
	return p, nil
}

func startTCPProxy(ctx context.Context, config ClientConfig, resp HandshakeResponse, listenAddr string, firstConn *TransitStreamConn, udpClient *Client) (*TransitProxy, error) {
	if listenAddr == "" {
		listenAddr = "127.0.0.1:0"
	}
	ln, err := net.Listen("tcp4", listenAddr)
	if err != nil {
		return nil, err
	}
	tcpAddr := ln.Addr().(*net.TCPAddr)
	var udpLn *net.UDPConn
	if udpClient != nil {
		udpLn, err = net.ListenUDP("udp4", &net.UDPAddr{IP: tcpAddr.IP, Port: tcpAddr.Port})
		if err != nil {
			_ = ln.Close()
			return nil, err
		}
	}

	p := &TransitProxy{
		config:    config,
		response:  resp,
		tcpLn:     ln,
		udpLn:     udpLn,
		udpClient: udpClient,
		port:      tcpAddr.Port,
		listen:    ln.Addr().String(),
		done:      make(chan struct{}),
	}
	if udpLn != nil {
		go p.runUDPRelay()
	}
	go p.runTCPAccept(firstConn)
	go func() {
		<-ctx.Done()
		_ = p.Close()
	}()
	return p, nil
}

func (p *TransitProxy) ListenAddr() string          { return p.listen }
func (p *TransitProxy) Port() int                   { return p.port }
func (p *TransitProxy) Target() string              { return p.response.Target }
func (p *TransitProxy) MTU() int                    { return p.response.MTU }
func (p *TransitProxy) Response() HandshakeResponse { return p.response }
func (p *TransitProxy) Done() <-chan struct{}       { return p.done }

func (p *TransitProxy) Close() error {
	p.closeOnce.Do(func() {
		if p.tcpLn != nil {
			_ = p.tcpLn.Close()
		}
		if p.udpLn != nil {
			_ = p.udpLn.Close()
		}
		if p.udpClient != nil {
			_ = p.udpClient.Close()
		}
		close(p.done)
	})
	return nil
}

func (p *TransitProxy) runTCPAccept(firstConn *TransitStreamConn) {
	defer func() {
		if firstConn != nil {
			_ = firstConn.Close()
		}
	}()
	nextPre := firstConn
	for {
		localConn, err := p.tcpLn.Accept()
		if err != nil {
			return
		}
		pre := nextPre
		nextPre = nil
		go p.handleLocalTCP(localConn, pre)
	}
}

func (p *TransitProxy) handleLocalTCP(localConn net.Conn, pre *TransitStreamConn) {
	defer localConn.Close()
	if tc, ok := localConn.(*net.TCPConn); ok {
		_ = tc.SetNoDelay(true)
	}

	remote := pre
	if remote == nil {
		ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		var err error
		remote, _, err = DialTransitTCP(ctx, p.config)
		cancel()
		if err != nil {
			log.Printf("Argon4 TCP-транзит dial (%s): %v", p.config.Server, err)
			return
		}
	}
	defer remote.Close()

	kaStop := make(chan struct{})
	defer close(kaStop)
	go func() {
		select {
		case <-p.done:
			_ = localConn.Close()
			_ = remote.Close()
		case <-kaStop:
		}
	}()
	go func() {
		ticker := time.NewTicker(25 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-kaStop:
				return
			case <-p.done:
				return
			case <-ticker.C:
				if err := remote.WriteKeepalive(); err != nil {
					return
				}
			}
		}
	}()

	toRemoteDone := make(chan error, 1)
	fromRemoteDone := make(chan error, 1)
	go func() {
		_, err := io.Copy(remote, localConn)
		if err == nil {
			_ = remote.CloseWrite()
		}
		toRemoteDone <- err
	}()
	go func() {
		_, err := io.Copy(localConn, remote)
		fromRemoteDone <- err
	}()

	select {
	case <-fromRemoteDone:
		_ = remote.Close()
		_ = localConn.Close()
		<-toRemoteDone
	case err := <-toRemoteDone:
		if err != nil {
			_ = remote.Close()
			_ = localConn.Close()
			<-fromRemoteDone
		} else {
			<-fromRemoteDone
			_ = remote.Close()
			_ = localConn.Close()
		}
	}
}

func (p *TransitProxy) runUDPRelay() {
	var lastPeer atomic.Pointer[net.UDPAddr]
	stop := make(chan struct{})
	defer close(stop)

	// Клиент UDP-транзита -> локальный UDP-клиент.
	go func() {
		for {
			pkt, err := p.udpClient.ReadPacket()
			if err != nil {
				return
			}
			peer := lastPeer.Load()
			if peer != nil {
				_, _ = p.udpLn.WriteToUDP(pkt, peer)
			}
		}
	}()

	// Keepalive на время простоя.
	go func() {
		ticker := time.NewTicker(25 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-stop:
				return
			case <-p.done:
				return
			case <-ticker.C:
				if err := p.udpClient.WriteKeepalive(); err != nil {
					return
				}
			}
		}
	}()

	buf := make([]byte, 65535)
	for {
		n, peerAddr, err := p.udpLn.ReadFromUDP(buf)
		if err != nil {
			return
		}
		if peerAddr != nil {
			clone := *peerAddr
			clone.IP = append(net.IP(nil), peerAddr.IP...)
			lastPeer.Store(&clone)
		}
		if err := p.udpClient.WritePacket(buf[:n]); err != nil {
			return
		}
	}
}
