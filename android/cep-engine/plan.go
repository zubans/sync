package cepcore

import (
	"context"
	"encoding/json"
	"fmt"
	"net"
	"net/netip"
	"net/url"
	"strconv"
	"strings"
	"time"

	"contactsync/cep-engine/argon4"
)

// Profile и Plan — те же поля, что в profiles.json приложения для macOS.
type Profile struct {
	ID                             string `json:"id"`
	Name                           string `json:"name"`
	Proto                          string `json:"proto"`
	URI                            string `json:"uri"`
	Config                         string `json:"config"`
	Server                         string `json:"server"`
	Username                       string `json:"username"`
	Password                       string `json:"password"`
	ClientID                       string `json:"clientID"`
	ClientSecret                   string `json:"clientSecret"`
	OpenConnectFlavor              string `json:"openconnectFlavor"`
	ServerCert                     string `json:"serverCert"`
	AuthGroup                      string `json:"authGroup"`
	IgnoreCertErrors               bool   `json:"ignoreCertErrors"`
	Routes                         string `json:"routes"`
	UseArgon                       bool   `json:"useArgon"`
	ArgonPassword                  string `json:"argonPassword"`
	ArgonProtocolVersion           int    `json:"argonProtocolVersion,omitempty"`
	ArgonPaddingProfile            string `json:"argonPaddingProfile,omitempty"`
	ArgonFirstPacketProfile        string `json:"argonFirstPacketProfile,omitempty"`
	ArgonPathMTU                   int    `json:"argonPathMTU,omitempty"`
	ArgonHandshakeMaxAttempts      int    `json:"argonHandshakeMaxAttempts,omitempty"`
	ArgonHandshakeInitialTimeoutMs int    `json:"argonHandshakeInitialTimeoutMs,omitempty"`
	ArgonHandshakeMaxTimeoutMs     int    `json:"argonHandshakeMaxTimeoutMs,omitempty"`
	ArgonKeepaliveMinIdleSec       int    `json:"argonKeepaliveMinIdleSec,omitempty"`
	ArgonKeepaliveMaxIdleSec       int    `json:"argonKeepaliveMaxIdleSec,omitempty"`
	ArgonBehaviorProfile           string `json:"argonBehaviorProfile,omitempty"`
	ArgonLowPowerMode              bool   `json:"argonLowPowerMode,omitempty"`
	ArgonVideoCallMaxDurationSec   int    `json:"argonVideoCallMaxDurationSec,omitempty"`
}

type Plan struct {
	ChainName string    `json:"chainName"`
	Hops      []Profile `json:"hops"`
	DNS       []string  `json:"dns"`
	BlockIPv6 bool      `json:"blockIPv6"`
}

func parsePlan(planJSON string) (*Plan, error) {
	var plan Plan
	if err := json.Unmarshal([]byte(planJSON), &plan); err != nil {
		return nil, fmt.Errorf("не удалось прочитать задание: %w", err)
	}
	if len(plan.Hops) == 0 {
		return nil, fmt.Errorf("в цепочке нет ни одного узла")
	}
	return &plan, nil
}

// Supported — поддерживается ли протокол на Android (пусто) или почему нет.
func Supported(proto string) string {
	switch proto {
	case "vless", "hysteria2", "hysteria", "openconnect", "openvpn", "argon4", "transit":
		return ""
	case "amneziawg":
		return "AmneziaWG на Android пока не поддерживается"
	case "sstp":
		return "SSTP на Android пока не поддерживается"
	}
	return "неизвестный протокол " + proto
}

// parseRoutes — сети из поля профиля: через запятую, пробел или с новой строки; адрес без маски — /32.
func parseRoutes(text string) ([]string, error) {
	var result []string
	for _, s := range strings.FieldsFunc(text, func(r rune) bool { return strings.ContainsRune(",; \n\t", r) }) {
		addr, bits, hasMask := strings.Cut(s, "/")
		ip, err := netip.ParseAddr(addr)
		if err != nil || !ip.Is4() {
			return nil, fmt.Errorf("«%s» — не IPv4-сеть; пример: 192.168.1.119 или 10.0.0.0/8", s)
		}
		n := 32
		if hasMask {
			n, err = strconv.Atoi(bits)
			if err != nil || n < 1 || n > 32 {
				return nil, fmt.Errorf("«%s»: маска должна быть от /1 до /32", s)
			}
		}
		cidr := netip.PrefixFrom(ip, n).Masked().String()
		if !contains(result, cidr) {
			result = append(result, cidr)
		}
	}
	return result, nil
}

func contains(list []string, s string) bool {
	for _, v := range list {
		if v == s {
			return true
		}
	}
	return false
}

// endpointHost — адрес сервера узла: его движок заранее разрешает в IP.
func endpointHost(p *Profile) (string, error) {
	switch p.Proto {
	case "vless", "hysteria2", "hysteria":
		l, err := parseLink(p.URI)
		if err != nil {
			return "", err
		}
		return l.Host, nil
	case "openconnect":
		u, err := openConnectURL(p.Server)
		if err != nil {
			return "", err
		}
		return u.Hostname(), nil
	case "openvpn":
		ovpn, err := parseOVPN(p.Config)
		if err != nil {
			return "", err
		}
		return ovpn.remoteHost, nil
	case "argon4", "transit":
		host, _, err := parseArgon4Server(p.Server)
		return host, err
	}
	return "", fmt.Errorf("%s", Supported(p.Proto))
}

// openConnectURL — host, host:port или https://host:port/path.
func openConnectURL(raw string) (*url.URL, error) {
	s := strings.TrimSpace(raw)
	if s == "" {
		return nil, fmt.Errorf("не указан сервер")
	}
	if !strings.Contains(s, "://") {
		s = "https://" + s
	}
	u, err := url.Parse(s)
	if err != nil || u.Hostname() == "" {
		return nil, fmt.Errorf("неверный адрес сервера «%s»", raw)
	}
	return u, nil
}

// parseArgon4Server принимает host, host:port, udp://host:port или argon4://host:port.
func parseArgon4Server(raw string) (host, port string, err error) {
	s := strings.TrimSpace(raw)
	if s == "" {
		return "", "", fmt.Errorf("Argon4: не указан сервер")
	}
	if !strings.Contains(s, "://") {
		if address, addrErr := netip.ParseAddr(s); addrErr == nil {
			if address.Is6() {
				s = "[" + s + "]"
			}
			s = "udp://" + s
		} else if _, _, splitErr := net.SplitHostPort(s); splitErr == nil || !strings.Contains(s, ":") {
			s = "udp://" + s
		} else {
			return "", "", fmt.Errorf("Argon4: неверный адрес сервера «%s»", raw)
		}
	}
	u, err := url.Parse(s)
	if err != nil || u.Hostname() == "" || u.User != nil || (u.Scheme != "udp" && u.Scheme != "argon4") {
		return "", "", fmt.Errorf("Argon4: неверный адрес сервера «%s»", raw)
	}
	port = u.Port()
	if port == "" {
		port = "51820"
	}
	portNumber, err := strconv.Atoi(port)
	if err != nil || portNumber < 1 || portNumber > 65535 {
		return "", "", fmt.Errorf("Argon4: порт должен быть от 1 до 65535")
	}
	return u.Hostname(), port, nil
}

// Validate — ошибки, из-за которых цепочку не запустить (JSON-массив строк).
func Validate(planJSON string) string {
	plan, err := parsePlan(planJSON)
	if err != nil {
		return mustJSON([]string{err.Error()})
	}
	problems := []string{}
	for i, hop := range plan.Hops {
		if reason := Supported(hop.Proto); reason != "" {
			problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, reason))
			continue
		}
		if hop.Proto == "transit" {
			if _, _, err := parseArgon4Server(hop.Server); err != nil {
				problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, err))
			}
			if i == len(plan.Hops)-1 {
				problems = append(problems, fmt.Sprintf("«%s»: транзитный шлюз не может быть последним узлом цепочки", hop.Name))
			} else if strings.TrimSpace(hop.Password) == "" {
				next := plan.Hops[i+1]
				if next.Proto != "argon4" && !next.UseArgon && next.Proto != "transit" {
					problems = append(problems, fmt.Sprintf("«%s»: слепой транзит без пароля поддерживает только следующий узел Argon4", hop.Name))
				}
			}
		} else if _, err := hopOptions(&hop, hop.Name, ""); err != nil {
			problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, err))
			continue
		}
		if hop.UseArgon {
			if hop.Proto == "argon4" || hop.Proto == "transit" {
				problems = append(problems, fmt.Sprintf("«%s»: Argon4-надстройка не используется с %s", hop.Name, hop.Proto))
			} else if strings.TrimSpace(hop.ArgonPassword) == "" {
				problems = append(problems, fmt.Sprintf("«%s»: не задан пароль Argon4-надстройки", hop.Name))
			}
		}
		if hop.ArgonBehaviorProfile != "" {
			if hop.ArgonBehaviorProfile != "baseline" && hop.ArgonBehaviorProfile != "video-call" {
				problems = append(problems, fmt.Sprintf("«%s»: неизвестный поведенческий профиль Argon4 %q", hop.Name, hop.ArgonBehaviorProfile))
			} else if hop.ArgonProtocolVersion == 4 {
				problems = append(problems, fmt.Sprintf("«%s»: поведенческие профили Argon4 требуют версию v5 и недоступны в v4", hop.Name))
			} else if hop.Proto == "transit" && strings.TrimSpace(hop.Password) == "" {
				problems = append(problems, fmt.Sprintf("«%s»: слепой транзит без пароля не поддерживает поведенческие профили Argon4", hop.Name))
			} else if hop.ArgonBehaviorProfile == "video-call" {
				if hop.Proto == "transit" && i+1 < len(plan.Hops) {
					nextHop := &plan.Hops[i+1]
					if !nextHop.UseArgon {
						if _, nextProto, perr := hopTargetPortAndProto(nextHop); perr == nil && nextProto != "udp" {
							problems = append(problems, fmt.Sprintf("«%s»: профиль video-call в транзитном шлюзе поддерживается только для UDP-целей", hop.Name))
						}
					}
				} else if hop.UseArgon {
					if _, hopProto, perr := hopTargetPortAndProto(&hop); perr == nil && hopProto != "udp" {
						problems = append(problems, fmt.Sprintf("«%s»: профиль video-call в Argon4-надстройке поддерживается только для UDP-узлов", hop.Name))
					}
				}
			}
		}
		if _, err := parseRoutes(hop.Routes); err != nil {
			problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, err))
		}
	}
	return mustJSON(problems)
}

// resolveAll заранее разрешает адреса серверов, пока VPN не поднят: потом системный DNS уйдёт в цепочку.
func resolveAll(plan *Plan, log func(string)) ([]string, error) {
	ips := make([]string, len(plan.Hops))
	for i := range plan.Hops {
		host, err := endpointHost(&plan.Hops[i])
		if err != nil {
			return nil, fmt.Errorf("«%s»: %w", plan.Hops[i].Name, err)
		}
		if ip, err := netip.ParseAddr(host); err == nil {
			ips[i] = ip.String()
			continue
		}
		addrs, err := net.LookupIP(host)
		if err != nil {
			return nil, fmt.Errorf("«%s»: не удалось разрешить %s: %w", plan.Hops[i].Name, host, err)
		}
		for _, a := range addrs {
			if a.To4() != nil {
				ips[i] = a.String()
				break
			}
		}
		if ips[i] == "" {
			return nil, fmt.Errorf("«%s»: у %s нет IPv4-адреса", plan.Hops[i].Name, host)
		}
		log(fmt.Sprintf("%s: %s → %s", plan.Hops[i].Name, host, ips[i]))
	}
	return ips, nil
}

// TunAddress — адрес TUN приложения; сеть не пересекается с адресами sing-box на macOS (172.19.N.1/30).
const tunAddress = "172.19.0.1/30"

const tunMTU = 1400

// buildConfig собирает конфиг sing-box: узел N выходит в сеть через узел N-1 (detour),
// весь трафик TUN уходит в последний узел, «сети через этот узел» — в свой узел.
func hopClientID(hop *Profile) string {
	if clientID := strings.TrimSpace(hop.ClientID); clientID != "" {
		return clientID
	}
	return hop.ID
}

func clampNestedPathMTU(configured, budget int) int {
	if budget <= 0 {
		return configured
	}
	if configured <= 0 || configured > budget {
		return budget
	}
	return configured
}

func nextNestedUDPBudget(configured, currentBudget int) int {
	outer := configured
	if outer <= 0 {
		outer = argon4.DefaultPathMTU
	}
	if currentBudget > 0 && currentBudget < outer {
		outer = currentBudget
	}
	next := outer - 64
	if next < argon4.MinPathMTU {
		return argon4.MinPathMTU
	}
	return next
}

func argon4BaseOptions(hop *Profile, serverAddr, password, clientID string, nestedPathMTUBudget int) map[string]any {
	opts := map[string]any{
		"type":          argon4Type,
		"server":        serverAddr,
		"password":      password,
		"client_id":     clientID,
		"client_secret": hop.ClientSecret,
	}
	if hop.ArgonProtocolVersion != 0 {
		opts["protocol_version"] = hop.ArgonProtocolVersion
	}
	if hop.ArgonPaddingProfile != "" {
		opts["padding_profile"] = hop.ArgonPaddingProfile
	}
	if hop.ArgonFirstPacketProfile != "" {
		opts["first_packet_profile"] = hop.ArgonFirstPacketProfile
	}
	if pathMTU := clampNestedPathMTU(hop.ArgonPathMTU, nestedPathMTUBudget); pathMTU != 0 {
		opts["path_mtu"] = pathMTU
	}
	if hop.ArgonHandshakeMaxAttempts != 0 {
		opts["handshake_max_attempts"] = hop.ArgonHandshakeMaxAttempts
	}
	if hop.ArgonHandshakeInitialTimeoutMs != 0 {
		opts["handshake_initial_timeout_ms"] = hop.ArgonHandshakeInitialTimeoutMs
	}
	if hop.ArgonHandshakeMaxTimeoutMs != 0 {
		opts["handshake_max_timeout_ms"] = hop.ArgonHandshakeMaxTimeoutMs
	}
	if hop.ArgonKeepaliveMinIdleSec != 0 {
		opts["keepalive_min_idle_sec"] = hop.ArgonKeepaliveMinIdleSec
	}
	if hop.ArgonKeepaliveMaxIdleSec != 0 {
		opts["keepalive_max_idle_sec"] = hop.ArgonKeepaliveMaxIdleSec
	}
	if hop.ArgonBehaviorProfile != "" {
		opts["behavior_profile"] = hop.ArgonBehaviorProfile
	}
	if hop.ArgonLowPowerMode {
		opts["low_power_mode"] = true
	}
	if hop.ArgonVideoCallMaxDurationSec != 0 {
		opts["video_call_max_duration_sec"] = hop.ArgonVideoCallMaxDurationSec
	}
	return opts
}

func hopTargetPortAndProto(hop *Profile) (port, proto string, err error) {
	switch hop.Proto {
	case "vless":
		l, err := parseLink(hop.URI)
		if err != nil {
			return "", "", err
		}
		return strconv.Itoa(l.Port), "tcp", nil
	case "hysteria2", "hysteria":
		l, err := parseLink(hop.URI)
		if err != nil {
			return "", "", err
		}
		return strconv.Itoa(l.Port), "udp", nil
	case "openconnect":
		u, err := openConnectURL(hop.Server)
		if err != nil {
			return "", "", err
		}
		return portOr(u.Port(), "443"), "tcp", nil
	case "openvpn":
		ovpn, err := parseOVPN(hop.Config)
		if err != nil {
			return "", "", err
		}
		return strconv.Itoa(ovpn.remotePort), ovpn.network, nil
	case "argon4", "transit":
		_, port, err := parseArgon4Server(hop.Server)
		if err != nil {
			return "", "", err
		}
		return port, "udp", nil
	}
	return "", "", fmt.Errorf("%s", Supported(hop.Proto))
}

func buildConfig(plan *Plan, serverIPs []string) (map[string]any, error) {
	inbounds := []any{map[string]any{
		"type":       "tun",
		"tag":        "tun-in",
		"address":    []string{tunAddress},
		"mtu":        tunMTU,
		"auto_route": true,
		"stack":      "gvisor",
	}}
	var outbounds, endpoints, rules []any
	previousTag := ""
	previousIsProxy := false
	previousUsesTransit := false
	blindRelayAddr := ""
	nestedUDPBudget := 0

	appendHop := func(opts map[string]any, tag string, isEndpoint bool) {
		if previousTag != "" {
			if isEndpoint && previousIsProxy {
				// до прокси-узла — через обёртку с таймаутами (см. deadline.go);
				// UDP (DTLS) поверх прокси не используем: тоже требует таймаутов, туннель идёт по TLS
				wrap := previousTag + "-deadline"
				outbounds = append(outbounds, map[string]any{"type": deadlineType, "tag": wrap, "detour": previousTag})
				opts["detour"] = wrap
			} else {
				opts["detour"] = previousTag
			}
			if opts["type"] == "openconnect" && (previousIsProxy || previousUsesTransit) {
				opts["no_udp"] = true
			}
		}
		if isEndpoint {
			endpoints = append(endpoints, opts)
		} else {
			outbounds = append(outbounds, opts)
		}
		previousTag = tag
		previousIsProxy = !isEndpoint
	}

	for i := range plan.Hops {
		hop := &plan.Hops[i]
		tag := fmt.Sprintf("hop%d", i+1)
		if hop.Proto == "transit" {
			_, transitPort, err := parseArgon4Server(hop.Server)
			if err != nil {
				return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
			}
			serverAddr := net.JoinHostPort(serverIPs[i], transitPort)
			if blindRelayAddr != "" {
				serverAddr = blindRelayAddr
				blindRelayAddr = ""
			}
			if strings.TrimSpace(hop.Password) == "" {
				blindRelayAddr = serverAddr
				if hop.ArgonPathMTU > 0 {
					nestedUDPBudget = nextNestedUDPBudget(hop.ArgonPathMTU, nestedUDPBudget)
				}
				continue
			}
			if i+1 >= len(plan.Hops) {
				return nil, fmt.Errorf("узел %d «%s»: транзитный шлюз не может быть последним", i+1, hop.Name)
			}
			nextHop := &plan.Hops[i+1]
			nextPort, nextProto := "51820", "udp"
			if !nextHop.UseArgon {
				var targetErr error
				nextPort, nextProto, targetErr = hopTargetPortAndProto(nextHop)
				if targetErr != nil {
					return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, targetErr)
				}
			}
			transitOpts := argon4BaseOptions(hop, serverAddr, hop.Password, hopClientID(hop), nestedUDPBudget)
			transitOpts["tag"] = tag
			transitOpts["mode"] = "transit"
			transitOpts["target"] = net.JoinHostPort(serverIPs[i+1], nextPort)
			transitOpts["proto"] = nextProto
			appendHop(transitOpts, tag, true)
			previousUsesTransit = true
			if nextProto == "udp" {
				nestedUDPBudget = nextNestedUDPBudget(hop.ArgonPathMTU, nestedUDPBudget)
			} else {
				nestedUDPBudget = 0
			}
			continue
		}

		if hop.UseArgon {
			if strings.TrimSpace(hop.ArgonPassword) == "" {
				return nil, fmt.Errorf("узел %d «%s»: не задан пароль Argon4-надстройки", i+1, hop.Name)
			}
			hopPort, hopProto, err := hopTargetPortAndProto(hop)
			if err != nil {
				return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
			}
			argonAddr := net.JoinHostPort(serverIPs[i], "51820")
			if blindRelayAddr != "" {
				argonAddr = blindRelayAddr
				blindRelayAddr = ""
			}
			underlayTag := tag + "-argon"
			underlayOpts := argon4BaseOptions(hop, argonAddr, hop.ArgonPassword, hopClientID(hop)+"-underlay", nestedUDPBudget)
			underlayOpts["tag"] = underlayTag
			underlayOpts["mode"] = "auto"
			underlayOpts["target"] = net.JoinHostPort(serverIPs[i], hopPort)
			underlayOpts["proto"] = hopProto
			appendHop(underlayOpts, underlayTag, true)
			previousUsesTransit = true
			if hopProto == "udp" {
				nestedUDPBudget = nextNestedUDPBudget(hop.ArgonPathMTU, nestedUDPBudget)
			}
		}

		opts, err := hopOptions(hop, tag, serverIPs[i])
		if err != nil {
			return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
		}
		if hop.Proto == "argon4" && nestedUDPBudget > 0 {
			if existing, _ := opts["path_mtu"].(int); existing == 0 || existing > nestedUDPBudget {
				opts["path_mtu"] = nestedUDPBudget
			}
		}
		finalPathCap := 0
		if hop.ArgonPathMTU > 0 {
			finalPathCap = hop.ArgonPathMTU
		}
		if nestedUDPBudget > 0 && (finalPathCap == 0 || nestedUDPBudget < finalPathCap) {
			finalPathCap = nestedUDPBudget
		}
		isEndpoint := opts["type"] == "openconnect" || opts["type"] == "openvpn-client" || opts["type"] == argon4Type
		if opts["type"] == argon4Type && blindRelayAddr != "" {
			opts["server"] = blindRelayAddr
			blindRelayAddr = ""
		}
		appendHop(opts, tag, isEndpoint)
		previousUsesTransit = false
		if i == len(plan.Hops)-1 && finalPathCap > 0 {
			innerCap := finalPathCap - 64
			if innerCap < argon4.MinPathMTU {
				innerCap = argon4.MinPathMTU
			}
			if tunIn, ok := inbounds[0].(map[string]any); ok && innerCap < tunMTU {
				tunIn["mtu"] = innerCap
			}
		}
		nestedUDPBudget = 0

		routes, err := parseRoutes(hop.Routes)
		if err != nil {
			return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
		}
		if len(routes) > 0 {
			rules = append(rules, map[string]any{"ip_cidr": routes, "outbound": tag})
		}
	}
	outbounds = append(outbounds, map[string]any{"type": "direct", "tag": "direct"})

	config := map[string]any{
		"log":       map[string]any{"level": "info"},
		"inbounds":  inbounds,
		"outbounds": outbounds,
		"route": map[string]any{
			"final":                 fmt.Sprintf("hop%d", len(plan.Hops)),
			"auto_detect_interface": true,
			"rules":                 rules,
		},
	}
	if len(endpoints) > 0 {
		config["endpoints"] = endpoints
	}
	return config, nil
}

// hopOptions — outbound или endpoint sing-box для узла; server — разрешённый IP (пусто — только проверка).
func hopOptions(hop *Profile, tag, server string) (map[string]any, error) {
	var opts map[string]any
	switch hop.Proto {
	case "vless", "hysteria2", "hysteria":
		l, err := parseLink(hop.URI)
		if err != nil {
			return nil, err
		}
		if server == "" {
			server = l.Host
		}
		if opts, err = singBoxOutbound(l, server); err != nil {
			return nil, err
		}
	case "openconnect":
		var err error
		if opts, err = openConnectOptions(hop, server); err != nil {
			return nil, err
		}
	case "openvpn":
		ovpn, err := parseOVPN(hop.Config)
		if err != nil {
			return nil, err
		}
		if opts, err = ovpn.options(hop, server); err != nil {
			return nil, err
		}
	case "argon4":
		var err error
		if opts, err = argon4HopOptions(hop, server); err != nil {
			return nil, err
		}
	default:
		return nil, fmt.Errorf("%s", Supported(hop.Proto))
	}
	opts["tag"] = tag
	return opts, nil
}

func argon4HopOptions(hop *Profile, resolvedIP string) (map[string]any, error) {
	host, port, err := parseArgon4Server(hop.Server)
	if err != nil {
		return nil, err
	}
	if hop.Password == "" {
		return nil, fmt.Errorf("Argon4: не задан мастер-пароль")
	}
	if resolvedIP != "" {
		host = resolvedIP
	}
	return argon4BaseOptions(hop, net.JoinHostPort(host, port), hop.Password, hopClientID(hop), 0), nil
}

func openConnectOptions(hop *Profile, server string) (map[string]any, error) {
	u, err := openConnectURL(hop.Server)
	if err != nil {
		return nil, err
	}
	flavor := hop.OpenConnectFlavor
	switch flavor {
	case "", "anyconnect", "gp", "nc", "pulse", "fortinet":
	case "array":
		return nil, fmt.Errorf("OpenConnect: Array Networks на Android не поддерживается")
	default:
		return nil, fmt.Errorf("OpenConnect: неизвестный протокол %s", flavor)
	}
	tls := map[string]any{}
	host := u.Hostname()
	if server != "" && server != host {
		// подключаемся по разрешённому IP, а сертификат проверяем по имени
		tls["server_name"] = host
		u.Host = net.JoinHostPort(server, portOr(u.Port(), "443"))
	}
	if hop.ServerCert != "" {
		fp, err := certFingerprint(hop.ServerCert)
		if err != nil {
			return nil, err
		}
		tls["peer_fingerprint"] = []string{fp}
	}
	if hop.IgnoreCertErrors {
		tls["insecure"] = true
	}
	opts := map[string]any{
		"type":     "openconnect",
		"server":   u.String(),
		"username": hop.Username,
		"password": hop.Password,
	}
	if flavor != "" {
		opts["flavor"] = flavor
	}
	if hop.AuthGroup != "" {
		opts["auth_group"] = hop.AuthGroup
	}
	if len(tls) > 0 {
		opts["tls"] = tls
	}
	return opts, nil
}

func portOr(port, def string) string {
	if port == "" {
		return def
	}
	return port
}

// certFingerprint — отпечаток в формате openconnect --servercert (pin-sha256:base64 или sha1/sha256 hex) как есть:
// sing-box принимает те же формы, что и openconnect.
func certFingerprint(s string) (string, error) {
	s = strings.TrimSpace(s)
	if s == "" {
		return "", fmt.Errorf("пустой отпечаток сертификата")
	}
	return s, nil
}

func profileToProbeRequest(p Profile, resolvedIP string, transitProto string, precedingMTU int, timeoutMs int) argon4.ProbeNodeMTURequest {
	server := p.Server
	if resolvedIP != "" && p.Proto == "transit" {
		if _, port, err := parseArgon4Server(p.Server); err == nil {
			server = net.JoinHostPort(resolvedIP, port)
		}
	}
	req := argon4.ProbeNodeMTURequest{
		Proto:                   p.Proto,
		Server:                  server,
		Password:                p.Password,
		ClientID:                hopClientID(&p),
		ClientSecret:            p.ClientSecret,
		TransitProto:            transitProto,
		Link:                    p.URI,
		ProtocolVersion:         p.ArgonProtocolVersion,
		PaddingProfile:          p.ArgonPaddingProfile,
		FirstPacketProfile:      p.ArgonFirstPacketProfile,
		UseArgon:                p.UseArgon,
		ArgonPassword:           p.ArgonPassword,
		ArgonProtocolVersion:    p.ArgonProtocolVersion,
		ArgonPaddingProfile:     p.ArgonPaddingProfile,
		ArgonFirstPacketProfile: p.ArgonFirstPacketProfile,
		PrecedingMTU:            precedingMTU,
		TimeoutMs:               timeoutMs,
	}
	if p.Proto == "openvpn" {
		req.OpenVPNConfig = p.Config
	}
	if p.Proto == "amneziawg" {
		req.WireGuardConfig = p.Config
	}
	if strings.EqualFold(p.Proto, "transit") {
		req.TransitProto = argon4.ProtoUDP
		if strings.TrimSpace(p.Password) != "" {
			req.Mode = argon4.ModeTransit
		}
	}
	return req
}

func parseChainHops(chainJSON string) []Profile {
	raw := strings.TrimSpace(chainJSON)
	if raw == "" {
		return nil
	}
	var hops []Profile
	var p Plan
	if err := json.Unmarshal([]byte(raw), &p); err == nil && len(p.Hops) > 0 {
		return p.Hops
	}
	if err := json.Unmarshal([]byte(raw), &hops); err != nil {
		return nil
	}
	return hops
}

func computePrecedingMTUBudget(chainJSON, targetID string) int {
	hops := parseChainHops(chainJSON)
	if len(hops) == 0 {
		return 0
	}
	prev := 0
	for i := range hops {
		hop := &hops[i]
		if hop.ID == targetID && targetID != "" {
			return prev
		}
		if hop.Proto == "transit" || i < len(hops)-1 {
			outer := hop.ArgonPathMTU
			if outer <= 0 {
				outer = argon4.DefaultPathMTU
			}
			if prev > 0 {
				prev = nextNestedUDPBudget(outer, prev)
			} else {
				prev = outer
			}
		} else {
			prev = 0
		}
	}
	return 0
}

func hopTargetAddress(hop *Profile) string {
	if hop == nil {
		return ""
	}
	if hop.UseArgon && hop.Proto != "argon4" && hop.Proto != "transit" {
		if h, p, err := parseArgon4Server(hop.Server); err == nil && h != "" {
			return net.JoinHostPort(h, p)
		}
	}
	switch hop.Proto {
	case "vless", "hysteria2", "hysteria":
		if l, err := parseLink(hop.URI); err == nil && l.Host != "" {
			port := strconv.Itoa(l.Port)
			if hop.UseArgon {
				port = "51820"
			}
			return net.JoinHostPort(l.Host, port)
		}
	case "openconnect":
		if u, err := openConnectURL(hop.Server); err == nil && u.Hostname() != "" {
			port := portOr(u.Port(), "443")
			if hop.UseArgon {
				port = "51820"
			}
			return net.JoinHostPort(u.Hostname(), port)
		}
	case "openvpn":
		if ovpn, err := parseOVPN(hop.Config); err == nil && ovpn.remoteHost != "" {
			port := strconv.Itoa(ovpn.remotePort)
			if hop.UseArgon {
				port = "51820"
			}
			return net.JoinHostPort(ovpn.remoteHost, port)
		}
	case "argon4", "transit":
		if h, p, err := parseArgon4Server(hop.Server); err == nil && h != "" {
			return net.JoinHostPort(h, p)
		}
	case "amneziawg":
		for _, rawLine := range strings.Split(hop.Config, "\n") {
			line := strings.TrimSpace(rawLine)
			if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 && strings.EqualFold(strings.TrimSpace(parts[0]), "Endpoint") {
				if h, p, err := parseArgon4Server(parts[1]); err == nil && h != "" {
					return net.JoinHostPort(h, p)
				}
			}
		}
		if h, p, err := parseArgon4Server(hop.Server); err == nil && h != "" {
			return net.JoinHostPort(h, p)
		}
	}
	if hop.Server != "" {
		if h, p, err := parseArgon4Server(hop.Server); err == nil && h != "" {
			return net.JoinHostPort(h, p)
		}
	}
	return ""
}

func nextTargetInChain(chainJSON, targetID string) string {
	if strings.TrimSpace(targetID) == "" {
		return ""
	}
	hops := parseChainHops(chainJSON)
	for i := range hops {
		if hops[i].ID == targetID && i+1 < len(hops) {
			return hopTargetAddress(&hops[i+1])
		}
	}
	return ""
}

// ProbeProfileMTU подбирает оптимальный внешний MTU для узла (вызывается из Android Kotlin через gomobile).
func ProbeProfileMTU(profileJSON, chainJSON string) string {
	var p Profile
	if err := json.Unmarshal([]byte(profileJSON), &p); err != nil {
		out, _ := json.Marshal(argon4.ProbeNodeMTUResult{
			OK:    false,
			Error: fmt.Sprintf("некорректный JSON профиля: %v", err),
		})
		return string(out)
	}
	preceding := computePrecedingMTUBudget(chainJSON, p.ID)
	transitProto := ""
	if p.Proto == "transit" {
		transitProto = argon4.ProtoUDP
	}
	req := profileToProbeRequest(p, "", transitProto, preceding, 7000)
	if p.Proto == "transit" {
		if nextTarget := nextTargetInChain(chainJSON, p.ID); nextTarget != "" {
			req.Target = nextTarget
		}
		if strings.TrimSpace(p.Password) != "" {
			req.Mode = argon4.ModeTransit
		}
	}
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()
	res := argon4.ProbeNodeMTU(ctx, req)
	out, _ := json.Marshal(res)
	return string(out)
}

func autoProbeTransitInitialMTU(plan *Plan, ips []string, logf func(string)) {
	if plan == nil || len(plan.Hops) == 0 {
		return
	}
	// До первого туннелирующего узла (слепой или первый прямой транзит) сокет ещё идёт напрямую.
	directPath := true
	for i := range plan.Hops {
		hop := &plan.Hops[i]
		if !directPath {
			break
		}
		if hop.Proto != "transit" {
			directPath = false
			continue
		}
		if hop.ArgonPathMTU > 0 {
			if strings.TrimSpace(hop.Password) != "" {
				directPath = false
			}
			continue
		}
		if strings.TrimSpace(hop.Password) != "" {
			// Для первого аутентифицированного транзита автоподбор выполнит connectTransitUDP при старте,
			// но быстрый замер перед buildConfig задаст вложенный бюджет для следующих не-Argon узлов.
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			target := ""
			if i+1 < len(plan.Hops) {
				nextPort := "51820"
				if !plan.Hops[i+1].UseArgon {
					if pt, _, err := hopTargetPortAndProto(&plan.Hops[i+1]); err == nil {
						nextPort = pt
					}
				} else if _, pt, err := parseArgon4Server(plan.Hops[i+1].Server); err == nil {
					nextPort = pt
				}
				target = net.JoinHostPort(ips[i+1], nextPort)
			}
			req := profileToProbeRequest(*hop, ips[i], argon4.ProtoUDP, 0, 1500)
			req.Mode = argon4.ModeTransit
			req.TransitProto = argon4.ProtoUDP
			req.Target = target
			res := argon4.ProbeNodeMTU(ctx, req)
			cancel()
			if res.OK && res.OptimalPathMTU > 0 && res.OptimalPathMTU < argon4.DefaultPathMTU {
				hop.ArgonPathMTU = res.OptimalPathMTU
				if logf != nil {
					logf(fmt.Sprintf("автоподбор MTU транзита «%s»: path_mtu=%d", hop.Name, hop.ArgonPathMTU))
				}
			}
			directPath = false
			continue
		}
		// Слепой транзит: подбираем path MTU по DF/NIC, не ожидая рукопожатия.
		ctx, cancel := context.WithTimeout(context.Background(), 1500*time.Millisecond)
		res := argon4.ProbeNodeMTU(ctx, profileToProbeRequest(*hop, ips[i], argon4.ProtoUDP, 0, 1200))
		cancel()
		if res.OK && res.OptimalPathMTU > 0 && res.OptimalPathMTU < argon4.DefaultPathMTU {
			hop.ArgonPathMTU = res.OptimalPathMTU
			if logf != nil {
				logf(fmt.Sprintf("автоподбор MTU слепого транзита «%s»: path_mtu=%d", hop.Name, hop.ArgonPathMTU))
			}
		}
	}
}
