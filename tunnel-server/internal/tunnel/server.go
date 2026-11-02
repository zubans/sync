package tunnel

import (
	"bytes"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"sync"
	"sync/atomic"
	"time"
)

// ServerConfig описывает конфигурацию серверного демона Argon4 на Ubuntu.
type ServerConfig struct {
	ListenInterface string
	ListenAddr      string
	TunName         string
	SubnetCIDR      string
	OutInterface    string
	EnableNAT       bool
	MasterPassword  string
	ArgonParams     ArgonParams
	MTU             int
	DNS             []string
	Routes          []string
}

type handshakeResponseCacheEntry struct {
	remoteAddr string
	request    []byte
	response   []byte
	expires    time.Time
}

// Server реализует шифрованный шлюз-надстройку Argon4 между UDP-интерфейсом и виртуальным TUN-интерфейсом Ubuntu.
type Server struct {
	cfg            ServerConfig
	sessions       *SessionManager
	udpConn        *net.UDPConn
	tun            io.ReadWriteCloser
	lastErrLog     atomic.Int64
	handshakeMu    sync.Mutex
	handshakeCache map[[SaltSize]byte]handshakeResponseCacheEntry
}

func NewServer(cfg ServerConfig, tunDev io.ReadWriteCloser) (*Server, error) {
	if cfg.MasterPassword == "" {
		return nil, fmt.Errorf("не задан мастер-пароль")
	}
	if cfg.MTU <= 0 {
		cfg.MTU = 1380
	}
	if cfg.SubnetCIDR == "" {
		cfg.SubnetCIDR = "10.44.0.1/24"
	}
	if len(cfg.Routes) == 0 {
		cfg.Routes = []string{"0.0.0.0/0"}
	}

	sm, err := NewSessionManager(cfg.MasterPassword, cfg.ArgonParams, cfg.SubnetCIDR, cfg.MTU, cfg.DNS, cfg.Routes)
	if err != nil {
		return nil, err
	}

	return &Server{
		cfg:            cfg,
		sessions:       sm,
		tun:            tunDev,
		handshakeCache: make(map[[SaltSize]byte]handshakeResponseCacheEntry),
	}, nil
}

// ResolveInterfaceUDPAddr вычисляет UDP-адрес прослушивания с привязкой к конкретному сетевому интерфейсу Ubuntu, если он задан.
func ResolveInterfaceUDPAddr(ifName, listenAddr string) (*net.UDPAddr, error) {
	udpAddr, err := net.ResolveUDPAddr("udp4", listenAddr)
	if err != nil {
		return nil, err
	}
	if ifName == "" {
		return udpAddr, nil
	}
	iface, err := net.InterfaceByName(ifName)
	if err != nil {
		return nil, fmt.Errorf("сетевой интерфейс %q не найден: %w", ifName, err)
	}
	addrs, err := iface.Addrs()
	if err != nil {
		return nil, fmt.Errorf("адреса интерфейса %q: %w", ifName, err)
	}
	for _, a := range addrs {
		var ip net.IP
		switch v := a.(type) {
		case *net.IPNet:
			ip = v.IP
		case *net.IPAddr:
			ip = v.IP
		}
		if ip4 := ip.To4(); ip4 != nil {
			udpAddr.IP = ip4
			return udpAddr, nil
		}
	}
	return nil, fmt.Errorf("на интерфейсе %q нет IPv4-адреса", ifName)
}

func (s *Server) StartUDP() error {
	addr, err := ResolveInterfaceUDPAddr(s.cfg.ListenInterface, s.cfg.ListenAddr)
	if err != nil {
		return err
	}
	conn, err := net.ListenUDP("udp4", addr)
	if err != nil {
		return err
	}
	s.udpConn = conn
	log.Printf("[argon4-server] приём пакетов на UDP %s (интерфейс=%q, tun=%q, подсеть=%s, выход=%q)",
		conn.LocalAddr().String(), s.cfg.ListenInterface, s.cfg.TunName, s.cfg.SubnetCIDR, s.cfg.OutInterface)
	return nil
}

func (s *Server) LocalAddr() net.Addr {
	if s.udpConn == nil {
		return nil
	}
	return s.udpConn.LocalAddr()
}

// Run запускает двунаправленную пересылку пакетов между UDP-сокетом и виртуальным TUN-интерфейсом.
func (s *Server) Run() error {
	errCh := make(chan error, 2)
	go func() {
		errCh <- s.udpToTunLoop()
	}()
	go func() {
		errCh <- s.tunToUdpLoop()
	}()
	return <-errCh
}

func (s *Server) Close() error {
	if s.udpConn != nil {
		_ = s.udpConn.Close()
	}
	if s.tun != nil {
		_ = s.tun.Close()
	}
	return nil
}

func (s *Server) logDrop(remoteAddr *net.UDPAddr, msg string, err error) {
	now := time.Now().UnixNano()
	prev := s.lastErrLog.Load()
	if now-prev >= int64(2*time.Second) && s.lastErrLog.CompareAndSwap(prev, now) {
		log.Printf("[argon4-server] пакет от %s отброшен (%s): %v", remoteAddr, msg, err)
	}
}

func (s *Server) udpToTunLoop() error {
	buf := make([]byte, 65535)
	for {
		n, remoteAddr, err := s.udpConn.ReadFromUDP(buf)
		if err != nil {
			return err
		}
		pkt := buf[:n]
		s.handleUDPPacket(pkt, remoteAddr)
	}
}

// HandlePacket обрабатывает один входящий зашифрованный кадр от remoteAddr и при необходимости возвращает зашифрованный ответ.
func (s *Server) HandlePacket(pkt []byte, remoteAddr *net.UDPAddr) []byte {
	psk := s.sessions.PSK()
	_, frameType, salt, _, seq, _, err := ParseAndVerifyHeader(pkt, psk)
	if err != nil {
		s.logDrop(remoteAddr, "header", err)
		return nil
	}

	if frameType == FrameTypeHandshakeReq {
		log.Printf("[argon4-server] получен handshake от %s", remoteAddr)
		return s.handleHandshake(pkt, psk, salt, remoteAddr)
	}

	sess := s.sessions.LookupBySalt(salt)
	if sess == nil {
		// Сессия не установлена рукопожатием — пакеты данных и keepalive без рукопожатия отбрасываются без запуска Argon2id.
		s.logDrop(remoteAddr, "no session", fmt.Errorf("frame type %d", frameType))
		return nil
	}
	if !sess.CheckReplay(seq) {
		s.logDrop(remoteAddr, "replay pre-check", ErrReplayPacket)
		return nil
	}
	_, _, _, plaintext, err := OpenPacketWithKey(pkt, psk, sess.C2SKey)
	if err != nil {
		s.logDrop(remoteAddr, "c2s auth", err)
		return nil
	}
	if !sess.AcceptReplay(seq) {
		s.logDrop(remoteAddr, "replay commit", ErrReplayPacket)
		return nil
	}

	// Обновляем адрес клиента только после успешной проверки GCM-тега и защиты от повтора (невозможно угнать сессию повтором пакета).
	s.sessions.TouchSession(sess, remoteAddr)

	switch frameType {
	case FrameTypeKeepalive:
		wirePong, err := SealPacketWithSeq(FrameTypeKeepalive, sess.Salt[:], psk, sess.S2CKey, sess.NextS2CSeq(), []byte("pong"))
		if err == nil {
			return wirePong
		}

	case FrameTypeData:
		srcIP, _, ok := ParseIPv4Addrs(plaintext)
		if !ok {
			return nil
		}
		// Запрещаем подмену внутреннего адреса отправителя (IP spoofing между клиентами туннеля).
		if !srcIP.Equal(sess.AssignedIP) {
			s.logDrop(remoteAddr, "inner src IP mismatch", fmt.Errorf("got %s want %s", srcIP, sess.AssignedIP))
			return nil
		}
		if s.tun != nil && len(plaintext) > 0 {
			_, _ = s.tun.Write(plaintext)
		}
	}
	return nil
}

// cachedHandshakeResponse делает повторную UDP-передачу идемпотентной: тот же запрос
// с того же адреса получает тот же ответ без новой сессии и нового расчёта Argon2id.
func (s *Server) cachedHandshakeResponse(pkt, salt []byte, remoteAddr *net.UDPAddr) []byte {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	s.handshakeMu.Lock()
	defer s.handshakeMu.Unlock()
	entry, ok := s.handshakeCache[saltKey]
	if !ok {
		return nil
	}
	if !time.Now().Before(entry.expires) {
		delete(s.handshakeCache, saltKey)
		return nil
	}
	if entry.remoteAddr != remoteAddr.String() || !bytes.Equal(entry.request, pkt) {
		return nil
	}
	return append([]byte(nil), entry.response...)
}

func (s *Server) cacheHandshakeResponse(pkt, salt []byte, remoteAddr *net.UDPAddr, response []byte) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := time.Now()
	s.handshakeMu.Lock()
	for key, entry := range s.handshakeCache {
		if !now.Before(entry.expires) {
			delete(s.handshakeCache, key)
		}
	}
	s.handshakeCache[saltKey] = handshakeResponseCacheEntry{
		remoteAddr: remoteAddr.String(),
		request:    append([]byte(nil), pkt...),
		response:   append([]byte(nil), response...),
		expires:    now.Add(HandshakeReplayTTL),
	}
	s.handshakeMu.Unlock()
}

func (s *Server) handleHandshake(pkt, psk, salt []byte, remoteAddr *net.UDPAddr) []byte {
	if cached := s.cachedHandshakeResponse(pkt, salt, remoteAddr); cached != nil {
		return cached
	}

	hsKey, err := s.sessions.DeriveHandshakeKey(salt)
	if err != nil {
		s.logDrop(remoteAddr, "handshake derive", err)
		return nil
	}
	_, _, _, plaintext, err := OpenPacketWithKey(pkt, psk, hsKey)
	if err != nil {
		s.logDrop(remoteAddr, "handshake open", err)
		return nil
	}

	var req HandshakeRequest
	if err := json.Unmarshal(plaintext, &req); err != nil {
		s.logDrop(remoteAddr, "handshake json", err)
		return nil
	}

	now := time.Now()
	reqTime := time.Unix(req.Timestamp, 0)
	if now.Sub(reqTime).Abs() > HandshakeClockSkew {
		s.logDrop(remoteAddr, "handshake timestamp", ErrClockSkew)
		return nil
	}

	clientPub, err := hex.DecodeString(req.ClientPub)
	if err != nil || len(clientPub) != X25519KeySize {
		s.logDrop(remoteAddr, "handshake clientPub", fmt.Errorf("некорректный ключ X25519"))
		return nil
	}

	var clientAuth []byte
	if req.ClientAuth != "" {
		clientAuth, _ = hex.DecodeString(req.ClientAuth)
	}

	srvPriv, srvPub, err := GenerateX25519Keypair()
	if err != nil {
		return nil
	}
	sharedSecret, err := ComputeX25519Shared(srvPriv, clientPub)
	for i := range srvPriv {
		srvPriv[i] = 0
	}
	if err != nil {
		s.logDrop(remoteAddr, "handshake ecdh", err)
		return nil
	}

	c2sKey, s2cKey := DeriveSessionKeys(hsKey, sharedSecret, salt)
	for i := range sharedSecret {
		sharedSecret[i] = 0
	}

	peer, err := s.sessions.RegisterSession(req.ClientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr)
	if err != nil {
		s.logDrop(remoteAddr, "handshake register", err)
		return nil
	}

	resp := HandshakeResponse{
		AssignedIP: peer.AssignedIP.String(),
		ServerIP:   s.sessions.ServerIP().String(),
		SubnetCidr: s.sessions.SubnetPrefixLen(),
		MTU:        s.cfg.MTU,
		DNS:        s.cfg.DNS,
		Routes:     s.cfg.Routes,
		ServerTime: now.Unix(),
		ServerPub:  hex.EncodeToString(srvPub),
	}
	respBytes, _ := json.Marshal(resp)
	wireResp, err := SealPacketWithSeq(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes)
	if err != nil {
		return nil
	}
	s.cacheHandshakeResponse(pkt, salt, remoteAddr, wireResp)
	log.Printf("[argon4-server] рукопожатие ОК: клиент=%q адрес=%s выдан=%s приложения=%v",
		req.ClientID, remoteAddr, peer.AssignedIP, req.Apps)
	return wireResp
}

// SealTunPacket шифрует исходящий IPv4-пакет из интерфейса TUN ключом s2c соответствующей клиентской сессии.
func (s *Server) SealTunPacket(ipPkt []byte) ([]byte, *net.UDPAddr) {
	_, dstIP, ok := ParseIPv4Addrs(ipPkt)
	if !ok {
		return nil, nil
	}
	sess := s.sessions.LookupByDstIP(dstIP)
	if sess == nil {
		return nil, nil
	}
	wirePkt, err := SealPacketWithSeq(FrameTypeData, sess.Salt[:], s.sessions.PSK(), sess.S2CKey, sess.NextS2CSeq(), ipPkt)
	if err != nil {
		return nil, nil
	}
	return wirePkt, sess.RemoteAddr()
}

func (s *Server) handleUDPPacket(pkt []byte, remoteAddr *net.UDPAddr) {
	if resp := s.HandlePacket(pkt, remoteAddr); resp != nil && s.udpConn != nil {
		_, _ = s.udpConn.WriteToUDP(resp, remoteAddr)
	}
}

func (s *Server) tunToUdpLoop() error {
	if s.tun == nil {
		select {}
	}
	buf := make([]byte, 65535)
	for {
		n, err := s.tun.Read(buf)
		if err != nil {
			return err
		}
		if wirePkt, addr := s.SealTunPacket(buf[:n]); wirePkt != nil && addr != nil && s.udpConn != nil {
			_, _ = s.udpConn.WriteToUDP(wirePkt, addr)
		}
	}
}
