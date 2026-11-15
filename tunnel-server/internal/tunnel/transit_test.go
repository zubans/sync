package tunnel

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"strconv"
	"sync/atomic"
	"testing"
	"time"
)

func testFastArgonParams() ArgonParams {
	return ArgonParams{Time: 1, Memory: 1024, Threads: 1}
}

func doTransitClientSetup(t *testing.T, password, mode, target, proto string) (
	psk, salt []byte,
	reqWire []byte,
	finish func(respWire []byte) (HandshakeResponse, []byte, []byte),
) {
	t.Helper()
	salt = make([]byte, SaltSize)
	if _, err := rand.Read(salt); err != nil {
		t.Fatal(err)
	}
	params := testFastArgonParams()
	psk = DerivePSK(password, params)
	hsKey := DeriveKey(password, salt, params)

	cliPriv, cliPub, err := GenerateX25519Keypair()
	if err != nil {
		t.Fatal(err)
	}
	req := HandshakeRequest{
		ClientID:   "transit-test-client",
		ClientAuth: hex.EncodeToString(ClientAuthToken(psk, "transit-test-client", "sec")),
		ClientPub:  hex.EncodeToString(cliPub),
		Timestamp:  time.Now().Unix(),
		Mode:       mode,
		Target:     target,
		Proto:      proto,
	}
	reqBytes, err := json.Marshal(req)
	if err != nil {
		t.Fatal(err)
	}
	reqWire, err = SealPacketWithSeq(FrameTypeHandshakeReq, salt, psk, hsKey, 1, reqBytes)
	if err != nil {
		t.Fatal(err)
	}
	return psk, salt, reqWire, func(respWire []byte) (HandshakeResponse, []byte, []byte) {
		t.Helper()
		ft, _, _, plain, err := OpenPacketWithKey(respWire, psk, hsKey)
		if err != nil {
			t.Fatalf("ошибка расшифровки ответа: %v", err)
		}
		if ft != FrameTypeHandshakeResp {
			t.Fatalf("ожидался FrameTypeHandshakeResp, получен %d", ft)
		}
		var resp HandshakeResponse
		if err := json.Unmarshal(plain, &resp); err != nil {
			t.Fatal(err)
		}
		srvPub, err := hex.DecodeString(resp.ServerPub)
		if err != nil {
			t.Fatal(err)
		}
		shared, err := ComputeX25519Shared(cliPriv, srvPub)
		if err != nil {
			t.Fatal(err)
		}
		c2s, s2c := DeriveSessionKeys(hsKey, shared, salt)
		return resp, c2s, s2c
	}
}

func TestAuthenticatedUDPTransit(t *testing.T) {
	echoConn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer echoConn.Close()
	go func() {
		buf := make([]byte, 4096)
		for {
			n, addr, rerr := echoConn.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			_, _ = echoConn.WriteToUDP(append([]byte("echo:"), buf[:n]...), addr)
		}
	}()

	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "transit-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitTarget:  echoConn.LocalAddr().String(),
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := srv.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer srv.Close()
	go func() { _ = srv.Run() }()

	raddr := srv.LocalAddr().(*net.UDPAddr)
	cliConn, err := net.DialUDP("udp4", nil, raddr)
	if err != nil {
		t.Fatal(err)
	}
	defer cliConn.Close()

	psk, salt, reqWire, finish := doTransitClientSetup(t, "transit-password", ModeTransit, "", ProtoUDP)
	if _, err := cliConn.Write(reqWire); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 4096)
	_ = cliConn.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err := cliConn.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	resp, c2s, s2c := finish(buf[:n])
	if resp.Mode != ModeTransit || resp.AssignedIP != "" || resp.Target != echoConn.LocalAddr().String() {
		t.Fatalf("неожиданный ответ транзитного рукопожатия: %+v", resp)
	}

	payload := []byte("hello-argon-udp-transit")
	dataWire, err := SealPacketWithSeq(FrameTypeData, salt, psk, c2s, 1, payload)
	if err != nil {
		t.Fatal(err)
	}
	if _, err := cliConn.Write(dataWire); err != nil {
		t.Fatal(err)
	}

	_ = cliConn.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err = cliConn.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	ft, _, _, plain, err := OpenPacketWithKey(buf[:n], psk, s2c)
	if err != nil || ft != FrameTypeData {
		t.Fatalf("ошибка обратного кадра UDP-транзита: ft=%d err=%v", ft, err)
	}
	if string(plain) != "echo:hello-argon-udp-transit" {
		t.Fatalf("неожиданный ответ echo UDP: %q", string(plain))
	}
}

func TestAuthenticatedTCPTransitAndReplayRejection(t *testing.T) {
	ln, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer ln.Close()
	go func() {
		for {
			c, aerr := ln.Accept()
			if aerr != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				_, _ = io.Copy(conn, conn)
			}(c)
		}
	}()

	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "tcp-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitAllow:   []string{"127.0.0.0/8:" + strconv.Itoa(ln.Addr().(*net.TCPAddr).Port)},
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := srv.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer srv.Close()
	go func() { _ = srv.Run() }()

	tcpAddr := srv.TCPLocalAddr().(*net.TCPAddr)
	conn, err := net.DialTCP("tcp4", nil, tcpAddr)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	psk, salt, reqWire, finish := doTransitClientSetup(t, "tcp-password", ModeTransit, ln.Addr().String(), ProtoTCP)
	if err := WriteTCPFrame(conn, reqWire); err != nil {
		t.Fatal(err)
	}
	respWire, err := ReadTCPFrame(conn)
	if err != nil {
		t.Fatal(err)
	}
	resp, c2s, s2c := finish(respWire)
	if resp.Mode != ModeTransit || resp.Target != ln.Addr().String() {
		t.Fatalf("некорректный ответ TCP-транзита: %+v", resp)
	}

	// Проверяем keepalive поверх TCP-транзита.
	kaWire, err := SealPacketWithSeq(FrameTypeKeepalive, salt, psk, c2s, 1, []byte("ping"))
	if err != nil {
		t.Fatal(err)
	}
	if err := WriteTCPFrame(conn, kaWire); err != nil {
		t.Fatal(err)
	}
	pongWire, err := ReadTCPFrame(conn)
	if err != nil {
		t.Fatal(err)
	}
	ft, _, _, pongPlain, err := OpenPacketWithKey(pongWire, psk, s2c)
	if err != nil || ft != FrameTypeKeepalive || string(pongPlain) != "pong" {
		t.Fatalf("некорректный pong: ft=%d plain=%q err=%v", ft, pongPlain, err)
	}

	// Передаём крупный буфер (> TransitTCPChunkSize), нарезанный на кадры.
	large := bytes.Repeat([]byte("Argon4TCPTransitStream!"), 1200) // ~27.6 KiB
	chunk1, _ := SealPacketWithSeq(FrameTypeData, salt, psk, c2s, 2, large[:TransitTCPChunkSize])
	chunk2, _ := SealPacketWithSeq(FrameTypeData, salt, psk, c2s, 3, large[TransitTCPChunkSize:])
	if err := WriteTCPFrame(conn, chunk1); err != nil {
		t.Fatal(err)
	}
	if err := WriteTCPFrame(conn, chunk2); err != nil {
		t.Fatal(err)
	}

	var received []byte
	_ = conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	for len(received) < len(large) {
		f, rerr := ReadTCPFrame(conn)
		if rerr != nil {
			t.Fatal(rerr)
		}
		ft, _, _, plain, oerr := OpenPacketWithKey(f, psk, s2c)
		if oerr != nil || ft != FrameTypeData {
			t.Fatalf("ошибка кадра данных TCP: ft=%d err=%v", ft, oerr)
		}
		received = append(received, plain...)
	}
	if !bytes.Equal(received, large) {
		t.Fatalf("расхождение данных TCP-транзита: got=%d want=%d", len(received), len(large))
	}

	// Повторный кадр (seq=2) должен закрыть соединение по анти-повтору.
	_ = WriteTCPFrame(conn, chunk1)
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	if _, rerr := ReadTCPFrame(conn); rerr == nil {
		t.Fatalf("ожидалось закрытие TCP-соединения при replay-повторе")
	}
}

func TestTransitTargetPolicyAndWrongPassword(t *testing.T) {
	echoConn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer echoConn.Close()

	srv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "correct-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitTarget:  echoConn.LocalAddr().String(),
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer srv.Close()

	// 1. Запрещённая цель отклоняется в режиме фиксированного -transit-target.
	_, _, badTargetWire, _ := doTransitClientSetup(t, "correct-password", ModeTransit, "127.0.0.1:9", ProtoUDP)
	if resp := srv.HandlePacket(badTargetWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40001}); resp != nil {
		t.Fatalf("ожидался отказ для несовпадающей цели транзита")
	}

	// 2. Режим ModeTunnel на transit-сервере отбрасывается.
	_, _, tunnelModeWire, _ := doTransitClientSetup(t, "correct-password", ModeTunnel, "", ProtoUDP)
	if resp := srv.HandlePacket(tunnelModeWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40002}); resp != nil {
		t.Fatalf("ожидался отказ для mode=tunnel на transit-сервере")
	}

	// 3. Неверный пароль отклоняется.
	_, _, wrongPassWire, _ := doTransitClientSetup(t, "wrong-password", ModeTransit, echoConn.LocalAddr().String(), ProtoUDP)
	if resp := srv.HandlePacket(wrongPassWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40003}); resp != nil {
		t.Fatalf("ожидался отказ для неверного мастер-пароля")
	}

	// 4. ModeAuto с портом локальной loopback-цели перенаправляется на TransitTarget (проба TCP без лишнего сокета).
	_, _, autoWire, finishAuto := doTransitClientSetup(t, "correct-password", ModeAuto, echoConn.LocalAddr().String(), ProtoTCP)
	respWire := srv.HandlePacket(autoWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40004})
	if respWire == nil {
		t.Fatalf("ожидался успешный ответ для mode=auto")
	}
	autoResp, _, _ := finishAuto(respWire)
	if autoResp.Mode != ModeTransit || autoResp.Target != echoConn.LocalAddr().String() {
		t.Fatalf("неожиданный auto-ответ transit-сервера: %+v", autoResp)
	}

	// 5. Обычный tunnel-сервер отклоняет явный запрос ModeTransit, но принимает ModeAuto как tunnel.
	tunSrv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "tunnel-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTunnel,
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer tunSrv.Close()
	_, _, explicitTransitWire, _ := doTransitClientSetup(t, "tunnel-password", ModeTransit, "127.0.0.1:443", ProtoTCP)
	if resp := tunSrv.HandlePacket(explicitTransitWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40005}); resp != nil {
		t.Fatalf("tunnel-сервер должен отклонять явный mode=transit")
	}
	_, _, tunAutoWire, finishTunAuto := doTransitClientSetup(t, "tunnel-password", ModeAuto, "127.0.0.1:443", ProtoTCP)
	tunRespWire := tunSrv.HandlePacket(tunAutoWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 40006})
	if tunRespWire == nil {
		t.Fatalf("tunnel-сервер должен принимать mode=auto")
	}
	tunResp, _, _ := finishTunAuto(tunRespWire)
	if tunResp.Mode != ModeTunnel || tunResp.AssignedIP == "" {
		t.Fatalf("ожидался tunnel-ответ при mode=auto: %+v", tunResp)
	}
}

func TestBlindTransitZeroKnowledgePassthrough(t *testing.T) {
	// Выходной сервер Argon4 (tunnel mode) за blind-транзитом.
	exitSrv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "exit-secret-only-client-knows",
		ArgonParams:    testFastArgonParams(),
		SubnetCIDR:     "10.44.0.1/24",
		MTU:            1380,
		Mode:           ModeTunnel,
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := exitSrv.StartUDP(); err != nil {
		t.Fatal(err)
	}
	defer exitSrv.Close()
	go func() { _ = exitSrv.Run() }()

	// Дополнительный TCP-сервер для проверки blind TCP.
	tcpEcho, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcpEcho.Close()
	go func() {
		for {
			c, aerr := tcpEcho.Accept()
			if aerr != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				_, _ = io.Copy(conn, conn)
			}(c)
		}
	}()

	// Blind UDP транзит к exitSrv (без мастер-пароля!).
	blindUDP, err := NewServer(ServerConfig{
		ListenAddr:    "127.0.0.1:0",
		Mode:          ModeTransit,
		TransitBlind:  true,
		TransitTarget: exitSrv.LocalAddr().String(),
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := blindUDP.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer blindUDP.Close()
	go func() { _ = blindUDP.Run() }()

	cliConn, err := net.DialUDP("udp4", nil, blindUDP.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer cliConn.Close()

	// Клиент общается с exitSrv через blindUDP, шифруя только паролем exitSrv.
	psk, salt, reqWire, finish := doTransitClientSetup(t, "exit-secret-only-client-knows", ModeAuto, "", ProtoUDP)
	if _, err := cliConn.Write(reqWire); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 4096)
	_ = cliConn.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err := cliConn.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	resp, c2s, s2c := finish(buf[:n])
	if resp.Mode != ModeTunnel || resp.AssignedIP != "10.44.0.2" {
		t.Fatalf("ожидался L3-ответ через blind-транзит, получено: %+v", resp)
	}

	// Keepalive через blind-транзит -> exitSrv -> обратно клиенту.
	kaWire, _ := SealPacketWithSeq(FrameTypeKeepalive, salt, psk, c2s, 1, []byte("ping"))
	_, _ = cliConn.Write(kaWire)
	_ = cliConn.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err = cliConn.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	ft, _, _, pong, err := OpenPacketWithKey(buf[:n], psk, s2c)
	if err != nil || ft != FrameTypeKeepalive || string(pong) != "pong" {
		t.Fatalf("ошибка keepalive через blind-транзит: ft=%d plain=%q err=%v", ft, pong, err)
	}

	// Отдельный blind TCP транзит -> tcpEcho (без пароля).
	blindTCP, err := NewServer(ServerConfig{
		ListenAddr:    "127.0.0.1:0",
		Mode:          ModeTransit,
		TransitBlind:  true,
		TransitTarget: tcpEcho.Addr().String(),
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := blindTCP.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer blindTCP.Close()
	go func() { _ = blindTCP.Run() }()

	tc, err := net.DialTCP("tcp4", nil, blindTCP.TCPLocalAddr().(*net.TCPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer tc.Close()
	msg := []byte("blind-tcp-stream")
	if _, err := tc.Write(msg); err != nil {
		t.Fatal(err)
	}
	_ = tc.CloseWrite()
	got, err := io.ReadAll(tc)
	if err != nil || string(got) != "blind-tcp-stream" {
		t.Fatalf("ошибка blind TCP: got=%q err=%v", got, err)
	}
}

func TestTransitAllowCIDRPinsVerifiedIPWithoutReLookup(t *testing.T) {
	// 1. UDP-транзит: домен разрешается в [127.0.0.2, 127.0.0.1] при правиле 127.0.0.1/32.
	// Повторный вызов LookupIP намеренно вернул бы только 127.0.0.2, чтобы выявить любой повторный DNS-запрос.
	udpEcho, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer udpEcho.Close()
	go func() {
		buf := make([]byte, 4096)
		for {
			n, addr, rerr := udpEcho.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			_, _ = udpEcho.WriteToUDP(append([]byte("echo:"), buf[:n]...), addr)
		}
	}()

	udpPort := udpEcho.LocalAddr().(*net.UDPAddr).Port
	var udpLookups atomic.Int32
	udpSrv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "cidr-pin-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitAllow:   []string{"127.0.0.1/32:" + strconv.Itoa(udpPort)},
		LookupIP: func(host string) ([]net.IP, error) {
			switch host {
			case "dual.example.test":
				if udpLookups.Add(1) == 1 {
					return []net.IP{net.IPv4(127, 0, 0, 2), net.IPv4(127, 0, 0, 1)}, nil
				}
				return []net.IP{net.IPv4(127, 0, 0, 2)}, nil
			case "denied.example.test":
				return []net.IP{net.IPv4(127, 0, 0, 2)}, nil
			default:
				return nil, fmt.Errorf("unexpected lookup for %q", host)
			}
		},
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := udpSrv.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer udpSrv.Close()
	go func() { _ = udpSrv.Run() }()

	cliUDP, err := net.DialUDP("udp4", nil, udpSrv.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer cliUDP.Close()

	targetHostPort := net.JoinHostPort("dual.example.test", strconv.Itoa(udpPort))
	psk, salt, reqWire, finish := doTransitClientSetup(t, "cidr-pin-password", ModeTransit, targetHostPort, ProtoUDP)
	if _, err := cliUDP.Write(reqWire); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 4096)
	_ = cliUDP.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err := cliUDP.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	resp, c2s, s2c := finish(buf[:n])
	if resp.Target != udpEcho.LocalAddr().String() {
		t.Fatalf("ожидалась фиксация проверенного IP %q в resp.Target, получено %q", udpEcho.LocalAddr().String(), resp.Target)
	}
	if gotLookups := udpLookups.Load(); gotLookups != 1 {
		t.Fatalf("ожидалось ровно 1 разрешение DNS без повторного lookup, выполнено %d", gotLookups)
	}

	dataWire, err := SealPacketWithSeq(FrameTypeData, salt, psk, c2s, 1, []byte("udp-pinned"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := cliUDP.Write(dataWire); err != nil {
		t.Fatal(err)
	}
	_ = cliUDP.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err = cliUDP.Read(buf)
	if err != nil {
		t.Fatal(err)
	}
	ft, _, _, plain, err := OpenPacketWithKey(buf[:n], psk, s2c)
	if err != nil || ft != FrameTypeData || string(plain) != "echo:udp-pinned" {
		t.Fatalf("ошибка эхо UDP через зафиксированный IP: ft=%d plain=%q err=%v", ft, plain, err)
	}

	// Отклонённый домен, у которого нет IP в 127.0.0.1/32, не допускается.
	_, _, deniedWire, _ := doTransitClientSetup(t, "cidr-pin-password", ModeTransit, net.JoinHostPort("denied.example.test", strconv.Itoa(udpPort)), ProtoUDP)
	if deniedResp := udpSrv.HandlePacket(deniedWire, &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 41001}); deniedResp != nil {
		t.Fatalf("домен с IP вне CIDR-правила должен отклоняться")
	}

	// Дополнительно проверяем выбор именно разрешённого IP при обратном порядке [127.0.0.1, 127.0.0.2] и правиле 127.0.0.2/32.
	onlySecond, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "cidr-pin-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitAllow:   []string{"127.0.0.2/32:*"},
		LookupIP: func(host string) ([]net.IP, error) {
			return []net.IP{net.IPv4(127, 0, 0, 1), net.IPv4(127, 0, 0, 2)}, nil
		},
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	defer onlySecond.Close()
	pinned, rerr := onlySecond.resolveTransitTarget("reversed.example.test:443", ModeTransit)
	if rerr != nil || pinned != "127.0.0.2:443" {
		t.Fatalf("ожидалась фиксация разрешённого IP 127.0.0.2:443, получено err=%v target=%q", rerr, pinned)
	}

	// 2. TCP-транзит: аналогичная одноразовая резолюция без повторного DNS при DialTimeout.
	tcpEcho, err := net.Listen("tcp4", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcpEcho.Close()
	go func() {
		for {
			c, aerr := tcpEcho.Accept()
			if aerr != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				_, _ = io.Copy(conn, conn)
			}(c)
		}
	}()

	tcpPort := tcpEcho.Addr().(*net.TCPAddr).Port
	var tcpLookups atomic.Int32
	tcpSrv, err := NewServer(ServerConfig{
		ListenAddr:     "127.0.0.1:0",
		MasterPassword: "tcp-cidr-password",
		ArgonParams:    testFastArgonParams(),
		Mode:           ModeTransit,
		TransitAllow:   []string{"127.0.0.1/32:" + strconv.Itoa(tcpPort)},
		LookupIP: func(host string) ([]net.IP, error) {
			if tcpLookups.Add(1) == 1 {
				return []net.IP{net.IPv4(127, 0, 0, 2), net.IPv4(127, 0, 0, 1)}, nil
			}
			return []net.IP{net.IPv4(127, 0, 0, 2)}, nil
		},
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := tcpSrv.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer tcpSrv.Close()
	go func() { _ = tcpSrv.Run() }()

	tc, err := net.DialTCP("tcp4", nil, tcpSrv.TCPLocalAddr().(*net.TCPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer tc.Close()

	tPSK, tSalt, tReqWire, tFinish := doTransitClientSetup(t, "tcp-cidr-password", ModeTransit, net.JoinHostPort("dual-tcp.example.test", strconv.Itoa(tcpPort)), ProtoTCP)
	if err := WriteTCPFrame(tc, tReqWire); err != nil {
		t.Fatal(err)
	}
	tRespWire, err := ReadTCPFrame(tc)
	if err != nil {
		t.Fatal(err)
	}
	tResp, tC2S, tS2C := tFinish(tRespWire)
	if tResp.Target != tcpEcho.Addr().String() {
		t.Fatalf("TCP transit: ожидался target %q, получено %q", tcpEcho.Addr().String(), tResp.Target)
	}
	if gotLookups := tcpLookups.Load(); gotLookups != 1 {
		t.Fatalf("TCP transit: ожидалось 1 разрешение DNS, выполнено %d", gotLookups)
	}

	tDataWire, err := SealPacketWithSeq(FrameTypeData, tSalt, tPSK, tC2S, 1, []byte("tcp-pinned"))
	if err != nil {
		t.Fatal(err)
	}
	if err := WriteTCPFrame(tc, tDataWire); err != nil {
		t.Fatal(err)
	}
	_ = tc.SetReadDeadline(time.Now().Add(3 * time.Second))
	tEchoWire, err := ReadTCPFrame(tc)
	if err != nil {
		t.Fatal(err)
	}
	ftTCP, _, _, tPlain, err := OpenPacketWithKey(tEchoWire, tPSK, tS2C)
	if err != nil || ftTCP != FrameTypeData || string(tPlain) != "tcp-pinned" {
		t.Fatalf("ошибка эхо TCP через зафиксированный IP: ft=%d plain=%q err=%v", ftTCP, tPlain, err)
	}
}

func TestBlindUDPIdleTimeoutPreservesMappingOnOneWayTraffic(t *testing.T) {
	type recvPkt struct {
		addr *net.UDPAddr
		data string
	}
	targetConn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	defer targetConn.Close()

	recvCh := make(chan recvPkt, 32)
	go func() {
		buf := make([]byte, 2048)
		for {
			n, addr, rerr := targetConn.ReadFromUDP(buf)
			if rerr != nil {
				return
			}
			recvCh <- recvPkt{addr: addr, data: string(buf[:n])}
		}
	}()

	const idleTimeout = 180 * time.Millisecond
	blindSrv, err := NewServer(ServerConfig{
		ListenAddr:          "127.0.0.1:0",
		Mode:                ModeTransit,
		TransitBlind:        true,
		TransitTarget:       targetConn.LocalAddr().String(),
		BlindUDPIdleTimeout: idleTimeout,
	}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if err := blindSrv.StartTransit(); err != nil {
		t.Fatal(err)
	}
	defer blindSrv.Close()
	go func() { _ = blindSrv.Run() }()

	cliConn, err := net.DialUDP("udp4", nil, blindSrv.LocalAddr().(*net.UDPAddr))
	if err != nil {
		t.Fatal(err)
	}
	defer cliConn.Close()

	// Односторонний поток клиент -> цель длительностью > 2.5 * idleTimeout без единого ответа от цели.
	var firstRemote *net.UDPAddr
	for i := 0; i < 10; i++ {
		msg := fmt.Sprintf("oneway-%d", i)
		if _, err := cliConn.Write([]byte(msg)); err != nil {
			t.Fatal(err)
		}
		select {
		case got := <-recvCh:
			if got.data != msg {
				t.Fatalf("неожиданная нагрузка #%d: got=%q want=%q", i, got.data, msg)
			}
			if firstRemote == nil {
				firstRemote = got.addr
			} else if got.addr.Port != firstRemote.Port || !got.addr.IP.Equal(firstRemote.IP) {
				t.Fatalf("blind UDP пересоздал сокет на #%d при активном одностороннем трафике: был %v, стал %v", i, firstRemote, got.addr)
			}
		case <-time.After(2 * time.Second):
			t.Fatalf("таймаут приёма пакета #%d на цели", i)
		}
		time.Sleep(45 * time.Millisecond)
	}

	// После >450 мс одностороннего потока обратный ответ на исходный исходящий порт всё ещё должен дойти до клиента.
	if _, err := targetConn.WriteToUDP([]byte("late-reply"), firstRemote); err != nil {
		t.Fatal(err)
	}
	buf := make([]byte, 2048)
	_ = cliConn.SetReadDeadline(time.Now().Add(2 * time.Second))
	n, err := cliConn.Read(buf)
	if err != nil || string(buf[:n]) != "late-reply" {
		t.Fatalf("ожидался поздний ответ на неизменённый порт: got=%q err=%v", string(buf[:n]), err)
	}

	// После полного простоя в обоих направлениях mapping должен корректно закрыться.
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		blindSrv.blindMu.Lock()
		remaining := len(blindSrv.blindUDP)
		blindSrv.blindMu.Unlock()
		if remaining == 0 {
			return
		}
		time.Sleep(30 * time.Millisecond)
	}
	t.Fatalf("blind UDP mapping не очистился после полного двустороннего простоя")
}
