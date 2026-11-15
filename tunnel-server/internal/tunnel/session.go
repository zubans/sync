package tunnel

import (
	"crypto/subtle"
	"errors"
	"net"
	"sync"
	"sync/atomic"
	"time"
)

const (
	// Максимально допустимое расхождение часов при рукопожатии (защита от повтора старых рукопожатий).
	HandshakeClockSkew = 60 * time.Second
	// Время хранения использованных солей рукопожатия (больше 2 * HandshakeClockSkew).
	HandshakeReplayTTL = 180 * time.Second
	// Таймаут неактивности сессии, после которого её виртуальный IP возвращается в пул.
	SessionIdleTimeout = 10 * time.Minute
	// Максимальное число одновременных вычислений Argon2id на сервере (защита от CPU/RAM DoS).
	MaxConcurrentArgon2 = 4

	// Режимы работы сервера/рукопожатия Argon4.
	ModeTunnel  = "tunnel"
	ModeTransit = "transit"
	ModeAuto    = "auto"

	// Транспортные протоколы L4-транзита.
	ProtoUDP = "udp"
	ProtoTCP = "tcp"
)

var (
	ErrHandshakeReplay = errors.New("повторное использование соли рукопожатия")
	ErrClockSkew       = errors.New("недопустимое время рукопожатия (рассинхронизация часов или повтор)")
	ErrIPPoolExhausted = errors.New("пул IP-адресов туннеля исчерпан")
	ErrTransitDenied   = errors.New("запрошенный транзитный режим или цель запрещены сервером")
)

// HandshakeRequest передаётся зашифрованным внутри кадра FrameTypeHandshakeReq.
type HandshakeRequest struct {
	ClientID        string   `json:"clientId"`
	ClientAuth      string   `json:"clientAuth,omitempty"`
	ClientPub       string   `json:"clientPub"`
	Requested       string   `json:"requestedIp,omitempty"`
	Apps            []string `json:"apps,omitempty"`
	Timestamp       int64    `json:"timestamp"`
	Mode            string   `json:"mode,omitempty"`
	Target          string   `json:"target,omitempty"`
	Proto           string   `json:"proto,omitempty"`
	ProtocolVersion int      `json:"protocolVersion,omitempty"`
	Capabilities    []string `json:"capabilities,omitempty"`
	PaddingProfile  string   `json:"paddingProfile,omitempty"`
	BehaviorProfile string   `json:"behaviorProfile,omitempty"`
	ProfileVersion  int      `json:"profileVersion,omitempty"`
	AutoMTU         bool     `json:"autoMtu,omitempty"`
}

// HandshakeResponse возвращается сервером зашифрованным внутри кадра FrameTypeHandshakeResp.
type HandshakeResponse struct {
	AssignedIP       string   `json:"assignedIp,omitempty"`
	ServerIP         string   `json:"serverIp,omitempty"`
	SubnetCidr       int      `json:"subnetCidr,omitempty"`
	MTU              int      `json:"mtu,omitempty"`
	EffectivePathMTU int      `json:"effectivePathMtu,omitempty"`
	DNS              []string `json:"dns,omitempty"`
	Routes           []string `json:"routes,omitempty"`
	ServerTime       int64    `json:"serverTime"`
	ServerPub        string   `json:"serverPub"`
	Mode             string   `json:"mode,omitempty"`
	Target           string   `json:"target,omitempty"`
	ProtocolVersion  int      `json:"protocolVersion,omitempty"`
	Capabilities     []string `json:"capabilities,omitempty"`
	PaddingProfile   string   `json:"paddingProfile,omitempty"`
	BehaviorProfile  string   `json:"behaviorProfile,omitempty"`
	ProfileVersion   int      `json:"profileVersion,omitempty"`
}

// ReplayWindow реализует 64-пакетное скользящее окно защиты от повтора (anti-replay) по принципу RFC 6479 / WireGuard.
type ReplayWindow struct {
	mu     sync.Mutex
	maxSeq uint64
	bitmap uint64
}

// Check предварительно проверяет, что счётчик seq ещё не встречался и не выпал за левую границу окна.
func (w *ReplayWindow) Check(seq uint64) bool {
	if seq == 0 {
		return false
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	if seq > w.maxSeq {
		return true
	}
	diff := w.maxSeq - seq
	if diff >= 64 {
		return false
	}
	return (w.bitmap & (uint64(1) << diff)) == 0
}

// Accept проверяет и фиксирует счётчик seq в скользящем окне (вызывается только после успешной проверки GCM-тега).
func (w *ReplayWindow) Accept(seq uint64) bool {
	if seq == 0 {
		return false
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	if seq > w.maxSeq {
		shift := seq - w.maxSeq
		if shift >= 64 {
			w.bitmap = 1
		} else {
			w.bitmap = (w.bitmap << shift) | 1
		}
		w.maxSeq = seq
		return true
	}
	diff := w.maxSeq - seq
	if diff >= 64 {
		return false
	}
	mask := uint64(1) << diff
	if (w.bitmap & mask) != 0 {
		return false
	}
	w.bitmap |= mask
	return true
}

// PeerSession хранит состояние аутентифицированного клиента, раздельные сессионные ключи направлений (PFS) и окно повторов.
// При reconnect объект не изменяется: SessionManager подменяет его новым, поэтому читатели видят согласованные соль и ключи.
type PeerSession struct {
	ClientID        string
	ClientAuth      []byte
	AssignedIP      net.IP
	Salt            [SaltSize]byte
	C2SKey          []byte
	S2CKey          []byte
	ProtocolVersion byte
	Padding         PaddingConfig
	Profile         SessionProfile
	MaxOuterBytes   int
	Mode            string
	Target          string
	Proto           string
	TargetConn      *net.UDPConn
	Shaper          *TrafficShaper

	stateMu         sync.RWMutex
	remoteAddr      *net.UDPAddr
	lastSeen        time.Time
	replay          ReplayWindow
	s2cSeq          atomic.Uint64
	runtimeMaxOuter atomic.Int64
	closeOnce       sync.Once
}

// EffectiveMaxOuter возвращает действующий внешний лимит UDP-кадра сессии с учётом динамической автоподстройки MTU.
func (s *PeerSession) EffectiveMaxOuter(defaultMaxOuter int) int {
	if s == nil {
		return defaultMaxOuter
	}
	base := s.MaxOuterBytes
	if base <= 0 {
		base = defaultMaxOuter
	}
	if dyn := int(s.runtimeMaxOuter.Load()); dyn > 0 && (base <= 0 || dyn < base) {
		return dyn
	}
	return base
}

// SetRuntimeMaxOuter ограничивает внешний лимит UDP-кадра сессии сверху до maxOuter (без снижения ниже MinPathMTU-28).
func (s *PeerSession) SetRuntimeMaxOuter(maxOuter int) int {
	if s == nil || maxOuter <= 0 {
		return 0
	}
	floor := MinPathMTU - IPv4UDPOverhead
	if maxOuter < floor {
		maxOuter = floor
	}
	for {
		cur := int(s.runtimeMaxOuter.Load())
		if cur > 0 && cur <= maxOuter {
			return cur
		}
		if s.runtimeMaxOuter.CompareAndSwap(int64(cur), int64(maxOuter)) {
			if s.Shaper != nil {
				s.Shaper.SetMaxOuterBytes(s.EffectiveMaxOuter(maxOuter))
			}
			return maxOuter
		}
	}
}

// DownshiftMaxOuter снижает рабочий внешний лимит UDP-кадра при ошибке EMSGSIZE/переполнении пути.
func (s *PeerSession) DownshiftMaxOuter(failedWireLen, kernelPathMTU int) int {
	if s == nil {
		return 0
	}
	cur := s.EffectiveMaxOuter(DefaultMaxOuterUDPSize)
	candidate := cur - pmtuFallbackStep
	if failedWireLen > 0 && failedWireLen-pmtuFallbackStep < candidate {
		candidate = failedWireLen - pmtuFallbackStep
	}
	if kernelPathMTU >= MinPathMTU {
		kOuter := kernelPathMTU - IPv4UDPOverhead
		if kOuter < candidate || candidate <= 0 {
			candidate = kOuter
		}
	}
	floor := MinPathMTU - IPv4UDPOverhead
	if candidate < floor {
		candidate = floor
	}
	return s.SetRuntimeMaxOuter(candidate)
}

func (s *PeerSession) sealOptions(defaultMaxOuter int) SealOptions {
	ver := s.ProtocolVersion
	if ver == 0 {
		ver = ProtocolVersionV5
	}
	maxOuter := s.EffectiveMaxOuter(defaultMaxOuter)
	prof := s.Profile
	if s.Shaper != nil {
		prof = s.Shaper.EffectiveProfile()
	}
	opts := SealOptions{
		Version:       ver,
		Padding:       s.Padding,
		MaxOuterBytes: maxOuter,
	}
	if ver == ProtocolVersionV5 && prof.IsActive() {
		opts.Padding = prof.Padding
		opts.SamplePad = prof.SamplePaddingLen
	}
	return opts
}

// Close освобождает связанные с сессией сетевые ресурсы и останавливает шейпер.
func (s *PeerSession) Close() {
	if s == nil {
		return
	}
	s.closeOnce.Do(func() {
		if s.Shaper != nil {
			s.Shaper.Close()
		}
		if s.TargetConn != nil {
			_ = s.TargetConn.Close()
		}
	})
}

// RemoteAddr возвращает копию текущего адреса для безопасного roaming без гонок с TouchSession.
func (s *PeerSession) RemoteAddr() *net.UDPAddr {
	s.stateMu.RLock()
	defer s.stateMu.RUnlock()
	return cloneUDPAddr(s.remoteAddr)
}

// LastSeen возвращает время последнего аутентифицированного пакета.
func (s *PeerSession) LastSeen() time.Time {
	s.stateMu.RLock()
	defer s.stateMu.RUnlock()
	return s.lastSeen
}

func cloneUDPAddr(addr *net.UDPAddr) *net.UDPAddr {
	if addr == nil {
		return nil
	}
	clone := *addr
	clone.IP = append(net.IP(nil), addr.IP...)
	return &clone
}

func newPeerSession(clientID string, clientAuth []byte, assignedIP net.IP, salt [SaltSize]byte, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, now time.Time, version byte, padding PaddingConfig, maxOuter int) *PeerSession {
	if version == 0 {
		version = ProtocolVersionV5
	}
	if maxOuter <= 0 {
		maxOuter = DefaultMaxOuterUDPSize
	}
	return &PeerSession{
		ClientID:        clientID,
		ClientAuth:      append([]byte(nil), clientAuth...),
		AssignedIP:      append(net.IP(nil), assignedIP...),
		Salt:            salt,
		C2SKey:          append([]byte(nil), c2sKey...),
		S2CKey:          append([]byte(nil), s2cKey...),
		ProtocolVersion: version,
		Padding:         padding,
		MaxOuterBytes:   maxOuter,
		remoteAddr:      cloneUDPAddr(remoteAddr),
		lastSeen:        now,
	}
}

// NextS2CSeq возвращает следующий монотонный номер пакета в направлении сервер -> клиент (начиная с 1).
func (s *PeerSession) NextS2CSeq() uint64 {
	return s.s2cSeq.Add(1)
}

// CheckReplay проверяет допустимость входящего номера пакета до расшифровки.
func (s *PeerSession) CheckReplay(seq uint64) bool {
	return s.replay.Check(seq)
}

// AcceptReplay фиксирует входящий номер пакета после успешной проверки подлинности GCM.
func (s *PeerSession) AcceptReplay(seq uint64) bool {
	return s.replay.Accept(seq)
}

// SessionManager управляет активными сессиями клиентов, выдачей IP-адресов из пула туннеля и защитой от повторов.
type SessionManager struct {
	mu             sync.RWMutex
	masterPassword string
	params         ArgonParams
	psk            []byte
	argonSem       chan struct{}
	serverIP       net.IP
	subnet         *net.IPNet
	mtu            int
	dns            []string
	routes         []string

	bySalt             map[[SaltSize]byte]*PeerSession
	byIP               map[[4]byte]*PeerSession
	byClient           map[string]*PeerSession
	seenHandshakes     map[[SaltSize]byte]time.Time
	nextHost           byte
	idleTimeout        time.Duration
	handshakeReplayTTL time.Duration
	nowFunc            func() time.Time
}

// NewSessionManager создаёт менеджер сессий со значениями таймингов по умолчанию.
func NewSessionManager(masterPassword string, params ArgonParams, subnetCIDR string, mtu int, dns, routes []string) (*SessionManager, error) {
	return NewSessionManagerWithTiming(masterPassword, params, subnetCIDR, mtu, dns, routes, SessionIdleTimeout, HandshakeReplayTTL, nil)
}

// NewSessionManagerWithTiming создаёт менеджер сессий с настраиваемыми таймаутами простоя и кэша рукопожатий.
func NewSessionManagerWithTiming(
	masterPassword string,
	params ArgonParams,
	subnetCIDR string,
	mtu int,
	dns, routes []string,
	idleTimeout time.Duration,
	handshakeReplayTTL time.Duration,
	nowFunc func() time.Time,
) (*SessionManager, error) {
	ip, ipNet, err := net.ParseCIDR(subnetCIDR)
	if err != nil {
		return nil, err
	}
	ip4 := ip.To4()
	if ip4 == nil {
		return nil, &net.ParseError{Type: "IPv4 CIDR", Text: subnetCIDR}
	}
	serverIP := make(net.IP, 4)
	copy(serverIP, ip4)
	if serverIP[3] == 0 {
		serverIP[3] = 1
	}

	if idleTimeout <= 0 {
		idleTimeout = SessionIdleTimeout
	}
	if handshakeReplayTTL <= 0 {
		handshakeReplayTTL = HandshakeReplayTTL
	}
	if nowFunc == nil {
		nowFunc = time.Now
	}

	psk := DerivePSK(masterPassword, params)

	return &SessionManager{
		masterPassword:     masterPassword,
		params:             params,
		psk:                psk,
		argonSem:           make(chan struct{}, MaxConcurrentArgon2),
		serverIP:           serverIP,
		subnet:             ipNet,
		mtu:                mtu,
		dns:                dns,
		routes:             routes,
		bySalt:             make(map[[SaltSize]byte]*PeerSession),
		byIP:               make(map[[4]byte]*PeerSession),
		byClient:           make(map[string]*PeerSession),
		seenHandshakes:     make(map[[SaltSize]byte]time.Time),
		nextHost:           2,
		idleTimeout:        idleTimeout,
		handshakeReplayTTL: handshakeReplayTTL,
		nowFunc:            nowFunc,
	}, nil
}

func (m *SessionManager) now() time.Time {
	if m != nil && m.nowFunc != nil {
		return m.nowFunc()
	}
	return time.Now()
}

// IdleTimeout возвращает действующий таймаут простоя сессии.
func (m *SessionManager) IdleTimeout() time.Duration {
	if m != nil && m.idleTimeout > 0 {
		return m.idleTimeout
	}
	return SessionIdleTimeout
}

func (m *SessionManager) PSK() []byte {
	return m.psk
}

func (m *SessionManager) ServerIP() net.IP {
	return m.serverIP
}

func (m *SessionManager) SubnetPrefixLen() int {
	ones, _ := m.subnet.Mask.Size()
	return ones
}

// LookupBySalt возвращает активную сессию по 16-байтной соли (без запуска Argon2id).
// Если сессия превысила таймаут простоя, возвращает nil.
func (m *SessionManager) LookupBySalt(salt []byte) *PeerSession {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := m.now()
	m.mu.RLock()
	sess := m.bySalt[saltKey]
	m.mu.RUnlock()
	if sess != nil && now.Sub(sess.LastSeen()) > m.IdleTimeout() {
		return nil
	}
	return sess
}

// DeriveHandshakeKey вычисляет ключ рукопожатия через Argon2id с ограничением параллелизма и проверкой повтора соли.
func (m *SessionManager) DeriveHandshakeKey(salt []byte) ([]byte, error) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := m.now()
	m.mu.RLock()
	if exp, seen := m.seenHandshakes[saltKey]; seen && now.Before(exp) {
		m.mu.RUnlock()
		return nil, ErrHandshakeReplay
	}
	m.mu.RUnlock()

	m.argonSem <- struct{}{}
	defer func() { <-m.argonSem }()

	m.mu.RLock()
	if exp, seen := m.seenHandshakes[saltKey]; seen && now.Before(exp) {
		m.mu.RUnlock()
		return nil, ErrHandshakeReplay
	}
	m.mu.RUnlock()

	return DeriveKey(m.masterPassword, salt, m.params), nil
}

// RegisterSession закрепляет за проверенным клиентом виртуальный IP в туннеле и сохраняет ключи сессии (c2s/s2c).
func (m *SessionManager) RegisterSession(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr) (*PeerSession, error) {
	return m.RegisterSessionWithProtocol(clientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, ProtocolVersionV5, DefaultPaddingConfig(), DefaultMaxOuterUDPSize)
}

// RegisterSessionWithProtocol регистрирует сессию с зафиксированной версией протокола и распределением padding.
func (m *SessionManager) RegisterSessionWithProtocol(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, version byte, padding PaddingConfig, maxOuter int) (*PeerSession, error) {
	return m.RegisterSessionWithProfile(clientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, version, padding, SessionProfile{}, maxOuter)
}

// RegisterSessionWithProfile регистрирует сессию с зафиксированным поведенческим профилем трафика.
func (m *SessionManager) RegisterSessionWithProfile(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, version byte, padding PaddingConfig, profile SessionProfile, maxOuter int) (*PeerSession, error) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := m.now()
	var reclaimedOld *PeerSession
	defer func() {
		if reclaimedOld != nil {
			reclaimedOld.Close()
		}
	}()

	m.mu.Lock()
	defer m.mu.Unlock()

	if err := m.reserveHandshakeLocked(saltKey, now); err != nil {
		return nil, err
	}

	// Переиспользование существующего IP по clientID разрешено только при совпадении токена владения clientAuth.
	// Старый объект не перезаписываем: пакетные циклы могут всё ещё безопасно читать его согласованные ключи.
	if existing, ok := m.byClient[clientID]; ok && clientID != "" && len(existing.AssignedIP) > 0 {
		canReclaim := len(existing.ClientAuth) > 0 &&
			len(clientAuth) == len(existing.ClientAuth) &&
			subtle.ConstantTimeCompare(existing.ClientAuth, clientAuth) == 1
		if canReclaim {
			sess := newPeerSession(clientID, clientAuth, existing.AssignedIP, saltKey, c2sKey, s2cKey, remoteAddr, now, version, padding, maxOuter)
			sess.Mode = ModeTunnel
			sess.Profile = profile
			var ipKey [4]byte
			copy(ipKey[:], sess.AssignedIP.To4())
			delete(m.bySalt, existing.Salt)
			m.bySalt[saltKey] = sess
			m.byIP[ipKey] = sess
			m.byClient[clientID] = sess
			reclaimedOld = existing
			return sess, nil
		}
	}

	assignedIP, err := m.allocateIPLocked()
	if err != nil {
		return nil, err
	}
	var ipKey [4]byte
	copy(ipKey[:], assignedIP.To4())

	sess := newPeerSession(clientID, clientAuth, assignedIP, saltKey, c2sKey, s2cKey, remoteAddr, now, version, padding, maxOuter)
	sess.Mode = ModeTunnel
	sess.Profile = profile
	m.bySalt[saltKey] = sess
	m.byIP[ipKey] = sess
	if clientID != "" && len(clientAuth) > 0 {
		if _, exists := m.byClient[clientID]; !exists {
			m.byClient[clientID] = sess
		}
	}
	return sess, nil
}

// RegisterTransitSession регистрирует L4-транзитную сессию без выделения виртуального IP из пула туннеля.
func (m *SessionManager) RegisterTransitSession(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, target, proto string, targetConn *net.UDPConn) (*PeerSession, error) {
	maxOuter := DefaultMaxOuterUDPSize
	if proto == ProtoTCP {
		maxOuter = MaxTCPFrameSize
	}
	return m.RegisterTransitSessionWithProfile(clientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, target, proto, targetConn, ProtocolVersionV5, DefaultPaddingConfig(), SessionProfile{}, maxOuter)
}

// RegisterTransitSessionWithProtocol регистрирует транзитную сессию с зафиксированной версией протокола и padding.
func (m *SessionManager) RegisterTransitSessionWithProtocol(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, target, proto string, targetConn *net.UDPConn, version byte, padding PaddingConfig, maxOuter int) (*PeerSession, error) {
	return m.RegisterTransitSessionWithProfile(clientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, target, proto, targetConn, version, padding, SessionProfile{}, maxOuter)
}

// RegisterTransitSessionWithProfile регистрирует транзитную сессию с зафиксированным поведенческим профилем.
func (m *SessionManager) RegisterTransitSessionWithProfile(clientID string, clientAuth, salt, c2sKey, s2cKey []byte, remoteAddr *net.UDPAddr, target, proto string, targetConn *net.UDPConn, version byte, padding PaddingConfig, profile SessionProfile, maxOuter int) (*PeerSession, error) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := m.now()
	m.mu.Lock()
	defer m.mu.Unlock()

	if err := m.reserveHandshakeLocked(saltKey, now); err != nil {
		return nil, err
	}

	sess := newPeerSession(clientID, clientAuth, nil, saltKey, c2sKey, s2cKey, remoteAddr, now, version, padding, maxOuter)
	sess.Mode = ModeTransit
	sess.Target = target
	sess.Proto = proto
	sess.TargetConn = targetConn
	sess.Profile = profile
	m.bySalt[saltKey] = sess
	return sess, nil
}

// RemoveSession удаляет активную сессию по соли и освобождает её ресурсы (например, после закрытия TCP-потока).
func (m *SessionManager) RemoveSession(salt []byte) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	m.mu.Lock()
	sess := m.bySalt[saltKey]
	if sess != nil {
		delete(m.bySalt, saltKey)
		if ip4 := sess.AssignedIP.To4(); ip4 != nil {
			var ipKey [4]byte
			copy(ipKey[:], ip4)
			if m.byIP[ipKey] == sess {
				delete(m.byIP, ipKey)
			}
		}
		if sess.ClientID != "" && m.byClient[sess.ClientID] == sess {
			delete(m.byClient, sess.ClientID)
		}
	}
	m.mu.Unlock()
	sess.Close()
}

func (m *SessionManager) reserveHandshakeLocked(saltKey [SaltSize]byte, now time.Time) error {
	m.purgeExpiredLocked(now)
	if exp, seen := m.seenHandshakes[saltKey]; seen && now.Before(exp) {
		return ErrHandshakeReplay
	}
	ttl := m.handshakeReplayTTL
	if ttl <= 0 {
		ttl = HandshakeReplayTTL
	}
	m.seenHandshakes[saltKey] = now.Add(ttl)
	return nil
}

func (m *SessionManager) TouchSession(sess *PeerSession, remoteAddr *net.UDPAddr) {
	now := m.now()
	sess.stateMu.Lock()
	sess.remoteAddr = cloneUDPAddr(remoteAddr)
	sess.lastSeen = now
	sess.stateMu.Unlock()
}

func (m *SessionManager) LookupByDstIP(dstIP net.IP) *PeerSession {
	ip4 := dstIP.To4()
	if ip4 == nil {
		return nil
	}
	var ipKey [4]byte
	copy(ipKey[:], ip4)

	m.mu.RLock()
	sess := m.byIP[ipKey]
	m.mu.RUnlock()
	return sess
}

func (m *SessionManager) purgeExpiredLocked(now time.Time) {
	for s, exp := range m.seenHandshakes {
		if !now.Before(exp) {
			delete(m.seenHandshakes, s)
		}
	}
	idleTimeout := m.IdleTimeout()
	var expired []*PeerSession
	for saltKey, sess := range m.bySalt {
		if now.Sub(sess.LastSeen()) > idleTimeout {
			delete(m.bySalt, saltKey)
			if ip4 := sess.AssignedIP.To4(); ip4 != nil {
				var ipKey [4]byte
				copy(ipKey[:], ip4)
				if m.byIP[ipKey] == sess {
					delete(m.byIP, ipKey)
				}
			}
			if sess.ClientID != "" && m.byClient[sess.ClientID] == sess {
				delete(m.byClient, sess.ClientID)
			}
			expired = append(expired, sess)
		}
	}
	for _, sess := range expired {
		sess.Close()
	}
}

// CloseAll закрывает все активные транзитные соединения.
func (m *SessionManager) CloseAll() {
	if m == nil {
		return
	}
	m.mu.Lock()
	sessions := make([]*PeerSession, 0, len(m.bySalt))
	for k, s := range m.bySalt {
		sessions = append(sessions, s)
		delete(m.bySalt, k)
	}
	clear(m.byIP)
	clear(m.byClient)
	m.mu.Unlock()
	for _, s := range sessions {
		s.Close()
	}
}

func (m *SessionManager) allocateIPLocked() (net.IP, error) {
	base := m.subnet.IP.To4()
	for i := 0; i < 253; i++ {
		candidate := m.nextHost
		if m.nextHost >= 254 {
			m.nextHost = 2
		} else {
			m.nextHost++
		}
		if candidate < 2 || candidate >= 255 || candidate == m.serverIP[3] {
			continue
		}
		ip := net.IPv4(base[0], base[1], base[2], candidate).To4()
		var key [4]byte
		copy(key[:], ip)
		if _, used := m.byIP[key]; !used {
			return ip, nil
		}
	}
	return nil, ErrIPPoolExhausted
}

// ParseIPv4Addrs извлекает IPv4-адреса отправителя и получателя из заголовка IPv4-пакета.
func ParseIPv4Addrs(pkt []byte) (src, dst net.IP, ok bool) {
	if len(pkt) < 20 {
		return nil, nil, false
	}
	version := pkt[0] >> 4
	if version != 4 {
		return nil, nil, false
	}
	src = net.IPv4(pkt[12], pkt[13], pkt[14], pkt[15])
	dst = net.IPv4(pkt[16], pkt[17], pkt[18], pkt[19])
	return src, dst, true
}
