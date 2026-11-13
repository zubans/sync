package tunnel

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"strconv"
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
		ListenAddr:    "127.0.0.1:0",
		MasterPassword: "transit-password",
		ArgonParams:   testFastArgonParams(),
		Mode:          ModeTransit,
		TransitTarget: echoConn.LocalAddr().String(),
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
		ListenAddr:    "127.0.0.1:0",
		MasterPassword: "correct-password",
		ArgonParams:   testFastArgonParams(),
		Mode:          ModeTransit,
		TransitTarget: echoConn.LocalAddr().String(),
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
