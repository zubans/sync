package argon4

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/binary"
	"encoding/json"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// ProbeNodeMTURequest описывает параметры узла для подбора оптимального внешнего MTU.
type ProbeNodeMTURequest struct {
	Proto                   string `json:"proto"`
	Server                  string `json:"server,omitempty"`
	Password                string `json:"password,omitempty"`
	ClientID                string `json:"client_id,omitempty"`
	ClientSecret            string `json:"client_secret,omitempty"`
	Mode                    string `json:"mode,omitempty"`
	Target                  string `json:"target,omitempty"`
	TransitProto            string `json:"transit_proto,omitempty"`
	Link                    string `json:"link,omitempty"`
	WireGuardConfig         string `json:"wireguard_config,omitempty"`
	OpenVPNConfig           string `json:"openvpn_config,omitempty"`
	ProtocolVersion         int    `json:"protocol_version,omitempty"`
	PaddingProfile          string `json:"padding_profile,omitempty"`
	FirstPacketProfile      string `json:"first_packet_profile,omitempty"`
	UseArgon                bool   `json:"use_argon,omitempty"`
	ArgonPort               int    `json:"argon_port,omitempty"`
	ArgonPassword           string `json:"argon_password,omitempty"`
	ArgonProtocolVersion    int    `json:"argon_protocol_version,omitempty"`
	ArgonPaddingProfile     string `json:"argon_padding_profile,omitempty"`
	ArgonFirstPacketProfile string `json:"argon_first_packet_profile,omitempty"`
	PrecedingMTU            int    `json:"preceding_mtu,omitempty"`
	TimeoutMs               int    `json:"timeout_ms,omitempty"`
}

// ProbeNodeMTUResult содержит измеренный оптимальный внешний MTU и диагностику канала.
type ProbeNodeMTUResult struct {
	OK              bool   `json:"ok"`
	Proto           string `json:"proto"`
	Server          string `json:"server"`
	OptimalPathMTU  int    `json:"optimal_path_mtu"`
	InnerTunMTU     int    `json:"inner_tun_mtu"`
	EffectiveOuter  int    `json:"effective_outer_udp,omitempty"`
	InterfaceMTU    int    `json:"interface_mtu,omitempty"`
	TCPOverheadMSS  int    `json:"tcp_mss,omitempty"`
	RTTMs           int64  `json:"rtt_ms"`
	Method          string `json:"method"`
	Message         string `json:"message"`
	PrecedingBudget int    `json:"preceding_budget,omitempty"`
	SupportsInBand  bool   `json:"supports_in_band,omitempty"`
	Error           string `json:"error,omitempty"`
}

// ProbeNodeMTUJSON выполняет ProbeNodeMTU из JSON-запроса (удобно для CLI и мобильных биндингов).
func ProbeNodeMTUJSON(ctx context.Context, reqJSON string) string {
	var req ProbeNodeMTURequest
	if err := json.Unmarshal([]byte(reqJSON), &req); err != nil {
		out, _ := json.Marshal(ProbeNodeMTUResult{
			OK:    false,
			Error: fmt.Sprintf("некорректный JSON запроса: %v", err),
		})
		return string(out)
	}
	res := ProbeNodeMTU(ctx, req)
	out, _ := json.Marshal(res)
	return string(out)
}

// ProbeNodeMTU подбирает оптимальный внешний MTU для узла любого поддерживаемого протокола,
// учитывая бюджет предыдущего транзитного хопа (PrecedingMTU > 0).
func ProbeNodeMTU(ctx context.Context, req ProbeNodeMTURequest) ProbeNodeMTUResult {
	proto := strings.ToLower(strings.TrimSpace(req.Proto))
	res := ProbeNodeMTUResult{Proto: proto}

	if req.PrecedingMTU > 0 {
		res.PrecedingBudget = ClampPathMTUValue(req.PrecedingMTU - 64)
	}

	timeout := time.Duration(req.TimeoutMs) * time.Millisecond
	if timeout <= 0 {
		timeout = 6 * time.Second
	}
	probeCtx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()

	// Если узел инкапсулирован в Argon4 underlay, реальный внешний транспорт — Argon4-транзит.
	if req.UseArgon && proto != "argon4" && proto != "transit" {
		addr, err := resolveNodeProbeEndpoint(req)
		if err != nil {
			res.Error = err.Error()
			return res
		}
		host, _, _ := net.SplitHostPort(addr)
		port := req.ArgonPort
		if port <= 0 {
			port = 51820
		}
		argonAddr := net.JoinHostPort(host, strconv.Itoa(port))
		argonReq := ProbeNodeMTURequest{
			Proto:              "transit",
			Server:             argonAddr,
			Password:           firstNonEmpty(req.ArgonPassword, req.Password),
			ClientID:           firstNonEmpty(req.ClientID, "cep-mtu-probe"),
			ClientSecret:       req.ClientSecret,
			Mode:               ModeAuto,
			TransitProto:       ProtoUDP,
			ProtocolVersion:    req.ArgonProtocolVersion,
			PaddingProfile:     req.ArgonPaddingProfile,
			FirstPacketProfile: req.ArgonFirstPacketProfile,
			PrecedingMTU:       req.PrecedingMTU,
			TimeoutMs:          req.TimeoutMs,
		}
		underlay := probeArgonOrUDPTransit(probeCtx, argonReq, res.PrecedingBudget)
		underlay.Proto = proto
		if underlay.OK {
			underlay.Message = fmt.Sprintf("Подобран MTU для Argon4-underlay (%s): внешн. %d, рекомендуемый %d",
				underlay.Method, underlay.OptimalPathMTU, underlay.InnerTunMTU)
		}
		return underlay
	}

	addr, transportKind, err := resolveProbeTargetAndTransport(req)
	if err != nil {
		res.Error = err.Error()
		return res
	}
	res.Server = addr

	switch {
	case proto == "argon4":
		return probeArgonEndpoint(probeCtx, req, addr, res.PrecedingBudget)

	case proto == "transit":
		tp := strings.ToLower(strings.TrimSpace(req.TransitProto))
		if strings.TrimSpace(req.Password) != "" {
			argonReq := req
			argonReq.TransitProto = ProtoUDP
			if strings.TrimSpace(argonReq.Mode) == "" {
				argonReq.Mode = ModeTransit
			}
			return probeArgonEndpoint(probeCtx, argonReq, addr, res.PrecedingBudget)
		}
		if tp == ProtoTCP {
			return probeTCPEndpoint(probeCtx, proto, addr, res.PrecedingBudget)
		}
		return probeUDPDFEndpoint(probeCtx, proto, addr, res.PrecedingBudget)

	case transportKind == "udp":
		return probeUDPDFEndpoint(probeCtx, proto, addr, res.PrecedingBudget)

	default:
		return probeTCPEndpoint(probeCtx, proto, addr, res.PrecedingBudget)
	}
}

func probeArgonOrUDPTransit(ctx context.Context, req ProbeNodeMTURequest, precedingBudget int) ProbeNodeMTUResult {
	if strings.TrimSpace(req.Password) != "" {
		argonReq := req
		argonReq.TransitProto = ProtoUDP
		if strings.TrimSpace(argonReq.Mode) == "" {
			argonReq.Mode = ModeTransit
		}
		return probeArgonEndpoint(ctx, argonReq, req.Server, precedingBudget)
	}
	return probeUDPDFEndpoint(ctx, req.Proto, req.Server, precedingBudget)
}

func probeArgonEndpoint(ctx context.Context, req ProbeNodeMTURequest, addr string, precedingBudget int) ProbeNodeMTUResult {
	res := ProbeNodeMTUResult{
		Proto:           strings.ToLower(strings.TrimSpace(req.Proto)),
		Server:          addr,
		PrecedingBudget: precedingBudget,
	}
	res.InterfaceMTU = OutgoingInterfaceMTUForAddr(addr)

	clientID := strings.TrimSpace(req.ClientID)
	if clientID == "" {
		clientID = "cep-mtu-probe"
	}
	mode := strings.TrimSpace(req.Mode)
	if mode == "" {
		if strings.EqualFold(strings.TrimSpace(req.Proto), "transit") {
			mode = ModeTransit
		} else {
			mode = ModeAuto
		}
	}
	cfg := ClientConfig{
		Server:             addr,
		MasterPassword:     req.Password,
		ClientID:           clientID,
		ClientSecret:       req.ClientSecret,
		Mode:               mode,
		Target:             req.Target,
		Proto:              ProtoUDP,
		AutoMTU:            true,
		ProtocolVersion:    req.ProtocolVersion,
		PaddingProfile:     req.PaddingProfile,
		FirstPacketProfile: req.FirstPacketProfile,
		Timing: TimingConfig{
			HandshakeInitialTimeout: 500 * time.Millisecond,
			HandshakeMaxElapsed:     4 * time.Second,
		},
	}
	client, err := NewClient(cfg)
	if err != nil {
		res.Error = err.Error()
		return res
	}
	defer func() { _ = client.Close() }()

	start := time.Now()
	if err := client.Connect(ctx); err != nil {
		res.Error = fmt.Sprintf("Argon4 рукопожатие не удалось: %v", err)
		return res
	}
	discovered, derr := client.DiscoverPathMTU(ctx)
	if derr != nil && discovered <= 0 {
		res.Error = fmt.Sprintf("Argon4 PLPMTUD не подтверждён: %v", derr)
		return res
	}
	rtt := time.Since(start).Milliseconds()
	if rtt < 1 {
		rtt = 1
	}

	optimal := ClampPathMTUValue(discovered)
	if optimal <= 0 {
		res.Error = "Argon4 PLPMTUD не получил подтверждённого значения MTU"
		return res
	}
	if res.InterfaceMTU > 0 && res.InterfaceMTU < optimal {
		optimal = res.InterfaceMTU
	}
	if precedingBudget > 0 && optimal > precedingBudget {
		optimal = precedingBudget
	}
	optimal = ClampPathMTUValue(optimal)

	client.SetPathMTU(optimal)
	inner := client.EffectiveTunMTU()
	if res.Proto == "transit" || client.IsTransit() {
		inner = ClampPathMTUValue(optimal - 64)
	}

	res.OK = true
	res.OptimalPathMTU = optimal
	res.InnerTunMTU = inner
	res.EffectiveOuter = client.EffectiveMaxOuterUDP()
	res.RTTMs = rtt
	res.Method = "argon4_plpmtud"
	res.SupportsInBand = true
	res.Message = fmt.Sprintf("Argon4 PLPMTUD: path_mtu=%d (внешн. UDP %d, внутр. %d), RTT=%d мс",
		res.OptimalPathMTU, res.EffectiveOuter, res.InnerTunMTU, res.RTTMs)
	return res
}

func probeUDPDFEndpoint(ctx context.Context, proto, addr string, precedingBudget int) ProbeNodeMTUResult {
	res := ProbeNodeMTUResult{
		Proto:           proto,
		Server:          addr,
		PrecedingBudget: precedingBudget,
	}
	dialer := &net.Dialer{Timeout: 3 * time.Second}
	conn, err := dialer.DialContext(ctx, "udp", addr)
	if err != nil {
		res.Error = fmt.Sprintf("ошибка UDP-соединения с %s: %v", addr, err)
		return res
	}
	defer conn.Close()
	defer func() { _ = conn.SetReadDeadline(time.Time{}) }()

	ifaceMTU := InterfaceMTUByLocalAddr(conn.LocalAddr())
	if ifaceMTU <= 0 {
		ifaceMTU = OutgoingInterfaceMTUForAddr(addr)
	}
	res.InterfaceMTU = ifaceMTU

	ipHdr := IPv4UDPOverhead
	if uaddr, ok := conn.RemoteAddr().(*net.UDPAddr); ok && uaddr.IP != nil && uaddr.IP.To4() == nil {
		ipHdr = 48
	}

	dfApplied := setUDPSocketDFConn(conn)
	upperPath := DefaultPathMTU
	if ifaceMTU >= MinPathMTU && ifaceMTU < upperPath {
		upperPath = ifaceMTU
	}
	if precedingBudget > 0 && precedingBudget < upperPath {
		upperPath = precedingBudget
	}
	if upperPath < MinPathMTU {
		upperPath = MinPathMTU
	}

	isQuic := proto == "hysteria2" || proto == "hysteria"
	noiseBuf := make([]byte, upperPath)
	_, _ = rand.Read(noiseBuf)
	replyBuf := make([]byte, 16384)
	var seq uint16
	var kernelCapPath int

	drainConn := func() {
		_ = conn.SetReadDeadline(time.Now().Add(time.Millisecond))
		for i := 0; i < 32; i++ {
			if _, rerr := conn.Read(replyBuf); rerr != nil {
				break
			}
		}
		_ = conn.SetReadDeadline(time.Time{})
	}

	type probeStepOutcome int
	const (
		stepOK probeStepOutcome = iota
		stepTooBig
		stepTimeout
		stepRefused
	)

	probePath := func(targetPath int, wait time.Duration) (outcome probeStepOutcome, capPath int, rtt time.Duration, errMsg string) {
		drainConn()
		seq++
		curSeq := seq
		outerLen := targetPath - ipHdr
		if outerLen < 8 {
			outerLen = 8
		}
		if outerLen > len(noiseBuf) {
			outerLen = len(noiseBuf)
		}
		pkt := buildUDPDFProbePacket(proto, outerLen, targetPath, curSeq, noiseBuf[:outerLen])

		sendStart := time.Now()
		if _, werr := conn.Write(pkt); werr != nil {
			if isMsgSizeError(werr) {
				if kMTU, kok := queryKernelConnMTU(conn); kok && kMTU >= MinPathMTU {
					if kernelCapPath == 0 || kMTU < kernelCapPath {
						kernelCapPath = kMTU
					}
					return stepTooBig, kMTU, 0, ""
				}
				return stepTooBig, targetPath - 1, 0, ""
			}
			msg := strings.ToLower(werr.Error())
			if strings.Contains(msg, "connection refused") {
				return stepRefused, 0, 0, fmt.Sprintf("UDP-порт %s отклонил пакет (connection refused)", addr)
			}
			return stepTimeout, 0, 0, ""
		}

		deadline := sendStart.Add(wait)
		if ctxDeadline, has := ctx.Deadline(); has && ctxDeadline.Before(deadline) {
			deadline = ctxDeadline
		}
		for {
			if ctx.Err() != nil {
				return stepTimeout, 0, 0, ""
			}
			if !time.Now().Before(deadline) {
				return stepTimeout, 0, 0, ""
			}
			_ = conn.SetReadDeadline(deadline)
			n, rerr := conn.Read(replyBuf)
			if rerr != nil {
				if isMsgSizeError(rerr) {
					if kMTU, kok := queryKernelConnMTU(conn); kok && kMTU >= MinPathMTU {
						if kernelCapPath == 0 || kMTU < kernelCapPath {
							kernelCapPath = kMTU
						}
						return stepTooBig, kMTU, 0, ""
					}
					return stepTooBig, targetPath - 1, 0, ""
				}
				msg := strings.ToLower(rerr.Error())
				if strings.Contains(msg, "connection refused") {
					return stepRefused, 0, 0, fmt.Sprintf("UDP-порт %s недоступен (ICMP Port Unreachable)", addr)
				}
				return stepTimeout, 0, 0, ""
			}
			if n <= 0 || !matchesUDPDFProbeReply(replyBuf[:n], targetPath, curSeq, proto) {
				continue
			}
			return stepOK, 0, time.Since(sendStart), ""
		}
	}

	bestPath := 0
	var measuredRTT time.Duration

	// 1. Проверяем верхний размер и требуем реального подтверждённого ответа от узла.
	outUpper, capUpper, rttUpper, refMsg := probePath(upperPath, 220*time.Millisecond)
	if outUpper == stepRefused {
		res.Error = refMsg
		return res
	}
	if outUpper == stepOK {
		bestPath = upperPath
		measuredRTT = rttUpper
	} else {
		// 2. Обязательная проверка базового минимального MTU. Без ответа считаем путь неподтверждённым.
		floorCandidates := []int{MinPathMTU}
		if isQuic {
			quicFloor := 1200 + ipHdr
			if quicFloor > upperPath {
				quicFloor = upperPath
			}
			if quicFloor > MinPathMTU {
				floorCandidates = []int{quicFloor, MinPathMTU}
			}
		}
		confirmedFloor := 0
		for _, fc := range floorCandidates {
			for attempt := 0; attempt < 2; attempt++ {
				if ctx.Err() != nil {
					break
				}
				outLo, _, rttLo, refLo := probePath(fc, 250*time.Millisecond)
				if outLo == stepRefused {
					res.Error = refLo
					return res
				}
				if outLo == stepOK {
					confirmedFloor = fc
					measuredRTT = rttLo
					break
				}
				if outLo == stepTooBig {
					break
				}
			}
			if confirmedFloor > 0 {
				break
			}
		}
		if confirmedFloor == 0 {
			res.Error = fmt.Sprintf("UDP-узел %s не подтвердил доставку ни при %d Б, ни при минимальном MTU %d Б (нет ответа)", addr, upperPath, MinPathMTU)
			return res
		}

		bestPath = confirmedFloor
		lo := confirmedFloor
		hi := upperPath - 1
		if capUpper > 0 && capUpper < hi {
			hi = capUpper
		}
		if kernelCapPath > 0 && kernelCapPath >= lo && kernelCapPath < hi {
			hi = kernelCapPath
		}

		stepWait := measuredRTT*4 + 120*time.Millisecond
		if stepWait < 140*time.Millisecond {
			stepWait = 140 * time.Millisecond
		}
		if stepWait > 350*time.Millisecond {
			stepWait = 350 * time.Millisecond
		}

		for lo < hi {
			if ctx.Err() != nil {
				break
			}
			var mid int
			if hi-lo > 16 {
				mid = (((lo + hi + 1) / 2) / 4) * 4
				if mid <= lo {
					mid = lo + 1
				}
			} else {
				mid = (lo + hi + 1) / 2
			}
			if mid <= lo || mid > hi {
				break
			}

			outMid, capMid, rttMid, refMid := probePath(mid, stepWait)
			if outMid == stepTimeout && hi-lo <= 32 {
				outMid, capMid, rttMid, refMid = probePath(mid, stepWait)
			}
			if outMid == stepRefused {
				res.Error = refMid
				return res
			}
			if outMid == stepOK {
				bestPath = mid
				lo = mid
				if rttMid > 0 {
					measuredRTT = rttMid
				}
			} else {
				hi = mid - 1
				if capMid > 0 && capMid < hi {
					hi = capMid
				}
				if kernelCapPath > 0 && kernelCapPath >= lo && kernelCapPath < hi {
					hi = kernelCapPath
				}
			}
		}
	}

	optimal := ClampPathMTUValue(bestPath)
	method := "udp_df_probe"
	if !dfApplied {
		method = "udp_probe"
	}

	rtt := measuredRTT.Milliseconds()
	if ifaceMTU >= MinPathMTU && ifaceMTU < optimal {
		optimal = ifaceMTU
	}
	if precedingBudget > 0 && optimal > precedingBudget {
		optimal = precedingBudget
	}
	optimal = ClampPathMTUValue(optimal)
	if rtt < 1 {
		rtt = 1
	}

	inner := ClampPathMTUValue(optimal - 64)
	if proto == "amneziawg" {
		inner = ClampPathMTUValue(optimal - 80)
	}

	res.OK = true
	res.OptimalPathMTU = optimal
	res.InnerTunMTU = inner
	res.EffectiveOuter = optimal - ipHdr
	res.RTTMs = rtt
	res.Method = method
	res.Message = fmt.Sprintf("Подобран внешний MTU=%d (внутр. %d, интерфейс %d, метод %s, RTT=%d мс)",
		res.OptimalPathMTU, res.InnerTunMTU, res.InterfaceMTU, res.Method, res.RTTMs)
	return res
}

func probeTCPEndpoint(ctx context.Context, proto, addr string, precedingBudget int) ProbeNodeMTUResult {
	res := ProbeNodeMTUResult{
		Proto:           proto,
		Server:          addr,
		PrecedingBudget: precedingBudget,
	}
	dialer := &net.Dialer{Timeout: 3 * time.Second}
	start := time.Now()
	conn, err := dialer.DialContext(ctx, "tcp", addr)
	if err != nil {
		res.Error = fmt.Sprintf("ошибка TCP-подключения к %s: %v", addr, err)
		return res
	}
	defer conn.Close()
	rtt := time.Since(start).Milliseconds()
	if rtt < 1 {
		rtt = 1
	}

	ifaceMTU := InterfaceMTUByLocalAddr(conn.LocalAddr())
	if ifaceMTU <= 0 {
		ifaceMTU = OutgoingInterfaceMTUForAddr(addr)
	}
	res.InterfaceMTU = ifaceMTU

	tcpHeaderOverhead := 40
	if taddr, ok := conn.RemoteAddr().(*net.TCPAddr); ok && taddr.IP != nil && taddr.IP.To4() == nil {
		tcpHeaderOverhead = 60
	}

	optimal := DefaultPathMTU
	method := "tcp_interface_mtu"
	if mss, ok := queryTCPConnMSS(conn); ok && mss > 0 {
		res.TCPOverheadMSS = mss
		mssPath := ClampPathMTUValue(mss + tcpHeaderOverhead)
		if mssPath > 0 && mssPath < optimal {
			optimal = mssPath
		}
		method = "tcp_mss"
	}
	if ifaceMTU >= MinPathMTU && ifaceMTU < optimal {
		optimal = ifaceMTU
	}
	if precedingBudget > 0 && optimal > precedingBudget {
		optimal = precedingBudget
	}
	optimal = ClampPathMTUValue(optimal)

	res.OK = true
	res.OptimalPathMTU = optimal
	res.InnerTunMTU = ClampPathMTUValue(optimal - 64)
	res.EffectiveOuter = optimal - tcpHeaderOverhead
	res.RTTMs = rtt
	res.Method = method
	res.Message = fmt.Sprintf("TCP MSS=%d → оптимальный внешний MTU=%d (внутр. %d, интерфейс %d, RTT=%d мс)",
		res.TCPOverheadMSS, res.OptimalPathMTU, res.InnerTunMTU, res.InterfaceMTU, res.RTTMs)
	return res
}

func resolveNodeProbeEndpoint(req ProbeNodeMTURequest) (string, error) {
	addr, _, err := resolveProbeTargetAndTransport(req)
	return addr, err
}

func resolveProbeTargetAndTransport(req ProbeNodeMTURequest) (addr string, transport string, err error) {
	proto := strings.ToLower(strings.TrimSpace(req.Proto))
	transport = "tcp"
	switch proto {
	case "argon4":
		addr = normalizeHostPort(req.Server, "51820")
		transport = "udp"
	case "transit":
		raw := firstNonEmpty(req.Server, req.Target)
		addr = normalizeHostPort(raw, "51820")
		if strings.TrimSpace(req.Password) == "" && strings.EqualFold(strings.TrimSpace(req.TransitProto), ProtoTCP) {
			transport = "tcp"
		} else {
			transport = "udp"
		}
	case "hysteria2", "hysteria":
		addr, _, err = parseLinkEndpoint(req.Link, req.Server, "443")
		transport = "udp"
	case "vless":
		var query url.Values
		addr, query, err = parseLinkEndpoint(req.Link, req.Server, "443")
		netType := strings.ToLower(query.Get("type"))
		if netType == "" {
			netType = strings.ToLower(query.Get("net"))
		}
		if netType == "kcp" || netType == "quic" {
			transport = "udp"
		} else {
			transport = "tcp"
		}
	case "amneziawg":
		addr, err = parseWireGuardEndpoint(req.WireGuardConfig, req.Server)
		transport = "udp"
	case "openconnect", "sstp":
		addr = normalizeHostPort(req.Server, "443")
		transport = "tcp"
	case "openvpn":
		addr, transport, err = parseOpenVPNEndpoint(req.OpenVPNConfig, req.Server)
	default:
		if req.Link != "" {
			addr, _, err = parseLinkEndpoint(req.Link, req.Server, "443")
		} else {
			addr = normalizeHostPort(req.Server, "443")
		}
	}
	if err != nil {
		return "", "", err
	}
	if strings.TrimSpace(addr) == "" {
		return "", "", fmt.Errorf("не указан адрес сервера для узла %s", proto)
	}
	return addr, transport, nil
}

func normalizeHostPort(raw, defaultPort string) string {
	s := strings.TrimSpace(raw)
	if s == "" {
		return ""
	}
	if strings.Contains(s, "://") {
		if u, err := url.Parse(s); err == nil && u.Host != "" {
			s = u.Host
		}
	}
	if idx := strings.IndexByte(s, '/'); idx >= 0 {
		s = s[:idx]
	}
	if _, _, err := net.SplitHostPort(s); err == nil {
		return s
	}
	return net.JoinHostPort(strings.Trim(s, "[]"), defaultPort)
}

func parseLinkEndpoint(link, fallbackServer, defaultPort string) (string, url.Values, error) {
	trimmed := strings.TrimSpace(link)
	if trimmed == "" {
		if fallbackServer == "" {
			return "", nil, fmt.Errorf("не указана ссылка подключения или сервер")
		}
		return normalizeHostPort(fallbackServer, defaultPort), nil, nil
	}
	u, err := url.Parse(trimmed)
	if err != nil || u.Hostname() == "" {
		return "", nil, fmt.Errorf("некорректная ссылка подключения: %v", err)
	}
	port := u.Port()
	if port == "" {
		port = defaultPort
	}
	return net.JoinHostPort(u.Hostname(), port), u.Query(), nil
}

func parseWireGuardEndpoint(cfgText, fallbackServer string) (string, error) {
	for _, line := range strings.Split(cfgText, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		lower := strings.ToLower(line)
		if strings.HasPrefix(lower, "endpoint") {
			if idx := strings.IndexByte(line, '='); idx >= 0 {
				ep := strings.TrimSpace(line[idx+1:])
				if ep != "" {
					return normalizeHostPort(ep, "51820"), nil
				}
			}
		}
	}
	if fallbackServer != "" {
		return normalizeHostPort(fallbackServer, "51820"), nil
	}
	return "", fmt.Errorf("в конфигурации AmneziaWG не найден Endpoint")
}

func parseOpenVPNEndpoint(cfgText, fallbackServer string) (addr string, transport string, err error) {
	transport = "udp"
	for _, line := range strings.Split(cfgText, "\n") {
		fields := strings.Fields(strings.TrimSpace(line))
		if len(fields) == 0 || strings.HasPrefix(fields[0], "#") || strings.HasPrefix(fields[0], ";") {
			continue
		}
		key := strings.ToLower(fields[0])
		if key == "proto" && len(fields) >= 2 && strings.Contains(strings.ToLower(fields[1]), "tcp") {
			transport = "tcp"
		}
		if key == "remote" && len(fields) >= 2 {
			port := "1194"
			if len(fields) >= 3 {
				port = fields[2]
			}
			if len(fields) >= 4 && strings.Contains(strings.ToLower(fields[3]), "tcp") {
				transport = "tcp"
			}
			addr = net.JoinHostPort(fields[1], port)
		}
	}
	if addr == "" && fallbackServer != "" {
		addr = normalizeHostPort(fallbackServer, "1194")
	}
	if addr == "" {
		return "", "", fmt.Errorf("в конфигурации OpenVPN не найден remote")
	}
	return addr, transport, nil
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if strings.TrimSpace(v) != "" {
			return v
		}
	}
	return ""
}

func buildUDPDFProbePacket(proto string, outerLen int, pathMTU int, seq uint16, pattern []byte) []byte {
	pkt := make([]byte, outerLen)
	copy(pkt, pattern)
	if (proto == "hysteria2" || proto == "hysteria") && outerLen >= 1200 {
		pkt[0] = 0xC0
		pkt[1], pkt[2], pkt[3], pkt[4] = 0x0A, 0x1A, 0x2A, 0x3A
		pkt[5] = 8
		copy(pkt[6:10], "PMTU")
		binary.BigEndian.PutUint16(pkt[10:12], uint16(pathMTU))
		binary.BigEndian.PutUint16(pkt[12:14], seq)
		pkt[14] = 8
		copy(pkt[15:19], "CEPM")
		binary.BigEndian.PutUint16(pkt[19:21], uint16(pathMTU))
		binary.BigEndian.PutUint16(pkt[21:23], seq)
		return pkt
	}
	if outerLen >= 8 {
		copy(pkt[0:4], "PMTU")
		binary.BigEndian.PutUint16(pkt[4:6], uint16(pathMTU))
		binary.BigEndian.PutUint16(pkt[6:8], seq)
	}
	return pkt
}

func matchesUDPDFProbeReply(reply []byte, pathMTU int, seq uint16, proto string) bool {
	if len(reply) >= 8 && string(reply[0:4]) == "PMTU" {
		return binary.BigEndian.Uint16(reply[6:8]) == seq
	}
	if (proto == "hysteria2" || proto == "hysteria") && len(reply) >= 7 {
		var expectedPmtu [8]byte
		var expectedCepm [8]byte
		copy(expectedPmtu[0:4], "PMTU")
		binary.BigEndian.PutUint16(expectedPmtu[4:6], uint16(pathMTU))
		binary.BigEndian.PutUint16(expectedPmtu[6:8], seq)
		copy(expectedCepm[0:4], "CEPM")
		binary.BigEndian.PutUint16(expectedCepm[4:6], uint16(pathMTU))
		binary.BigEndian.PutUint16(expectedCepm[6:8], seq)
		if bytes.Contains(reply, expectedPmtu[:]) || bytes.Contains(reply, expectedCepm[:]) {
			return true
		}
		for _, marker := range [][]byte{[]byte("PMTU"), []byte("CEPM")} {
			if idx := bytes.Index(reply, marker); idx >= 0 && idx+8 <= len(reply) {
				return binary.BigEndian.Uint16(reply[idx+6:idx+8]) == seq
			}
		}
	}
	return true
}
