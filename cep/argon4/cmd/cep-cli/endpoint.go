package main

import (
	"fmt"
	"net"
	"net/netip"
	"net/url"
	"strconv"
	"strings"
)

type Endpoint struct {
	Host string
	Port int
}

type ProxyLink struct {
	Scheme   string
	UserInfo string
	Host     string
	Port     int
	Query    url.Values
}

func (l ProxyLink) q(key string) string {
	return l.Query.Get(key)
}

func (l ProxyLink) flag(key string) bool {
	switch strings.ToLower(l.q(key)) {
	case "1", "true", "yes":
		return true
	default:
		return false
	}
}

func (l ProxyLink) sni() string {
	for _, key := range []string{"sni", "peer"} {
		if value := l.q(key); value != "" {
			return value
		}
	}
	if _, err := netip.ParseAddr(l.Host); err == nil {
		return ""
	}
	return l.Host
}

func endpointFor(profile Profile) (Endpoint, error) {
	switch profile.Proto {
	case "vless", "hysteria2", "hysteria":
		link, err := parseProxyLink(profile.URI)
		return Endpoint{Host: link.Host, Port: link.Port}, err
	case "openvpn":
		remote, err := firstOpenVPNRemote(profile.Config)
		return Endpoint{Host: remote.Host, Port: remote.Port}, err
	case "argon4":
		return parseArgonEndpoint(profile.Server)
	default:
		return parseHostPort(profile.Server)
	}
}

func parseArgonEndpoint(raw string) (Endpoint, error) {
	trimmed := strings.TrimSpace(raw)
	if strings.Contains(trimmed, "://") && !strings.HasPrefix(trimmed, "udp://") && !strings.HasPrefix(trimmed, "argon4://") {
		return Endpoint{}, fmt.Errorf("Argon4 поддерживает только udp:// и argon4://")
	}
	if strings.Contains(trimmed, "@") {
		return Endpoint{}, fmt.Errorf("адрес Argon4 не должен содержать учётные данные")
	}
	endpoint, err := parseHostPort(trimmed)
	if err != nil {
		return Endpoint{}, err
	}
	if endpoint.Port == 0 {
		endpoint.Port = 51820
	}
	return endpoint, nil
}

func parseHostPort(raw string) (Endpoint, error) {
	value := strings.TrimSpace(raw)
	if value == "" {
		return Endpoint{}, fmt.Errorf("не указан сервер")
	}
	if strings.Contains(value, "://") {
		parsed, err := url.Parse(value)
		if err != nil {
			return Endpoint{}, fmt.Errorf("неверный адрес сервера: %w", err)
		}
		value = parsed.Host
	}
	if slash := strings.IndexByte(value, '/'); slash >= 0 {
		value = value[:slash]
	}
	host := value
	port := 0
	if strings.HasPrefix(value, "[") {
		var err error
		host, _, err = splitOptionalPort(value)
		if err != nil {
			return Endpoint{}, err
		}
		if parsed, err := strconv.Atoi(strings.TrimPrefix(value[strings.LastIndex(value, "]")+1:], ":")); err == nil && strings.LastIndex(value, "]")+1 < len(value) {
			port = parsed
		}
	} else if strings.Count(value, ":") == 1 {
		var portText string
		var err error
		host, portText, err = net.SplitHostPort(value)
		if err != nil {
			return Endpoint{}, fmt.Errorf("неверный адрес %q: %w", raw, err)
		}
		port, err = strconv.Atoi(portText)
		if err != nil || port < 1 || port > 65535 {
			return Endpoint{}, fmt.Errorf("неверный порт в %q", raw)
		}
	}
	host = strings.Trim(host, "[]")
	if host == "" {
		return Endpoint{}, fmt.Errorf("не указан сервер")
	}
	return Endpoint{Host: host, Port: port}, nil
}

func splitOptionalPort(value string) (string, string, error) {
	close := strings.LastIndex(value, "]")
	if close < 0 {
		return "", "", fmt.Errorf("неверный адрес %q", value)
	}
	return value[1:close], value[close+1:], nil
}

func parseProxyLink(raw string) (ProxyLink, error) {
	parsed, err := url.Parse(strings.TrimSpace(raw))
	if err != nil {
		return ProxyLink{}, fmt.Errorf("не удалось разобрать ссылку: %w", err)
	}
	scheme := strings.ToLower(parsed.Scheme)
	if scheme == "hy2" {
		scheme = "hysteria2"
	}
	if scheme != "vless" && scheme != "hysteria2" && scheme != "hysteria" {
		return ProxyLink{}, fmt.Errorf("неизвестная схема %s://", parsed.Scheme)
	}
	host := parsed.Hostname()
	port, err := strconv.Atoi(parsed.Port())
	if host == "" || err != nil || port < 1 || port > 65535 {
		return ProxyLink{}, fmt.Errorf("в ссылке нужны корректные адрес и порт сервера")
	}
	userInfo := parsed.User.Username()
	if password, ok := parsed.User.Password(); ok {
		userInfo += ":" + password
	}
	return ProxyLink{Scheme: scheme, UserInfo: userInfo, Host: host, Port: port, Query: parsed.Query()}, nil
}

func isIPv4(value string) bool {
	address, err := netip.ParseAddr(value)
	return err == nil && address.Is4()
}
