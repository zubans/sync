package argon4

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
)

var (
	ErrHandshakeReplay = errors.New("повторное использование соли рукопожатия")
	ErrClockSkew       = errors.New("недопустимое время рукопожатия (рассинхронизация часов или повтор)")
	ErrIPPoolExhausted = errors.New("пул IP-адресов туннеля исчерпан")
)

// HandshakeRequest передаётся зашифрованным внутри кадра FrameTypeHandshakeReq.
type HandshakeRequest struct {
	ClientID   string   `json:"clientId"`
	ClientAuth string   `json:"clientAuth,omitempty"`
	ClientPub  string   `json:"clientPub"`
	Requested  string   `json:"requestedIp,omitempty"`
	Apps       []string `json:"apps,omitempty"`
	Timestamp  int64    `json:"timestamp"`
}

// HandshakeResponse возвращается сервером зашифрованным внутри кадра FrameTypeHandshakeResp.
type HandshakeResponse struct {
	AssignedIP string   `json:"assignedIp"`
	ServerIP   string   `json:"serverIp"`
	SubnetCidr int      `json:"subnetCidr"`
	MTU        int      `json:"mtu"`
	DNS        []string `json:"dns,omitempty"`
	Routes     []string `json:"routes,omitempty"`
	ServerTime int64    `json:"serverTime"`
	ServerPub  string   `json:"serverPub"`
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
type PeerSession struct {
	ClientID   string
	ClientAuth []byte
	AssignedIP net.IP
	RemoteAddr *net.UDPAddr
	Salt       [SaltSize]byte
	C2SKey     []byte
	S2CKey     []byte
	LastSeen   time.Time
	replay     ReplayWindow
	s2cSeq     atomic.Uint64
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

	bySalt         map[[SaltSize]byte]*PeerSession
	byIP           map[[4]byte]*PeerSession
	byClient       map[string]*PeerSession
	seenHandshakes map[[SaltSize]byte]time.Time
	nextHost       byte
}

func NewSessionManager(masterPassword string, params ArgonParams, subnetCIDR string, mtu int, dns, routes []string) (*SessionManager, error) {
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

	psk := DerivePSK(masterPassword, params)

	return &SessionManager{
		masterPassword: masterPassword,
		params:         params,
		psk:            psk,
		argonSem:       make(chan struct{}, MaxConcurrentArgon2),
		serverIP:       serverIP,
		subnet:         ipNet,
		mtu:            mtu,
		dns:            dns,
		routes:         routes,
		bySalt:         make(map[[SaltSize]byte]*PeerSession),
		byIP:           make(map[[4]byte]*PeerSession),
		byClient:       make(map[string]*PeerSession),
		seenHandshakes: make(map[[SaltSize]byte]time.Time),
		nextHost:       2,
	}, nil
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
func (m *SessionManager) LookupBySalt(salt []byte) *PeerSession {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	m.mu.RLock()
	sess := m.bySalt[saltKey]
	m.mu.RUnlock()
	return sess
}

// DeriveHandshakeKey вычисляет ключ рукопожатия через Argon2id с ограничением параллелизма и проверкой повтора соли.
func (m *SessionManager) DeriveHandshakeKey(salt []byte) ([]byte, error) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := time.Now()
	m.mu.RLock()
	if _, seen := m.seenHandshakes[saltKey]; seen {
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
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	now := time.Now()
	m.mu.Lock()
	defer m.mu.Unlock()

	m.purgeExpiredLocked(now)

	if exp, seen := m.seenHandshakes[saltKey]; seen && now.Before(exp) {
		return nil, ErrHandshakeReplay
	}
	m.seenHandshakes[saltKey] = now.Add(HandshakeReplayTTL)

	// Переиспользование существующего IP по clientID разрешено только при совпадении токена владения clientAuth
	if existing, ok := m.byClient[clientID]; ok && clientID != "" {
		canReclaim := len(existing.ClientAuth) > 0 &&
			len(clientAuth) == len(existing.ClientAuth) &&
			subtle.ConstantTimeCompare(existing.ClientAuth, clientAuth) == 1
		if canReclaim {
			delete(m.bySalt, existing.Salt)
			existing.Salt = saltKey
			existing.C2SKey = c2sKey
			existing.S2CKey = s2cKey
			existing.RemoteAddr = remoteAddr
			existing.LastSeen = now
			existing.replay = ReplayWindow{}
			existing.s2cSeq.Store(0)
			m.bySalt[saltKey] = existing
			return existing, nil
		}
	}

	assignedIP, err := m.allocateIPLocked()
	if err != nil {
		return nil, err
	}
	var ipKey [4]byte
	copy(ipKey[:], assignedIP.To4())

	sess := &PeerSession{
		ClientID:   clientID,
		ClientAuth: append([]byte(nil), clientAuth...),
		AssignedIP: assignedIP,
		RemoteAddr: remoteAddr,
		Salt:       saltKey,
		C2SKey:     c2sKey,
		S2CKey:     s2cKey,
		LastSeen:   now,
	}
	m.bySalt[saltKey] = sess
	m.byIP[ipKey] = sess
	if clientID != "" && len(clientAuth) > 0 {
		if _, exists := m.byClient[clientID]; !exists {
			m.byClient[clientID] = sess
		}
	}
	return sess, nil
}

func (m *SessionManager) TouchSession(sess *PeerSession, remoteAddr *net.UDPAddr) {
	m.mu.Lock()
	sess.RemoteAddr = remoteAddr
	sess.LastSeen = time.Now()
	m.mu.Unlock()
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
	for saltKey, sess := range m.bySalt {
		if now.Sub(sess.LastSeen) > SessionIdleTimeout {
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
