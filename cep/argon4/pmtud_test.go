package argon4

import (
	"context"
	"encoding/binary"
	"net"
	"testing"
	"time"
)

// startUDPBottleneckProxy эмулирует реальный канал связи с фиксированным ограничением внешнего UDP payload.
func startUDPBottleneckProxy(t *testing.T, targetAddr string, maxOuterBytes int) string {
	t.Helper()
	raddr, err := net.ResolveUDPAddr("udp4", targetAddr)
	if err != nil {
		t.Fatal(err)
	}
	clientSide, err := net.ListenUDP("udp4", &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1)})
	if err != nil {
		t.Fatal(err)
	}
	serverSide, err := net.DialUDP("udp4", nil, raddr)
	if err != nil {
		_ = clientSide.Close()
		t.Fatal(err)
	}
	t.Cleanup(func() {
		_ = clientSide.Close()
		_ = serverSide.Close()
	})

	lastClient := make(chan *net.UDPAddr, 1)
	go func() {
		buf := make([]byte, 65535)
		var peer *net.UDPAddr
		for {
			n, err := serverSide.Read(buf)
			if err != nil {
				return
			}
			select {
			case p := <-lastClient:
				peer = p
			default:
			}
			if peer == nil || n > maxOuterBytes {
				continue
			}
			_, _ = clientSide.WriteToUDP(buf[:n], peer)
		}
	}()

	go func() {
		buf := make([]byte, 65535)
		for {
			n, src, err := clientSide.ReadFromUDP(buf)
			if err != nil {
				return
			}
			select {
			case lastClient <- src:
			default:
				select {
				case <-lastClient:
				default:
				}
				lastClient <- src
			}
			if n > maxOuterBytes {
				// Отбрасываем пакеты больше узкого участка без ICMP (тест PLPMTUD blackhole).
				continue
			}
			_, _ = serverSide.Write(buf[:n])
		}
	}()

	return clientSide.LocalAddr().String()
}

func TestTransitAutoMTUDiscoveryE2E(t *testing.T) {
	serverBin := buildE2EServerBinary(t)
	const transitPassword = "pmtu-transit-password"
	const exitPassword = "pmtu-exit-password"

	exitAddr := startE2EServerWithBinary(t, serverBin,
		"-password", exitPassword,
		"-protocol-version", "5",
		"-echo-data",
	)
	authTransitAddr := startE2EServerWithBinary(t, serverBin,
		"-mode", "transit",
		"-password", transitPassword,
		"-protocol-version", "5",
		"-transit-allow", exitAddr,
	)

	// Узкое звено пропускает только UDP payload <= 1252 байт (path MTU <= 1280).
	const bottleneckOuter = 1252
	bottleneckAddr := startUDPBottleneckProxy(t, authTransitAddr, bottleneckOuter)

	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	proxy, err := StartTransitProxy(ctx, ClientConfig{
		Server:          bottleneckAddr,
		MasterPassword:  transitPassword,
		ClientID:        "pmtu-transit-client",
		ClientSecret:    "sec",
		Mode:            ModeTransit,
		Target:          exitAddr,
		Proto:           ProtoUDP,
		ProtocolVersion: 5,
		// PathMTU == 0 -> автоматический подбор оптимального MTU.
	}, "127.0.0.1:0")
	if err != nil {
		t.Fatalf("StartTransitProxy: %v", err)
	}
	defer proxy.Close()

	discovered := proxy.PathMTU()
	maxAllowedPath := bottleneckOuter + IPv4UDPOverhead
	if discovered < 1180 || discovered > maxAllowedPath {
		t.Fatalf("ожидался подобранный path_mtu транзита в [1180..%d], получено %d", maxAllowedPath, discovered)
	}
	if proxy.InnerUDPMTU() <= 0 || proxy.InnerUDPMTU() >= discovered {
		t.Fatalf("некорректный inner_max транзита: path=%d inner=%d", discovered, proxy.InnerUDPMTU())
	}

	// Вложенный клиент поверх транзита с PathMTU <= nestedBudget должен работать без потери пакетов.
	nestedBudget := ComputeNestedPathMTU(discovered, ProtocolVersionV5)
	innerClient, err := NewClient(ClientConfig{
		Server:          proxy.ListenAddr(),
		MasterPassword:  exitPassword,
		ClientID:        "pmtu-inner-client",
		ClientSecret:    "sec2",
		ProtocolVersion: 5,
		AutoMTU:         true,
		PathMTU:         nestedBudget,
	})
	if err != nil {
		t.Fatal(err)
	}
	defer innerClient.Close()
	if err := innerClient.Connect(ctx); err != nil {
		t.Fatalf("inner Connect: %v", err)
	}
	innerDiscovered, err := innerClient.DiscoverPathMTU(ctx)
	if err != nil {
		t.Fatalf("inner DiscoverPathMTU: %v", err)
	}
	if innerDiscovered < 1050 || innerDiscovered > nestedBudget {
		t.Fatalf("ожидался вложенный path_mtu в [1050..%d], получено %d", nestedBudget, innerDiscovered)
	}

	// Echo IPv4 внутри подобранного MTU проходит через оба узла.
	resp := innerClient.Response()
	pkt := make([]byte, 800)
	pkt[0] = 0x45
	binary.BigEndian.PutUint16(pkt[2:4], uint16(len(pkt)))
	pkt[8] = 64
	pkt[9] = 17
	copy(pkt[12:16], net.ParseIP(resp.AssignedIP).To4())
	copy(pkt[16:20], net.ParseIP(resp.ServerIP).To4())
	if err := innerClient.WritePacket(pkt); err != nil {
		t.Fatalf("WritePacket через авто-MTU: %v", err)
	}
	readDone := make(chan error, 1)
	go func() {
		echoed, rerr := innerClient.ReadPacket()
		if rerr != nil {
			readDone <- rerr
			return
		}
		if len(echoed) != len(pkt) {
			readDone <- context.DeadlineExceeded
			return
		}
		readDone <- nil
	}()
	select {
	case rerr := <-readDone:
		if rerr != nil {
			t.Fatalf("ReadPacket через авто-MTU: %v", rerr)
		}
	case <-time.After(5 * time.Second):
		t.Fatal("таймаут echo через автоподстроенный транзит")
	}
}

func TestProbeNodeMTUAllTransports(t *testing.T) {
	serverBin := buildE2EServerBinary(t)
	const password = "probe-node-mtu-secret"
	argonAddr := startE2EServerWithBinary(t, serverBin,
		"-password", password,
		"-protocol-version", "5",
		"-echo-data",
	)
	narrowAddr := startUDPBottleneckProxy(t, argonAddr, 1300)

	ctx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
	defer cancel()

	argonRes := ProbeNodeMTU(ctx, ProbeNodeMTURequest{
		Proto:    "argon4",
		Server:   narrowAddr,
		Password: password,
		ClientID: "probe-test",
	})
	if !argonRes.OK || !argonRes.SupportsInBand {
		t.Fatalf("ожидался успешный Argon4 PLPMTUD подбор, получено: %+v", argonRes)
	}
	if argonRes.OptimalPathMTU > 1300+IPv4UDPOverhead || argonRes.OptimalPathMTU < 1200 {
		t.Fatalf("неожиданный OptimalPathMTU=%d (лимит звена 1328)", argonRes.OptimalPathMTU)
	}

	// Учёт бюджета предыдущего транзитного узла (1280 - 64 = 1216).
	chainedRes := ProbeNodeMTU(ctx, ProbeNodeMTURequest{
		Proto:        "argon4",
		Server:       argonAddr,
		Password:     password,
		PrecedingMTU: 1280,
	})
	if !chainedRes.OK || chainedRes.PrecedingBudget != 1216 || chainedRes.OptimalPathMTU > 1216 {
		t.Fatalf("бюджет предыдущего узла не применился: %+v", chainedRes)
	}

	// TCP-протокол (VLESS / OpenConnect) — реальный TCP сокет + TCP_MAXSEG.
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
			_ = c.Close()
		}
	}()

	tcpRes := ProbeNodeMTU(ctx, ProbeNodeMTURequest{
		Proto:        "vless",
		Link:         "vless://11111111-1111-1111-1111-111111111111@" + ln.Addr().String() + "?type=tcp#test",
		PrecedingMTU: 1350,
	})
	if !tcpRes.OK || tcpRes.OptimalPathMTU > 1286 || tcpRes.InnerTunMTU > 1222 {
		t.Fatalf("ожидался успешный TCP MTU подбор с учётом цепочки: %+v", tcpRes)
	}
}

func TestBuildIPv4FragNeededPacket(t *testing.T) {
	orig := make([]byte, 40)
	orig[0] = 0x45
	binary.BigEndian.PutUint16(orig[2:4], 1480)
	orig[6] = 0x40 // Don't Fragment
	orig[8] = 64
	orig[9] = 17
	copy(orig[12:16], net.IPv4(10, 44, 0, 2).To4())
	copy(orig[16:20], net.IPv4(1, 1, 1, 1).To4())

	icmpPkt, ok := BuildIPv4FragNeededPacket(orig, net.IPv4(10, 44, 0, 1), 1280)
	if !ok {
		t.Fatal("ожидалось формирование ICMP Frag Needed для DF IPv4 пакета")
	}
	if len(icmpPkt) != 20+8+28 {
		t.Fatalf("ожидалась длина ICMP пакета 56 байт, получено %d", len(icmpPkt))
	}
	if icmpPkt[20] != 3 || icmpPkt[21] != 4 {
		t.Fatalf("неверные type/code ICMP: %d/%d", icmpPkt[20], icmpPkt[21])
	}
	nextHop := int(binary.BigEndian.Uint16(icmpPkt[26:28]))
	if nextHop != 1280 {
		t.Fatalf("ожидался next-hop MTU 1280, получено %d", nextHop)
	}
}
