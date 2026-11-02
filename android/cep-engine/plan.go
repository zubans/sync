package cepcore

import (
	"encoding/json"
	"fmt"
	"net"
	"net/netip"
	"net/url"
	"strconv"
	"strings"
)

// Profile и Plan — те же поля, что в profiles.json приложения для macOS.
type Profile struct {
	ID                string `json:"id"`
	Name              string `json:"name"`
	Proto             string `json:"proto"`
	URI               string `json:"uri"`
	Config            string `json:"config"`
	Server            string `json:"server"`
	Username          string `json:"username"`
	Password          string `json:"password"`
	ClientID          string `json:"clientID"`
	ClientSecret      string `json:"clientSecret"`
	OpenConnectFlavor string `json:"openconnectFlavor"`
	ServerCert        string `json:"serverCert"`
	AuthGroup         string `json:"authGroup"`
	IgnoreCertErrors  bool   `json:"ignoreCertErrors"`
	Routes            string `json:"routes"`
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
	case "vless", "hysteria2", "hysteria", "openconnect", "openvpn", "argon4":
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
	case "argon4":
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
	for _, hop := range plan.Hops {
		if reason := Supported(hop.Proto); reason != "" {
			problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, reason))
			continue
		}
		if _, err := hopOptions(&hop, hop.Name, ""); err != nil {
			problems = append(problems, fmt.Sprintf("«%s»: %s", hop.Name, err))
			continue
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
	previousIsProxy := false
	for i := range plan.Hops {
		hop := &plan.Hops[i]
		tag := fmt.Sprintf("hop%d", i+1)
		opts, err := hopOptions(hop, tag, serverIPs[i])
		if err != nil {
			return nil, fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
		}
		isEndpoint := opts["type"] == "openconnect" || opts["type"] == "openvpn-client" || opts["type"] == argon4Type
		switch {
		case i > 0 && isEndpoint && previousIsProxy:
			// до прокси-узла — через обёртку с таймаутами (см. deadline.go);
			// UDP (DTLS) поверх прокси не используем: тоже требует таймаутов, туннель идёт по TLS
			wrap := fmt.Sprintf("hop%d-deadline", i)
			outbounds = append(outbounds, map[string]any{"type": deadlineType, "tag": wrap, "detour": fmt.Sprintf("hop%d", i)})
			opts["detour"] = wrap
			if opts["type"] == "openconnect" {
				opts["no_udp"] = true
			}
		case i > 0:
			opts["detour"] = fmt.Sprintf("hop%d", i)
		}
		if isEndpoint {
			endpoints = append(endpoints, opts)
		} else {
			outbounds = append(outbounds, opts)
		}
		previousIsProxy = !isEndpoint
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
	clientID := strings.TrimSpace(hop.ClientID)
	if clientID == "" {
		clientID = hop.ID
	}
	return map[string]any{
		"type":          argon4Type,
		"server":        net.JoinHostPort(host, port),
		"password":      hop.Password,
		"client_id":     clientID,
		"client_secret": hop.ClientSecret,
	}, nil
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
