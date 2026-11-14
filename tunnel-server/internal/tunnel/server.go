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
	ListenInterface    string
	ListenAddr         string
	TunName            string
	SubnetCIDR         string
	OutInterface       string
	EnableNAT          bool
	MasterPassword     string
	ArgonParams        ArgonParams
	MTU                int
	DNS                []string
	Routes             []string
	Mode               string
	TransitTarget      string
	TransitAllow       []string
	TransitBlind       bool
	ProtocolVersion      byte
	DisallowV4           bool
	Padding              PaddingConfig
	BehaviorProfile      string
	DisallowVideoCall    bool
	VideoCallMaxDuration time.Duration
	SessionIdleTimeout   time.Duration
	HandshakeCacheTTL    time.Duration
	BlindUDPIdleTimeout  time.Duration
	Now                  func() time.Time
	WriteUDP             func(wire []byte, dst *net.UDPAddr) error
	LookupIP             func(host string) ([]net.IP, error)
}

type handshakeResponseCacheEntry struct {
	remoteAddr string
	request    []byte
	response   []byte
	expires    time.Time
}

// Server реализует шифрованный шлюз Argon4 (режим tunnel поверх TUN и режим L4 transit поверх UDP/TCP).
type Server struct {
	cfg               ServerConfig
	protocolVersion   byte
	padding           PaddingConfig
	maxOuterUDP       int
	handshakeCacheTTL time.Duration
	blindIdleTimeout  time.Duration
	nowFunc           func() time.Time
	lookupIP          func(host string) ([]net.IP, error)
	sessions          *SessionManager
	udpConn           *net.UDPConn
	tcpListener       *net.TCPListener
	tun               io.ReadWriteCloser
	lastErrLog        atomic.Int64
	handshakeMu       sync.Mutex
	handshakeCache    map[[SaltSize]byte]handshakeResponseCacheEntry
	blindMu           sync.Mutex
	blindUDP          map[string]*blindUDPPeer
}

func NewServer(cfg ServerConfig, tunDev io.ReadWriteCloser) (*Server, error) {
	if cfg.Mode == "" {
		cfg.Mode = ModeTunnel
	}
	if cfg.Mode != ModeTunnel && cfg.Mode != ModeTransit {
		return nil, fmt.Errorf("неизвестный режим сервера %q (ожидается %q или %q)", cfg.Mode, ModeTunnel, ModeTransit)
	}
	effVersion := cfg.ProtocolVersion
	if effVersion == 0 {
		effVersion = ProtocolVersionV5
	}
	if effVersion != ProtocolVersionV4 && effVersion != ProtocolVersionV5 {
		return nil, fmt.Errorf("%w: 0x%02x", ErrBadVersion, effVersion)
	}
	if effVersion == ProtocolVersionV4 && cfg.DisallowV4 {
		return nil, fmt.Errorf("несовместимая конфигурация: выбрана версия v4 при запрете v4")
	}

	resolvedPadding, err := NormalizePaddingConfig(cfg.Padding, !cfg.TransitBlind)
	if err != nil {
		return nil, err
	}
	allowVC := !cfg.DisallowVideoCall
	if _, _, err := ValidateProfileRequest(effVersion, cfg.Mode, "", ProfileConfig{
		Name:           cfg.BehaviorProfile,
		AllowVideoCall: &allowVC,
		MaxDuration:    cfg.VideoCallMaxDuration,
	}); err != nil {
		return nil, err
	}
	maxOuterUDP := MaxOuterBytesForPathMTU(resolvedPadding.PathMTU)

	if cfg.TransitBlind {
		if cfg.Mode != ModeTransit {
			return nil, fmt.Errorf("режим -transit-blind поддерживается только при -mode %s", ModeTransit)
		}
		if cfg.TransitTarget == "" {
			return nil, fmt.Errorf("для -transit-blind требуется указать -transit-target")
		}
	} else if cfg.MasterPassword == "" {
		return nil, fmt.Errorf("не задан мастер-пароль")
	}

	if cfg.MTU <= 0 {
		cfg.MTU = 1380
	}
	cfg.MTU = EffectiveTunMTU(cfg.MTU, resolvedPadding.PathMTU, effVersion)
	if cfg.SubnetCIDR == "" {
		cfg.SubnetCIDR = "10.44.0.1/24"
	}
	if len(cfg.Routes) == 0 {
		cfg.Routes = []string{"0.0.0.0/0"}
	}

	if cfg.SessionIdleTimeout < 0 || cfg.HandshakeCacheTTL < 0 || cfg.BlindUDPIdleTimeout < 0 {
		return nil, fmt.Errorf("%w: отрицательные таймауты сервера недопустимы", ErrInvalidTimingConfig)
	}
	idleTimeout := cfg.SessionIdleTimeout
	if idleTimeout == 0 {
		idleTimeout = SessionIdleTimeout
	}
	cacheTTL := cfg.HandshakeCacheTTL
	if cacheTTL == 0 {
		cacheTTL = HandshakeReplayTTL
	}
	blindTimeout := cfg.BlindUDPIdleTimeout
	if blindTimeout == 0 {
		blindTimeout = BlindUDPIdleTimeout
	}
	nowFn := cfg.Now
	if nowFn == nil {
		nowFn = time.Now
	}
	lookupFn := cfg.LookupIP
	if lookupFn == nil {
		lookupFn = net.LookupIP
	}

	var sm *SessionManager
	if cfg.MasterPassword != "" {
		var err error
		sm, err = NewSessionManagerWithTiming(cfg.MasterPassword, cfg.ArgonParams, cfg.SubnetCIDR, cfg.MTU, cfg.DNS, cfg.Routes, idleTimeout, cacheTTL, nowFn)
		if err != nil {
			return nil, err
		}
	}

	return &Server{
		cfg:               cfg,
		protocolVersion:   effVersion,
		padding:           resolvedPadding,
		maxOuterUDP:       maxOuterUDP,
		handshakeCacheTTL: cacheTTL,
		blindIdleTimeout:  blindTimeout,
		nowFunc:           nowFn,
		lookupIP:          lookupFn,
		sessions:          sm,
		tun:               tunDev,
		handshakeCache:    make(map[[SaltSize]byte]handshakeResponseCacheEntry),
		blindUDP:          make(map[string]*blindUDPPeer),
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

// Start запускает сетевые слушатели сервера согласно настроенному режиму (tunnel или transit).
func (s *Server) Start() error {
	if s.cfg.Mode == ModeTransit {
		return s.StartTransit()
	}
	return s.StartUDP()
}

// Run запускает циклы обработки пакетов согласно режиму сервера.
func (s *Server) Run() error {
	errCh := make(chan error, 2)
	go func() {
		errCh <- s.udpToTunLoop()
	}()
	if s.cfg.Mode == ModeTransit {
		go func() {
			errCh <- s.tcpAcceptLoop()
		}()
	} else {
		go func() {
			errCh <- s.tunToUdpLoop()
		}()
	}
	return <-errCh
}

func (s *Server) Close() error {
	if s.udpConn != nil {
		_ = s.udpConn.Close()
	}
	if s.tcpListener != nil {
		_ = s.tcpListener.Close()
	}
	if s.tun != nil {
		_ = s.tun.Close()
	}
	s.sessions.CloseAll()
	s.closeAllBlindUDP()
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

func (s *Server) isHandshakeVersionAllowed(version byte) bool {
	if version == s.protocolVersion {
		return true
	}
	if s.protocolVersion == ProtocolVersionV5 && version == ProtocolVersionV4 && !s.cfg.DisallowV4 {
		return true
	}
	return false
}

// HandlePacket обрабатывает один входящий кадр от remoteAddr и при необходимости возвращает зашифрованный ответ.
func (s *Server) HandlePacket(pkt []byte, remoteAddr *net.UDPAddr) []byte {
	if s.cfg.Mode == ModeTransit && s.cfg.TransitBlind {
		s.handleBlindUDP(pkt, remoteAddr)
		return nil
	}
	if s.sessions == nil {
		return nil
	}
	if s.maxOuterUDP > 0 && len(pkt) > s.maxOuterUDP {
		s.logDrop(remoteAddr, "mtu", ErrMTUExceeded)
		return nil
	}

	psk := s.sessions.PSK()
	version, frameType, salt, _, seq, _, err := ParseAndVerifyHeader(pkt, psk)
	if err != nil {
		s.logDrop(remoteAddr, "header", err)
		return nil
	}

	if frameType == FrameTypeHandshakeReq {
		if !s.isHandshakeVersionAllowed(version) {
			s.logDrop(remoteAddr, "handshake version", ErrBadVersion)
			return nil
		}
		log.Printf("[argon4-server] получен handshake (v%d) от %s", version, remoteAddr)
		return s.handleHandshake(pkt, psk, salt, version, remoteAddr)
	}

	sess := s.sessions.LookupBySalt(salt)
	if sess == nil {
		// Сессия не установлена рукопожатием — пакеты данных и keepalive без рукопожатия отбрасываются без запуска Argon2id.
		s.logDrop(remoteAddr, "no session", fmt.Errorf("frame type %d", frameType))
		return nil
	}
	if version != sess.ProtocolVersion {
		s.logDrop(remoteAddr, "downgrade", ErrDowngradeRejected)
		return nil
	}
	if !sess.CheckReplay(seq) {
		s.logDrop(remoteAddr, "replay pre-check", ErrReplayPacket)
		return nil
	}
	opened, err := OpenPacketWithOptions(pkt, psk, sess.C2SKey, OpenOptions{
		ExpectedVersion: sess.ProtocolVersion,
		MaxOuterBytes:   s.maxOuterUDP,
	})
	if err != nil {
		s.logDrop(remoteAddr, "c2s auth", err)
		return nil
	}
	if !sess.AcceptReplay(seq) {
		s.logDrop(remoteAddr, "replay commit", ErrReplayPacket)
		return nil
	}
	plaintext := opened.Plaintext

	// Обновляем адрес клиента только после успешной проверки GCM-тега и защиты от повтора (невозможно угнать сессию повтором пакета).
	s.sessions.TouchSession(sess, remoteAddr)

	switch frameType {
	case FrameTypeKeepalive:
		if string(plaintext) == KeepaliveFeedbackPayload {
			return nil
		}
		wirePong, err := SealPacketWithOptions(FrameTypeKeepalive, sess.Salt[:], psk, sess.S2CKey, sess.NextS2CSeq(), []byte(KeepalivePongPayload), sess.sealOptions(s.maxOuterUDP))
		if err == nil {
			return wirePong
		}

	case FrameTypeData:
		if sess.Shaper != nil {
			sess.Shaper.RecordInboundActivity()
		}
		s.deliverInboundPacket(sess, plaintext, remoteAddr)

	case FrameTypeBatch:
		if !sess.Profile.IsActive() {
			s.logDrop(remoteAddr, "batch unexpected", ErrProfileUnsupported)
			return nil
		}
		maxInner := MaxPayloadForSession(sess, s.maxOuterUDP)
		subPackets, berr := UnpackBatchPlaintext(plaintext, maxInner)
		if berr != nil {
			s.logDrop(remoteAddr, "batch unpack", berr)
			return nil
		}
		if sess.Shaper != nil {
			sess.Shaper.RecordInboundActivity()
		}
		for _, sub := range subPackets {
			s.deliverInboundPacket(sess, sub, remoteAddr)
		}
	}
	return nil
}

func (s *Server) deliverInboundPacket(sess *PeerSession, plaintext []byte, remoteAddr *net.UDPAddr) {
	if sess.Mode == ModeTransit {
		if sess.TargetConn != nil && len(plaintext) > 0 {
			_, _ = sess.TargetConn.Write(plaintext)
		}
		return
	}
	srcIP, _, ok := ParseIPv4Addrs(plaintext)
	if !ok {
		return
	}
	// Запрещаем подмену внутреннего адреса отправителя (IP spoofing между клиентами туннеля).
	if !srcIP.Equal(sess.AssignedIP) {
		s.logDrop(remoteAddr, "inner src IP mismatch", fmt.Errorf("got %s want %s", srcIP, sess.AssignedIP))
		return
	}
	if s.tun != nil && len(plaintext) > 0 {
		_, _ = s.tun.Write(plaintext)
	}
}

// MaxPayloadForSession возвращает максимально допустимую длину полезной нагрузки внутри кадра сессии.
func MaxPayloadForSession(sess *PeerSession, defaultMaxOuter int) int {
	maxOuter := sess.MaxOuterBytes
	if maxOuter <= 0 {
		maxOuter = defaultMaxOuter
	}
	if maxOuter <= 0 {
		return 0
	}
	overhead := OverheadV4
	if sess.ProtocolVersion == ProtocolVersionV5 {
		overhead = OverheadV5
	}
	if maxOuter <= overhead {
		return 0
	}
	return maxOuter - overhead
}

func (s *Server) now() time.Time {
	if s != nil && s.nowFunc != nil {
		return s.nowFunc()
	}
	return time.Now()
}

// cachedHandshakeResponse делает повторную UDP-передачу идемпотентной: тот же запрос
// с того же адреса получает тот же ответ без новой сессии и нового расчёта Argon2id.
func (s *Server) cachedHandshakeResponse(pkt, salt []byte, remoteAddr *net.UDPAddr) []byte {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := s.now()
	s.handshakeMu.Lock()
	defer s.handshakeMu.Unlock()
	entry, ok := s.handshakeCache[saltKey]
	if !ok {
		return nil
	}
	if !now.Before(entry.expires) {
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

	now := s.now()
	ttl := s.handshakeCacheTTL
	if ttl <= 0 {
		ttl = HandshakeReplayTTL
	}
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
		expires:    now.Add(ttl),
	}
	s.handshakeMu.Unlock()
}

func (s *Server) authenticateHandshake(pkt, psk, salt []byte, headerVersion byte, maxOuter int, remoteAddr *net.UDPAddr) (
	hsKey []byte,
	req HandshakeRequest,
	clientAuth []byte,
	srvPub []byte,
	c2sKey []byte,
	s2cKey []byte,
	sessionPad PaddingConfig,
	now time.Time,
	ok bool,
) {
	hsKey, err := s.sessions.DeriveHandshakeKey(salt)
	if err != nil {
		s.logDrop(remoteAddr, "handshake derive", err)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}
	opened, err := OpenPacketWithOptions(pkt, psk, hsKey, OpenOptions{
		ExpectedVersion: headerVersion,
		AllowV4:         true,
		MaxOuterBytes:   maxOuter,
	})
	if err != nil {
		s.logDrop(remoteAddr, "handshake open", err)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}

	if err := json.Unmarshal(opened.Plaintext, &req); err != nil {
		s.logDrop(remoteAddr, "handshake json", err)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}
	if headerVersion == ProtocolVersionV5 {
		if req.ProtocolVersion != 0 && req.ProtocolVersion != int(ProtocolVersionV5) {
			s.logDrop(remoteAddr, "handshake inner version mismatch", ErrDowngradeRejected)
			return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
		}
		sessionPad = s.padding
		if req.PaddingProfile != "" && req.PaddingProfile != PaddingProfileCustom {
			preset, perr := PresetPaddingConfig(req.PaddingProfile, s.padding.PathMTU)
			if perr != nil {
				s.logDrop(remoteAddr, "handshake padding profile", perr)
				return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
			}
			sessionPad = preset
		}
	} else if req.ProtocolVersion == int(ProtocolVersionV5) {
		s.logDrop(remoteAddr, "handshake downgraded", ErrDowngradeRejected)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}

	now = s.now()
	reqTime := time.Unix(req.Timestamp, 0)
	if now.Sub(reqTime).Abs() > HandshakeClockSkew {
		s.logDrop(remoteAddr, "handshake timestamp", ErrClockSkew)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}

	clientPub, err := hex.DecodeString(req.ClientPub)
	if err != nil || len(clientPub) != X25519KeySize {
		s.logDrop(remoteAddr, "handshake clientPub", fmt.Errorf("некорректный ключ X25519"))
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}

	if req.ClientAuth != "" {
		clientAuth, _ = hex.DecodeString(req.ClientAuth)
	}

	srvPriv, srvPub, err := GenerateX25519Keypair()
	if err != nil {
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}
	sharedSecret, err := ComputeX25519Shared(srvPriv, clientPub)
	for i := range srvPriv {
		srvPriv[i] = 0
	}
	if err != nil {
		s.logDrop(remoteAddr, "handshake ecdh", err)
		return nil, HandshakeRequest{}, nil, nil, nil, nil, PaddingConfig{}, time.Time{}, false
	}

	c2sKey, s2cKey = DeriveSessionKeys(hsKey, sharedSecret, salt)
	for i := range sharedSecret {
		sharedSecret[i] = 0
	}
	return hsKey, req, clientAuth, srvPub, c2sKey, s2cKey, sessionPad, now, true
}

func applyProtocolResponseFields(resp *HandshakeResponse, version byte, pad PaddingConfig, prof SessionProfile) {
	resp.ProtocolVersion = int(version)
	if version == ProtocolVersionV5 {
		resp.Capabilities = CapabilitiesForVersion(version, true)
		resp.PaddingProfile = pad.Profile
		if prof.IsActive() {
			resp.BehaviorProfile = prof.Name
			resp.ProfileVersion = prof.Version
		}
	}
}

func (s *Server) negotiateSessionProfile(headerVersion byte, proto string, req HandshakeRequest, sessionPad PaddingConfig) (SessionProfile, error) {
	policy := ServerProfilePolicy{
		DefaultProfile: s.cfg.BehaviorProfile,
		AllowVideoCall: !s.cfg.DisallowVideoCall,
		MaxDuration:    s.cfg.VideoCallMaxDuration,
		PathMTU:        sessionPad.PathMTU,
		DefaultPadding: sessionPad,
		DefaultTiming:  DefaultTimingConfig(),
	}
	prof, err := NegotiateServerProfile(headerVersion, s.cfg.Mode, proto, req, policy, nil)
	if err != nil {
		return SessionProfile{}, err
	}
	if prof.Name == "" && headerVersion == ProtocolVersionV5 && s.cfg.BehaviorProfile != "" && req.BehaviorProfile == "" && req.ProfileVersion == 0 {
		// Клиент не запрашивал профиль; явная политика — профиль включается только при явном согласовании.
		return SessionProfile{}, nil
	}
	return prof, nil
}

func (s *Server) sendWire(wire []byte, addr *net.UDPAddr) error {
	if addr == nil {
		return net.ErrClosed
	}
	if s.cfg.WriteUDP != nil {
		return s.cfg.WriteUDP(wire, addr)
	}
	if s.udpConn == nil {
		return net.ErrClosed
	}
	_, err := s.udpConn.WriteToUDP(wire, addr)
	return err
}

func (s *Server) attachSessionShaper(sess *PeerSession) {
	if s == nil || sess == nil || !sess.Profile.IsActive() || (s.udpConn == nil && s.cfg.WriteUDP == nil) || s.sessions == nil {
		return
	}
	psk := s.sessions.PSK()
	maxOuter := sess.MaxOuterBytes
	if maxOuter <= 0 {
		maxOuter = s.maxOuterUDP
	}
	shaper := NewTrafficShaper(ShaperOptions{
		Profile:          sess.Profile,
		Salt:             sess.Salt[:],
		PSK:              psk,
		Key:              sess.S2CKey,
		Version:          sess.ProtocolVersion,
		MaxOuterBytes:    maxOuter,
		NextSeq:          sess.NextS2CSeq,
		IsServer:         true,
		KeepaliveEnabled: false,
		WriteWire: func(wire []byte) error {
			return s.sendWire(wire, sess.RemoteAddr())
		},
	})
	sess.Shaper = shaper
	shaper.Start()
}

func (s *Server) handleHandshake(pkt, psk, salt []byte, headerVersion byte, remoteAddr *net.UDPAddr) []byte {
	if cached := s.cachedHandshakeResponse(pkt, salt, remoteAddr); cached != nil {
		return cached
	}

	hsKey, req, clientAuth, srvPub, c2sKey, s2cKey, sessionPad, now, ok := s.authenticateHandshake(pkt, psk, salt, headerVersion, s.maxOuterUDP, remoteAddr)
	if !ok {
		return nil
	}

	if s.cfg.Mode == ModeTransit {
		return s.handleTransitHandshake(pkt, psk, salt, hsKey, clientAuth, srvPub, c2sKey, s2cKey, headerVersion, sessionPad, req, now, remoteAddr)
	}
	if req.Mode != "" && req.Mode != ModeTunnel && req.Mode != ModeAuto {
		s.logDrop(remoteAddr, "tunnel mode mismatch", ErrTransitDenied)
		return nil
	}
	sessionProfile, perr := s.negotiateSessionProfile(headerVersion, ProtoUDP, req, sessionPad)
	if perr != nil {
		s.logDrop(remoteAddr, "behavior profile", perr)
		return nil
	}

	peer, err := s.sessions.RegisterSessionWithProfile(req.ClientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, headerVersion, sessionPad, sessionProfile, s.maxOuterUDP)
	if err != nil {
		s.logDrop(remoteAddr, "handshake register", err)
		return nil
	}
	s.attachSessionShaper(peer)

	respMTU := EffectiveProfileTunMTU(s.cfg.MTU, sessionPad.PathMTU, headerVersion, sessionProfile)
	resp := HandshakeResponse{
		AssignedIP: peer.AssignedIP.String(),
		ServerIP:   s.sessions.ServerIP().String(),
		SubnetCidr: s.sessions.SubnetPrefixLen(),
		MTU:        respMTU,
		DNS:        s.cfg.DNS,
		Routes:     s.cfg.Routes,
		ServerTime: now.Unix(),
		ServerPub:  hex.EncodeToString(srvPub),
		Mode:       ModeTunnel,
	}
	applyProtocolResponseFields(&resp, headerVersion, sessionPad, sessionProfile)
	respBytes, _ := json.Marshal(resp)
	wireResp, err := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, SealOptions{
		Version:       headerVersion,
		Padding:       sessionPad,
		SamplePad:     sessionProfile.SamplePaddingLen,
		MaxOuterBytes: s.maxOuterUDP,
	})
	if err != nil {
		return nil
	}
	s.cacheHandshakeResponse(pkt, salt, remoteAddr, wireResp)
	log.Printf("[argon4-server] рукопожатие ОК: клиент=%q v%d профиль=%q (%s) адрес=%s выдан=%s приложения=%v",
		req.ClientID, headerVersion, sessionProfile.Name, sessionProfile.Archetype, remoteAddr, peer.AssignedIP, req.Apps)
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
	wirePkt, err := SealPacketWithOptions(FrameTypeData, sess.Salt[:], s.sessions.PSK(), sess.S2CKey, sess.NextS2CSeq(), ipPkt, sess.sealOptions(s.maxOuterUDP))
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

// DispatchTunPacket ставит исходящий IPv4-пакет из TUN в шейпер активной сессии или шифрует его синхронно.
func (s *Server) DispatchTunPacket(ipPkt []byte) {
	_, dstIP, ok := ParseIPv4Addrs(ipPkt)
	if !ok {
		return
	}
	sess := s.sessions.LookupByDstIP(dstIP)
	if sess == nil {
		return
	}
	if sess.Shaper != nil {
		_ = sess.Shaper.EnqueueReal(ipPkt)
		return
	}
	if wirePkt, addr := s.SealTunPacket(ipPkt); wirePkt != nil && addr != nil {
		_ = s.sendWire(wirePkt, addr)
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
		s.DispatchTunPacket(buf[:n])
	}
}
