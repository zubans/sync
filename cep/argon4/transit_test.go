package argon4

import (
	"bytes"
	"context"
	"encoding/hex"
	"encoding/json"
	"io"
	"net"
	"testing"
	"time"
)

func startMockArgonTransitServer(t *testing.T, password, mode string, udpEcho, tcpEcho net.Addr) (string, func()) {
	t.Helper()
	psk := DerivePSK(password, DefaultParams)

	udpLn, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 0})
	if err != nil {
		t.Fatal(err)
	}
	port := udpLn.LocalAddr().(*net.UDPAddr).Port
	tcpLn, err := net.ListenTCP("tcp4", &net.TCPAddr{IP: net.IPv4(127, 0, 0, 1), Port: port})
	if err != nil {
		_ = udpLn.Close()
		t.Fatal(err)
	}

	var stopCh = make(chan struct{})
	// UDP loop (tunnel / transit / auto probe)
	go func() {
		buf := make([]byte, 65535)
		var targetConn *net.UDPConn
		if udpEcho != nil {
			targetConn, _ = net.DialUDP("udp4", nil, udpEcho.(*net.UDPAddr))
			defer func() {
				if targetConn != nil {
					_ = targetConn.Close()
				}
			}()
		}
		var lastClient *net.UDPAddr
		var salt [SaltSize]byte
		var c2s, s2c []byte
		var seq uint64
		if targetConn != nil {
			go func() {
				tbuf := make([]byte, 4096)
				for {
					_ = targetConn.SetReadDeadline(time.Now().Add(2 * time.Second))
					n, rerr := targetConn.Read(tbuf)
					if rerr != nil {
						select {
						case <-stopCh:
							return
						default:
							continue
						}
					}
					if lastClient != nil && len(s2c) > 0 {
						seq++
						wire, _ := SealPacketWithSeq(FrameTypeData, salt[:], psk, s2c, seq, tbuf[:n])
						_, _ = udpLn.WriteToUDP(wire, lastClient)
					}
				}
			}()
		}
		for {
			n, raddr, err := udpLn.ReadFromUDP(buf)
			if err != nil {
				return
			}
			_, ft, frameSalt, _, _, _, perr := ParseAndVerifyHeader(buf[:n], psk)
			if perr != nil {
				continue
			}
			if ft == FrameTypeHandshakeReq {
				hsKey := DeriveKey(password, frameSalt, DefaultParams)
				_, _, _, plain, oerr := OpenPacketWithKey(buf[:n], psk, hsKey)
				if oerr != nil {
					continue
				}
				var req HandshakeRequest
				if err := json.Unmarshal(plain, &req); err != nil {
					continue
				}
				cliPub, _ := hex.DecodeString(req.ClientPub)
				srvPriv, srvPub, _ := GenerateX25519Keypair()
				shared, _ := ComputeX25519Shared(srvPriv, cliPub)
				c2s, s2c = DeriveSessionKeys(hsKey, shared, frameSalt)
				copy(salt[:], frameSalt)
				lastClient = raddr
				resp := HandshakeResponse{
					ServerTime: time.Now().Unix(),
					ServerPub:  hex.EncodeToString(srvPub),
					MTU:        1380,
					Mode:       mode,
					Target:     req.Target,
				}
				if mode == ModeTunnel {
					resp.AssignedIP = "10.44.0.2"
					resp.ServerIP = "10.44.0.1"
					resp.SubnetCidr = 24
				}
				rb, _ := json.Marshal(resp)
				wr, _ := SealPacketWithSeq(FrameTypeHandshakeResp, frameSalt, psk, hsKey, 1, rb)
				_, _ = udpLn.WriteToUDP(wr, raddr)
				continue
			}
			if len(c2s) == 0 {
				continue
			}
			_, _, _, plain, oerr := OpenPacketWithKey(buf[:n], psk, c2s)
			if oerr != nil {
				continue
			}
			lastClient = raddr
			if ft == FrameTypeData && targetConn != nil {
				_, _ = targetConn.Write(plain)
			}
		}
	}()

	// TCP transit loop
	go func() {
		for {
			c, err := tcpLn.Accept()
			if err != nil {
				return
			}
			go func(conn net.Conn) {
				defer conn.Close()
				hsFrame, err := ReadTCPFrame(conn)
				if err != nil {
					return
				}
				_, ft, frameSalt, _, _, _, perr := ParseAndVerifyHeader(hsFrame, psk)
				if perr != nil || ft != FrameTypeHandshakeReq {
					return
				}
				hsKey := DeriveKey(password, frameSalt, DefaultParams)
				_, _, _, plain, oerr := OpenPacketWithKey(hsFrame, psk, hsKey)
				if oerr != nil {
					return
				}
				var req HandshakeRequest
				_ = json.Unmarshal(plain, &req)
				cliPub, _ := hex.DecodeString(req.ClientPub)
				srvPriv, srvPub, _ := GenerateX25519Keypair()
				shared, _ := ComputeX25519Shared(srvPriv, cliPub)
				c2s, s2c := DeriveSessionKeys(hsKey, shared, frameSalt)

				upstream, err := net.Dial("tcp4", tcpEcho.String())
				if err != nil {
					return
				}
				defer upstream.Close()

				resp := HandshakeResponse{
					ServerTime: time.Now().Unix(),
					ServerPub:  hex.EncodeToString(srvPub),
					MTU:        1380,
					Mode:       ModeTransit,
					Target:     req.Target,
				}
				rb, _ := json.Marshal(resp)
				wr, _ := SealPacketWithSeq(FrameTypeHandshakeResp, frameSalt, psk, hsKey, 1, rb)
				if err := WriteTCPFrame(conn, wr); err != nil {
					return
				}

				var seq uint64
				go func() {
					buf := make([]byte, TransitTCPChunkSize)
					for {
						n, rerr := upstream.Read(buf)
						if n > 0 {
							seq++
							w, _ := SealPacketWithSeq(FrameTypeData, frameSalt, psk, s2c, seq, buf[:n])
							if werr := WriteTCPFrame(conn, w); werr != nil {
								return
							}
						}
						if rerr != nil {
							_ = conn.Close()
							return
						}
					}
				}()
				for {
					f, rerr := ReadTCPFrame(conn)
					if rerr != nil {
						return
					}
					ft, _, _, plain, oerr := OpenPacketWithKey(f, psk, c2s)
					if oerr != nil {
						return
					}
					if ft == FrameTypeKeepalive && bytes.Equal(plain, []byte("fin")) {
						if cw, ok := upstream.(interface{ CloseWrite() error }); ok {
							_ = cw.CloseWrite()
						}
						continue
					}
					if ft == FrameTypeData {
						_, _ = upstream.Write(plain)
					}
				}
			}(c)
		}
	}()

	return udpLn.LocalAddr().String(), func() {
		close(stopCh)
		_ = udpLn.Close()
		_ = tcpLn.Close()
	}
}

func TestTransitProxyTCPAndUDP(t *testing.T) {
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
			_, _ = udpEcho.WriteToUDP(append([]byte("u:"), buf[:n]...), addr)
		}
	}()

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

	srvAddr, stopSrv := startMockArgonTransitServer(t, "pass-123", ModeTransit, udpEcho.LocalAddr(), tcpEcho.Addr())
	defer stopSrv()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// TCP transit proxy
	tcpProxy, err := StartTransitProxy(ctx, ClientConfig{
		Server:         srvAddr,
		MasterPassword: "pass-123",
		Target:         tcpEcho.Addr().String(),
		Proto:          ProtoTCP,
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer tcpProxy.Close()

	c, err := net.Dial("tcp4", tcpProxy.ListenAddr())
	if err != nil {
		t.Fatal(err)
	}
	payload := bytes.Repeat([]byte("cep-argon4-tcp-transit!"), 900)
	go func() {
		_, _ = c.Write(payload)
		if tc, ok := c.(*net.TCPConn); ok {
			_ = tc.CloseWrite()
		}
	}()
	_ = c.SetReadDeadline(time.Now().Add(3 * time.Second))
	got := make([]byte, len(payload))
	if _, err := io.ReadFull(c, got); err != nil {
		t.Fatal(err)
	}
	_ = c.Close()
	if !bytes.Equal(got, payload) {
		t.Fatalf("TCP transit mismatch: got %d want %d", len(got), len(payload))
	}

	// UDP transit proxy
	udpProxy, err := StartTransitProxy(ctx, ClientConfig{
		Server:         srvAddr,
		MasterPassword: "pass-123",
		Target:         udpEcho.LocalAddr().String(),
		Proto:          ProtoUDP,
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer udpProxy.Close()

	uc, err := net.Dial("udp4", udpProxy.ListenAddr())
	if err != nil {
		t.Fatal(err)
	}
	defer uc.Close()
	if _, err := uc.Write([]byte("hello")); err != nil {
		t.Fatal(err)
	}
	ubuf := make([]byte, 1024)
	_ = uc.SetReadDeadline(time.Now().Add(3 * time.Second))
	n, err := uc.Read(ubuf)
	if err != nil {
		t.Fatal(err)
	}
	if string(ubuf[:n]) != "u:hello" {
		t.Fatalf("UDP transit mismatch: got %q", string(ubuf[:n]))
	}
}

func TestConnectAutoNegotiatesTunnelAndTransit(t *testing.T) {
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

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// 1. Tunnel server responds with tunnel client (proxy == nil)
	tunSrv, stopTun := startMockArgonTransitServer(t, "auto-pass", ModeTunnel, nil, tcpEcho.Addr())
	defer stopTun()
	proxy, cli, err := ConnectAuto(ctx, ClientConfig{
		Server:         tunSrv,
		MasterPassword: "auto-pass",
		Mode:           ModeAuto,
		Target:         tcpEcho.Addr().String(),
		Proto:          ProtoTCP,
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	if proxy != nil || cli == nil || cli.IsTransit() {
		t.Fatalf("expected tunnel client from ConnectAuto, got proxy=%v cli=%v", proxy, cli)
	}
	_ = cli.Close()

	// 2. Transit server responds with TransitProxy (cli == nil)
	trSrv, stopTr := startMockArgonTransitServer(t, "auto-pass", ModeTransit, nil, tcpEcho.Addr())
	defer stopTr()
	proxy, cli, err = ConnectAuto(ctx, ClientConfig{
		Server:         trSrv,
		MasterPassword: "auto-pass",
		Mode:           ModeAuto,
		Target:         tcpEcho.Addr().String(),
		Proto:          ProtoTCP,
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	if proxy == nil || cli != nil {
		t.Fatalf("expected TransitProxy from ConnectAuto, got proxy=%v cli=%v", proxy, cli)
	}
	_ = proxy.Close()
}
