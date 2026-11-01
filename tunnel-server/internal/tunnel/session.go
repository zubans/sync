package tunnel

import (
	"net"
	"sync"
	"time"
)

// HandshakeRequest передаётся зашифрованным внутри кадра FrameTypeHandshakeReq.
type HandshakeRequest struct {
	ClientID  string   `json:"clientId"`
	Requested string   `json:"requestedIp,omitempty"`
	Apps      []string `json:"apps,omitempty"`
	Timestamp int64    `json:"timestamp"`
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
}

// PeerSession хранит состояние аутентифицированного клиента и кэширует выведенный ключ Argon2id по соли,
// чтобы не выполнять ресурсоёмкий Argon2id (19 МиБ) на каждый пакет данных.
type PeerSession struct {
	ClientID   string
	AssignedIP net.IP
	RemoteAddr *net.UDPAddr
	Salt       [SaltSize]byte
	Key        []byte
	LastSeen   time.Time
}

// SessionManager управляет активными сессиями клиентов, выдачей IP-адресов из пула туннеля и кэшем ключей Argon2id.
type SessionManager struct {
	mu             sync.RWMutex
	masterPassword string
	params         ArgonParams
	serverIP       net.IP
	subnet         *net.IPNet
	mtu            int
	dns            []string
	routes         []string

	bySalt   map[[SaltSize]byte]*PeerSession
	byIP     map[[4]byte]*PeerSession
	byClient map[string]*PeerSession
	nextHost byte
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
	// Сервер занимает адрес .1 в подсети туннеля (либо явно указанный хост, если он не .0)
	serverIP := make(net.IP, 4)
	copy(serverIP, ip4)
	if serverIP[3] == 0 {
		serverIP[3] = 1
	}

	return &SessionManager{
		masterPassword: masterPassword,
		params:         params,
		serverIP:       serverIP,
		subnet:         ipNet,
		mtu:            mtu,
		dns:            dns,
		routes:         routes,
		bySalt:         make(map[[SaltSize]byte]*PeerSession),
		byIP:           make(map[[4]byte]*PeerSession),
		byClient:       make(map[string]*PeerSession),
		nextHost:       2,
	}, nil
}

func (m *SessionManager) ServerIP() net.IP {
	return m.serverIP
}

func (m *SessionManager) SubnetPrefixLen() int {
	ones, _ := m.subnet.Mask.Size()
	return ones
}

// GetOrDeriveKey возвращает закэшированный ключ для 16-байтной соли сессии или выводит его через Argon2id.
func (m *SessionManager) GetOrDeriveKey(salt []byte) ([]byte, *PeerSession) {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	m.mu.RLock()
	if sess, ok := m.bySalt[saltKey]; ok {
		key := sess.Key
		m.mu.RUnlock()
		return key, sess
	}
	m.mu.RUnlock()

	key := DeriveKey(m.masterPassword, salt, m.params)
	return key, nil
}

// RegisterSession закрепляет за проверенным клиентом виртуальный IP в туннеле и сохраняет выведенный ключ в кэше.
func (m *SessionManager) RegisterSession(clientID string, salt []byte, key []byte, remoteAddr *net.UDPAddr) *PeerSession {
	var saltKey [SaltSize]byte
	copy(saltKey[:], salt)

	m.mu.Lock()
	defer m.mu.Unlock()

	if existing, ok := m.byClient[clientID]; ok && clientID != "" {
		delete(m.bySalt, existing.Salt)
		existing.Salt = saltKey
		existing.Key = key
		existing.RemoteAddr = remoteAddr
		existing.LastSeen = time.Now()
		m.bySalt[saltKey] = existing
		return existing
	}

	if existing, ok := m.bySalt[saltKey]; ok {
		existing.RemoteAddr = remoteAddr
		existing.LastSeen = time.Now()
		return existing
	}

	assignedIP := m.allocateIP()
	var ipKey [4]byte
	copy(ipKey[:], assignedIP.To4())

	sess := &PeerSession{
		ClientID:   clientID,
		AssignedIP: assignedIP,
		RemoteAddr: remoteAddr,
		Salt:       saltKey,
		Key:        key,
		LastSeen:   time.Now(),
	}
	m.bySalt[saltKey] = sess
	m.byIP[ipKey] = sess
	if clientID != "" {
		m.byClient[clientID] = sess
	}
	return sess
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

func (m *SessionManager) allocateIP() net.IP {
	base := m.subnet.IP.To4()
	for i := 0; i < 250; i++ {
		candidate := m.nextHost
		m.nextHost++
		if m.nextHost >= 254 {
			m.nextHost = 2
		}
		if candidate == m.serverIP[3] {
			continue
		}
		ip := net.IPv4(base[0], base[1], base[2], candidate).To4()
		var key [4]byte
		copy(key[:], ip)
		if _, used := m.byIP[key]; !used {
			return ip
		}
	}
	return net.IPv4(base[0], base[1], base[2], 2).To4()
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
