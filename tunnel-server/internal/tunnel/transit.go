package tunnel

import (
	"bytes"
	"encoding/binary"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const (
	// TransitTCPChunkSize задаёт максимальный размер полезной нагрузки внутри одного TCP-кадра FrameTypeData.
	TransitTCPChunkSize = 16384
	// MaxTCPFrameSize ограничивает максимальный размер одного зашифрованного Argon4-кадра поверх TCP.
	MaxTCPFrameSize = 65535
	// TransitDialTimeout ограничивает время ожидания установки исходящего соединения к следующей цели транзита.
	TransitDialTimeout = 10 * time.Second
	// BlindUDPIdleTimeout закрывает неактивные blind UDP-соответствия при отсутствии обмена пакетами.
	BlindUDPIdleTimeout = 2 * time.Minute
)

// WriteTCPFrame записывает Argon4-кадр с 2-байтовым big-endian префиксом длины в TCP-поток.
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

// ReadTCPFrame считывает один Argon4-кадр из TCP-потока по 2-байтовому префиксу длины.
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

// ResolveInterfaceTCPAddr вычисляет TCP-адрес прослушивания с привязкой к интерфейсу и опциональной подстановкой порта.
func ResolveInterfaceTCPAddr(ifName, listenAddr string, fallbackPort int) (*net.TCPAddr, error) {
	udpAddr, err := ResolveInterfaceUDPAddr(ifName, listenAddr)
	if err != nil {
		return nil, err
	}
	port := udpAddr.Port
	if port == 0 && fallbackPort > 0 {
		port = fallbackPort
	}
	return &net.TCPAddr{
		IP:   udpAddr.IP,
		Port: port,
		Zone: udpAddr.Zone,
	}, nil
}

type blindUDPPeer struct {
	clientAddr *net.UDPAddr
	targetConn *net.UDPConn
	lastActive atomic.Int64
}

// StartTransit запускает UDP- и TCP-слушатели для L4-транзита на одном порту.
func (s *Server) StartTransit() error {
	if err := s.StartUDP(); err != nil {
		return err
	}
	fallbackPort := 0
	if ua, ok := s.udpConn.LocalAddr().(*net.UDPAddr); ok {
		fallbackPort = ua.Port
	}
	if err := s.startTCPWithPort(fallbackPort); err != nil {
		_ = s.udpConn.Close()
		s.udpConn = nil
		return err
	}
	return nil
}

// StartTCP запускает TCP-слушатель Argon4 L4-транзита.
func (s *Server) StartTCP() error {
	return s.startTCPWithPort(0)
}

func (s *Server) startTCPWithPort(fallbackPort int) error {
	addr, err := ResolveInterfaceTCPAddr(s.cfg.ListenInterface, s.cfg.ListenAddr, fallbackPort)
	if err != nil {
		return err
	}
	ln, err := net.ListenTCP("tcp4", addr)
	if err != nil {
		return err
	}
	s.tcpListener = ln
	log.Printf("[argon4-server] приём TCP-транзита на %s (blind=%v, target=%q, allow=%v)",
		ln.Addr().String(), s.cfg.TransitBlind, s.cfg.TransitTarget, s.cfg.TransitAllow)
	return nil
}

// TCPLocalAddr возвращает локальный адрес TCP-слушателя транзита (если запущен).
func (s *Server) TCPLocalAddr() net.Addr {
	if s.tcpListener == nil {
		return nil
	}
	return s.tcpListener.Addr()
}

func (s *Server) resolveTransitTarget(requestedTarget, mode string) (string, error) {
	candidate := strings.TrimSpace(requestedTarget)
	if candidate == "" {
		candidate = strings.TrimSpace(s.cfg.TransitTarget)
	}
	if candidate == "" {
		return "", ErrTransitDenied
	}

	reqHost, reqPort, err := splitHostAndPort(candidate)
	if err != nil {
		return "", fmt.Errorf("%w: %v", ErrTransitDenied, err)
	}

	if len(s.cfg.TransitAllow) == 0 {
		pinned := strings.TrimSpace(s.cfg.TransitTarget)
		if pinned == "" {
			return "", ErrTransitDenied
		}
		pinnedHost, pinnedPort, err := splitHostAndPort(pinned)
		if err != nil {
			return "", fmt.Errorf("%w: некорректный -transit-target", ErrTransitDenied)
		}
		if reqPort == pinnedPort && sameHost(reqHost, pinnedHost) {
			return pinned, nil
		}
		if mode == ModeAuto && reqPort == pinnedPort && isLoopbackHost(pinnedHost) {
			return pinned, nil
		}
		return "", fmt.Errorf("%w: цель %q не совпадает с разрешённой %q", ErrTransitDenied, candidate, pinned)
	}

	for _, rule := range s.cfg.TransitAllow {
		if matchTransitRule(rule, reqHost, reqPort) {
			return net.JoinHostPort(reqHost, strconv.Itoa(reqPort)), nil
		}
	}
	return "", fmt.Errorf("%w: цель %q не входит в список allow", ErrTransitDenied, candidate)
}

func splitHostAndPort(addr string) (string, int, error) {
	host, portStr, err := net.SplitHostPort(strings.TrimSpace(addr))
	if err != nil {
		return "", 0, err
	}
	host = strings.TrimSpace(host)
	if host == "" {
		return "", 0, fmt.Errorf("пустой хост")
	}
	port, err := strconv.Atoi(portStr)
	if err != nil || port < 1 || port > 65535 {
		return "", 0, fmt.Errorf("некорректный порт %q", portStr)
	}
	return host, port, nil
}

func sameHost(a, b string) bool {
	if strings.EqualFold(strings.TrimSpace(a), strings.TrimSpace(b)) {
		return true
	}
	ipA := net.ParseIP(strings.TrimSpace(a))
	ipB := net.ParseIP(strings.TrimSpace(b))
	return ipA != nil && ipB != nil && ipA.Equal(ipB)
}

func isLoopbackHost(host string) bool {
	if strings.EqualFold(host, "localhost") {
		return true
	}
	ip := net.ParseIP(host)
	return ip != nil && ip.IsLoopback()
}

func matchTransitRule(rule, host string, port int) bool {
	rule = strings.TrimSpace(rule)
	if rule == "" {
		return false
	}
	if rule == "*" {
		return true
	}

	ruleHost := rule
	rulePort := 0
	if h, p, err := net.SplitHostPort(rule); err == nil {
		if p != "*" {
			pn, perr := strconv.Atoi(p)
			if perr != nil || pn < 1 || pn > 65535 {
				return false
			}
			rulePort = pn
		}
		ruleHost = h
	}
	if rulePort != 0 && rulePort != port {
		return false
	}
	if ruleHost == "*" {
		return true
	}
	if _, ipNet, err := net.ParseCIDR(ruleHost); err == nil {
		if ip := net.ParseIP(host); ip != nil {
			return ipNet.Contains(ip)
		}
		ips, err := net.LookupIP(host)
		if err != nil {
			return false
		}
		for _, ip := range ips {
			if ipNet.Contains(ip) {
				return true
			}
		}
		return false
	}
	return sameHost(ruleHost, host)
}

func (s *Server) handleTransitHandshake(pkt, psk, salt, hsKey, clientAuth, srvPub, c2sKey, s2cKey []byte, headerVersion byte, sessionPad PaddingConfig, req HandshakeRequest, now time.Time, remoteAddr *net.UDPAddr) []byte {
	if req.Mode == ModeTunnel {
		s.logDrop(remoteAddr, "transit mode mismatch", ErrTransitDenied)
		return nil
	}
	target, err := s.resolveTransitTarget(req.Target, req.Mode)
	if err != nil {
		s.logDrop(remoteAddr, "transit target", err)
		return nil
	}

	proto := strings.ToLower(strings.TrimSpace(req.Proto))
	if proto == "" {
		proto = ProtoUDP
	}
	if proto != ProtoUDP && proto != ProtoTCP {
		s.logDrop(remoteAddr, "transit proto", fmt.Errorf("неизвестный протокол %q", proto))
		return nil
	}

	// Для auto-пробы перед последующим DialTransitTCP возвращаем подтверждение режима без лишнего UDP-сокета.
	var targetConn *net.UDPConn
	if proto == ProtoUDP {
		raddr, err := net.ResolveUDPAddr("udp", target)
		if err != nil {
			s.logDrop(remoteAddr, "transit resolve udp", err)
			return nil
		}
		conn, err := net.DialUDP("udp", nil, raddr)
		if err != nil {
			s.logDrop(remoteAddr, "transit dial udp", err)
			return nil
		}
		targetConn = conn
	}

	peer, err := s.sessions.RegisterTransitSessionWithProtocol(req.ClientID, clientAuth, salt, c2sKey, s2cKey, remoteAddr, target, proto, targetConn, headerVersion, sessionPad, s.maxOuterUDP)
	if err != nil {
		if targetConn != nil {
			_ = targetConn.Close()
		}
		s.logDrop(remoteAddr, "transit register", err)
		return nil
	}

	resp := HandshakeResponse{
		MTU:        s.cfg.MTU,
		ServerTime: now.Unix(),
		ServerPub:  hex.EncodeToString(srvPub),
		Mode:       ModeTransit,
		Target:     target,
	}
	applyProtocolResponseFields(&resp, headerVersion, sessionPad)
	respBytes, _ := json.Marshal(resp)
	wireResp, err := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, SealOptions{
		Version:       headerVersion,
		Padding:       sessionPad,
		MaxOuterBytes: s.maxOuterUDP,
	})
	if err != nil {
		s.sessions.RemoveSession(salt)
		return nil
	}
	s.cacheHandshakeResponse(pkt, salt, remoteAddr, wireResp)
	if targetConn != nil {
		go s.udpTransitReturnLoop(peer)
	}
	log.Printf("[argon4-server] транзитное рукопожатие ОК: клиент=%q v%d адрес=%s proto=%s цель=%s",
		req.ClientID, headerVersion, remoteAddr, proto, target)
	return wireResp
}

func (s *Server) udpTransitReturnLoop(sess *PeerSession) {
	if sess == nil || sess.TargetConn == nil || s.sessions == nil {
		return
	}
	buf := make([]byte, 65535)
	psk := s.sessions.PSK()
	opts := sess.sealOptions(s.maxOuterUDP)
	idleTimeout := s.sessions.IdleTimeout()
	for {
		_ = sess.TargetConn.SetReadDeadline(time.Now().Add(idleTimeout))
		n, err := sess.TargetConn.Read(buf)
		if err != nil {
			if isTimeoutNetError(err) && s.now().Sub(sess.LastSeen()) < idleTimeout {
				continue
			}
			s.sessions.RemoveSession(sess.Salt[:])
			return
		}
		wirePkt, err := SealPacketWithOptions(FrameTypeData, sess.Salt[:], psk, sess.S2CKey, sess.NextS2CSeq(), buf[:n], opts)
		if err != nil {
			continue
		}
		if addr := sess.RemoteAddr(); addr != nil && s.udpConn != nil {
			_, _ = s.udpConn.WriteToUDP(wirePkt, addr)
		}
	}
}

func (s *Server) tcpAcceptLoop() error {
	if s.tcpListener == nil {
		select {}
	}
	for {
		conn, err := s.tcpListener.AcceptTCP()
		if err != nil {
			return err
		}
		go s.handleTCPConn(conn)
	}
}

func (s *Server) handleTCPConn(clientConn *net.TCPConn) {
	defer clientConn.Close()
	_ = clientConn.SetNoDelay(true)

	if s.cfg.TransitBlind {
		s.handleBlindTCP(clientConn)
		return
	}
	if s.sessions == nil {
		return
	}

	_ = clientConn.SetReadDeadline(time.Now().Add(TransitDialTimeout))
	hsFrame, err := ReadTCPFrame(clientConn)
	if err != nil {
		return
	}

	psk := s.sessions.PSK()
	remoteUDP := tcpToUDPAddr(clientConn.RemoteAddr())
	headerVersion, frameType, salt, _, _, _, err := ParseAndVerifyHeader(hsFrame, psk)
	if err != nil || frameType != FrameTypeHandshakeReq || !s.isHandshakeVersionAllowed(headerVersion) {
		s.logDrop(remoteUDP, "tcp header", err)
		return
	}

	hsKey, req, clientAuth, srvPub, c2sKey, s2cKey, sessionPad, now, ok := s.authenticateHandshake(hsFrame, psk, salt, headerVersion, MaxTCPFrameSize, remoteUDP)
	if !ok {
		return
	}
	if req.Mode == ModeTunnel {
		s.logDrop(remoteUDP, "tcp transit mode", ErrTransitDenied)
		return
	}
	target, err := s.resolveTransitTarget(req.Target, req.Mode)
	if err != nil {
		s.logDrop(remoteUDP, "tcp transit target", err)
		return
	}

	targetRaw, err := net.DialTimeout("tcp", target, TransitDialTimeout)
	if err != nil {
		s.logDrop(remoteUDP, "tcp transit dial", err)
		return
	}
	defer targetRaw.Close()
	if tcpTarget, ok := targetRaw.(*net.TCPConn); ok {
		_ = tcpTarget.SetNoDelay(true)
	}

	sess, err := s.sessions.RegisterTransitSessionWithProtocol(req.ClientID, clientAuth, salt, c2sKey, s2cKey, remoteUDP, target, ProtoTCP, nil, headerVersion, sessionPad, MaxTCPFrameSize)
	if err != nil {
		s.logDrop(remoteUDP, "tcp transit register", err)
		return
	}
	defer s.sessions.RemoveSession(salt)

	resp := HandshakeResponse{
		MTU:        s.cfg.MTU,
		ServerTime: now.Unix(),
		ServerPub:  hex.EncodeToString(srvPub),
		Mode:       ModeTransit,
		Target:     target,
	}
	applyProtocolResponseFields(&resp, headerVersion, sessionPad)
	respBytes, _ := json.Marshal(resp)
	tcpSealOpts := sess.sealOptions(MaxTCPFrameSize)
	wireResp, err := SealPacketWithOptions(FrameTypeHandshakeResp, salt, psk, hsKey, 1, respBytes, tcpSealOpts)
	if err != nil {
		return
	}
	if err := WriteTCPFrame(clientConn, wireResp); err != nil {
		return
	}
	_ = clientConn.SetReadDeadline(time.Time{})

	log.Printf("[argon4-server] TCP-транзит ОК: клиент=%q v%d адрес=%s цель=%s",
		req.ClientID, headerVersion, clientConn.RemoteAddr(), target)

	var writeMu sync.Mutex
	var closeOnce sync.Once
	closeBoth := func() {
		closeOnce.Do(func() {
			_ = targetRaw.Close()
			_ = clientConn.Close()
		})
	}

	idleTimeout := s.sessions.IdleTimeout()
	errCh := make(chan error, 2)
	// Цель -> клиент (шифруем поток S2C-кадрами).
	go func() {
		buf := make([]byte, TransitTCPChunkSize)
		for {
			_ = targetRaw.SetReadDeadline(time.Now().Add(idleTimeout))
			n, rerr := targetRaw.Read(buf)
			if n > 0 {
				wirePkt, serr := SealPacketWithOptions(FrameTypeData, sess.Salt[:], psk, sess.S2CKey, sess.NextS2CSeq(), buf[:n], tcpSealOpts)
				if serr != nil {
					errCh <- serr
					return
				}
				writeMu.Lock()
				werr := WriteTCPFrame(clientConn, wirePkt)
				writeMu.Unlock()
				if werr != nil {
					errCh <- werr
					return
				}
			}
			if rerr != nil {
				errCh <- rerr
				return
			}
		}
	}()

	// Клиент -> цель (проверяем анти-повтор, расшифровываем C2S и пишем в цель).
	go func() {
		openOpts := OpenOptions{
			ExpectedVersion: sess.ProtocolVersion,
			MaxOuterBytes:   MaxTCPFrameSize,
		}
		for {
			_ = clientConn.SetReadDeadline(time.Now().Add(idleTimeout))
			pkt, rerr := ReadTCPFrame(clientConn)
			if rerr != nil {
				errCh <- rerr
				return
			}
			frameVer, ft, frameSalt, _, seq, _, perr := ParseAndVerifyHeader(pkt, psk)
			if perr != nil || len(frameSalt) != SaltSize || !bytes.Equal(frameSalt, sess.Salt[:]) {
				errCh <- fmt.Errorf("некорректный заголовок TCP-транзита")
				return
			}
			if frameVer != sess.ProtocolVersion {
				errCh <- ErrDowngradeRejected
				return
			}
			if !sess.CheckReplay(seq) {
				errCh <- ErrReplayPacket
				return
			}
			opened, oerr := OpenPacketWithOptions(pkt, psk, sess.C2SKey, openOpts)
			if oerr != nil {
				errCh <- oerr
				return
			}
			if !sess.AcceptReplay(seq) {
				errCh <- ErrReplayPacket
				return
			}
			plaintext := opened.Plaintext
			s.sessions.TouchSession(sess, remoteUDP)

			switch ft {
			case FrameTypeKeepalive:
				if bytes.Equal(plaintext, []byte("fin")) {
					if cw, ok := targetRaw.(interface{ CloseWrite() error }); ok {
						_ = cw.CloseWrite()
					}
					continue
				}
				pong, serr := SealPacketWithOptions(FrameTypeKeepalive, sess.Salt[:], psk, sess.S2CKey, sess.NextS2CSeq(), []byte("pong"), tcpSealOpts)
				if serr == nil {
					writeMu.Lock()
					werr := WriteTCPFrame(clientConn, pong)
					writeMu.Unlock()
					if werr != nil {
						errCh <- werr
						return
					}
				}
			case FrameTypeData:
				if len(plaintext) > 0 {
					_ = targetRaw.SetWriteDeadline(time.Now().Add(TransitDialTimeout))
					if _, werr := targetRaw.Write(plaintext); werr != nil {
						errCh <- werr
						return
					}
					_ = targetRaw.SetWriteDeadline(time.Time{})
				}
			}
		}
	}()

	<-errCh
	closeBoth()
	<-errCh
}

func (s *Server) handleBlindUDP(pkt []byte, clientAddr *net.UDPAddr) {
	if clientAddr == nil || len(pkt) == 0 {
		return
	}
	peer, err := s.getOrCreateBlindUDPPeer(clientAddr)
	if err != nil {
		s.logDrop(clientAddr, "blind udp target", err)
		return
	}
	peer.lastActive.Store(time.Now().UnixNano())
	_, _ = peer.targetConn.Write(pkt)
}

func (s *Server) getOrCreateBlindUDPPeer(clientAddr *net.UDPAddr) (*blindUDPPeer, error) {
	key := clientAddr.String()
	s.blindMu.Lock()
	if peer, ok := s.blindUDP[key]; ok {
		s.blindMu.Unlock()
		return peer, nil
	}
	s.blindMu.Unlock()

	raddr, err := net.ResolveUDPAddr("udp", s.cfg.TransitTarget)
	if err != nil {
		return nil, err
	}
	targetConn, err := net.DialUDP("udp", nil, raddr)
	if err != nil {
		return nil, err
	}

	peer := &blindUDPPeer{
		clientAddr: cloneUDPAddr(clientAddr),
		targetConn: targetConn,
	}
	peer.lastActive.Store(time.Now().UnixNano())

	s.blindMu.Lock()
	if existing, ok := s.blindUDP[key]; ok {
		s.blindMu.Unlock()
		_ = targetConn.Close()
		return existing, nil
	}
	if s.blindUDP == nil {
		s.blindUDP = make(map[string]*blindUDPPeer)
	}
	s.blindUDP[key] = peer
	s.blindMu.Unlock()

	go s.blindUDPReturnLoop(key, peer)
	return peer, nil
}

func (s *Server) blindUDPReturnLoop(key string, peer *blindUDPPeer) {
	defer func() {
		_ = peer.targetConn.Close()
		s.blindMu.Lock()
		if cur, ok := s.blindUDP[key]; ok && cur == peer {
			delete(s.blindUDP, key)
		}
		s.blindMu.Unlock()
	}()

	buf := make([]byte, 65535)
	for {
		_ = peer.targetConn.SetReadDeadline(time.Now().Add(BlindUDPIdleTimeout))
		n, err := peer.targetConn.Read(buf)
		if err != nil {
			return
		}
		peer.lastActive.Store(time.Now().UnixNano())
		if s.udpConn != nil {
			_, _ = s.udpConn.WriteToUDP(buf[:n], peer.clientAddr)
		}
	}
}

func (s *Server) closeAllBlindUDP() {
	s.blindMu.Lock()
	peers := make([]*blindUDPPeer, 0, len(s.blindUDP))
	for k, p := range s.blindUDP {
		peers = append(peers, p)
		delete(s.blindUDP, k)
	}
	s.blindMu.Unlock()
	for _, p := range peers {
		_ = p.targetConn.Close()
	}
}

func (s *Server) handleBlindTCP(clientConn *net.TCPConn) {
	targetConn, err := net.DialTimeout("tcp", s.cfg.TransitTarget, TransitDialTimeout)
	if err != nil {
		return
	}
	defer targetConn.Close()
	if tcpTarget, ok := targetConn.(*net.TCPConn); ok {
		_ = tcpTarget.SetNoDelay(true)
	}

	var wg sync.WaitGroup
	wg.Add(2)
	go func() {
		defer wg.Done()
		_, _ = io.Copy(targetConn, clientConn)
		if cw, ok := targetConn.(interface{ CloseWrite() error }); ok {
			_ = cw.CloseWrite()
		}
	}()
	go func() {
		defer wg.Done()
		_, _ = io.Copy(clientConn, targetConn)
		_ = clientConn.CloseWrite()
	}()
	wg.Wait()
}

func tcpToUDPAddr(addr net.Addr) *net.UDPAddr {
	ta, ok := addr.(*net.TCPAddr)
	if !ok || ta == nil {
		return &net.UDPAddr{}
	}
	return &net.UDPAddr{
		IP:   append(net.IP(nil), ta.IP...),
		Port: ta.Port,
		Zone: ta.Zone,
	}
}
