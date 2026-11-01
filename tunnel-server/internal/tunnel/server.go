package tunnel

import (
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"time"
)

// ServerConfig описывает конфигурацию серверного демона Argon4 на Ubuntu.
type ServerConfig struct {
	// ListenInterface — имя сетевого интерфейса Ubuntu для приёма пакетов (например, "eth0", "ens3" или "" для всех).
	ListenInterface string
	// ListenAddr — UDP-адрес и порт приёма зашифрованных пакетов (например, "0.0.0.0:51820").
	ListenAddr string
	// TunName — имя создаваемого или оборачиваемого виртуального TUN-интерфейса на Ubuntu (например, "argon0").
	TunName string
	// SubnetCIDR — виртуальная подсеть туннеля (например, "10.44.0.1/24").
	SubnetCIDR string
	// OutInterface — существующий сетевой интерфейс Ubuntu для оборачивания/маршрутизации через NAT (например, "eth0").
	OutInterface string
	// EnableNAT включает автоматическую настройку IPv4 forwarding и правил iptables MASQUERADE/DNAT.
	EnableNAT bool
	// MasterPassword — общий мастер-пароль для вывода ключа через Argon2id.
	MasterPassword string
	// ArgonParams — параметры времени, памяти и параллелизма Argon2id.
	ArgonParams ArgonParams
	// MTU виртуального TUN-интерфейса (по умолчанию 1380).
	MTU int
	// DNS-серверы, передаваемые клиенту в ответе рукопожатия.
	DNS []string
	// Маршруты CIDR, передаваемые клиенту в ответе рукопожатия (например, ["0.0.0.0/0"] или ["10.44.0.0/24"]).
	Routes []string
}

// Server реализует шифрованный шлюз-надстройку Argon4 между UDP-интерфейсом и виртуальным TUN-интерфейсом Ubuntu.
type Server struct {
	cfg      ServerConfig
	sessions *SessionManager
	udpConn  *net.UDPConn
	tun      io.ReadWriteCloser
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
		cfg:      cfg,
		sessions: sm,
		tun:      tunDev,
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
	_, _, salt, _, _, err := ParseHeader(pkt)
	if err != nil {
		return nil
	}

	key, sess := s.sessions.GetOrDeriveKey(salt)
	frameType, _, plaintext, err := OpenPacket(pkt, key)
	if err != nil {
		log.Printf("[argon4-server] пакет от %s отброшен: %v", remoteAddr, err)
		return nil
	}

	switch frameType {
	case FrameTypeHandshakeReq:
		var req HandshakeRequest
		if err := json.Unmarshal(plaintext, &req); err != nil {
			log.Printf("[argon4-server] некорректный JSON рукопожатия от %s: %v", remoteAddr, err)
			return nil
		}
		peer := s.sessions.RegisterSession(req.ClientID, salt, key, remoteAddr)
		resp := HandshakeResponse{
			AssignedIP: peer.AssignedIP.String(),
			ServerIP:   s.sessions.ServerIP().String(),
			SubnetCidr: s.sessions.SubnetPrefixLen(),
			MTU:        s.cfg.MTU,
			DNS:        s.cfg.DNS,
			Routes:     s.cfg.Routes,
			ServerTime: time.Now().Unix(),
		}
		respBytes, _ := json.Marshal(resp)
		wireResp, err := SealPacket(FrameTypeHandshakeResp, salt, key, respBytes)
		if err == nil {
			log.Printf("[argon4-server] рукопожатие ОК: клиент=%q адрес=%s выдан=%s приложения=%v",
				req.ClientID, remoteAddr, peer.AssignedIP, req.Apps)
			return wireResp
		}

	case FrameTypeKeepalive:
		if sess == nil {
			sess = s.sessions.RegisterSession("", salt, key, remoteAddr)
		} else {
			s.sessions.TouchSession(sess, remoteAddr)
		}
		wirePong, err := SealPacket(FrameTypeKeepalive, salt, key, []byte("pong"))
		if err == nil {
			return wirePong
		}

	case FrameTypeData:
		if sess == nil {
			// Неявная регистрация сессии при прямом приёме зашифрованных IP-пакетов без предварительного рукопожатия
			srcIP, _, ok := ParseIPv4Addrs(plaintext)
			clientID := ""
			if ok {
				clientID = srcIP.String()
			}
			sess = s.sessions.RegisterSession(clientID, salt, key, remoteAddr)
		} else {
			s.sessions.TouchSession(sess, remoteAddr)
		}
		if s.tun != nil && len(plaintext) > 0 {
			_, _ = s.tun.Write(plaintext)
		}
	}
	return nil
}

// SealTunPacket шифрует исходящий IPv4-пакет из интерфейса TUN ключом соответствующей клиентской сессии.
func (s *Server) SealTunPacket(ipPkt []byte) ([]byte, *net.UDPAddr) {
	_, dstIP, ok := ParseIPv4Addrs(ipPkt)
	if !ok {
		return nil, nil
	}
	sess := s.sessions.LookupByDstIP(dstIP)
	if sess == nil {
		return nil, nil
	}
	wirePkt, err := SealPacket(FrameTypeData, sess.Salt[:], sess.Key, ipPkt)
	if err != nil {
		return nil, nil
	}
	return wirePkt, sess.RemoteAddr
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
