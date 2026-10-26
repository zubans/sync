package cepcore

import (
	"encoding/json"
	"fmt"
	"net"
	"net/url"
	"strconv"
	"strings"
)

// ProxyLink — разобранная ссылка vless:// hysteria2:// hy2:// hysteria://
type ProxyLink struct {
	Scheme   string
	UserInfo string
	Host     string
	Port     int
	Query    map[string]string
	Label    string
}

func parseLink(raw string) (*ProxyLink, error) {
	s := strings.TrimSpace(raw)
	if s == "" {
		return nil, fmt.Errorf("не указана ссылка")
	}
	u, err := url.Parse(s)
	if err != nil || u.Scheme == "" {
		return nil, fmt.Errorf("не удалось разобрать ссылку (перескок портов вида 443,2000-3000 не поддерживается)")
	}
	scheme := strings.ToLower(u.Scheme)
	switch scheme {
	case "vless", "hysteria2", "hy2", "hysteria":
	default:
		return nil, fmt.Errorf("неизвестная схема %s://", scheme)
	}
	host := u.Hostname()
	if host == "" {
		return nil, fmt.Errorf("в ссылке нет адреса сервера")
	}
	port, err := strconv.Atoi(u.Port())
	if err != nil {
		return nil, fmt.Errorf("в ссылке нет порта")
	}
	user := ""
	if u.User != nil {
		user = u.User.Username()
		if p, ok := u.User.Password(); ok {
			user += ":" + p
		}
	}
	query := map[string]string{}
	for k, v := range u.Query() {
		if len(v) > 0 {
			query[k] = v[0]
		}
	}
	if scheme == "hy2" {
		scheme = "hysteria2"
	}
	return &ProxyLink{Scheme: scheme, UserInfo: user, Host: host, Port: port, Query: query, Label: u.Fragment}, nil
}

func (l *ProxyLink) q(key string) string { return l.Query[key] }

func (l *ProxyLink) flag(key string) bool {
	switch strings.ToLower(l.Query[key]) {
	case "1", "true", "yes":
		return true
	}
	return false
}

// SNI по умолчанию — имя хоста из ссылки, даже когда подключаемся по уже разрешённому IP.
func (l *ProxyLink) sni() string {
	if v := l.q("sni"); v != "" {
		return v
	}
	if v := l.q("peer"); v != "" {
		return v
	}
	if net.ParseIP(l.Host) != nil {
		return ""
	}
	return l.Host
}

func splitList(s string) []string {
	var out []string
	for _, p := range strings.Split(s, ",") {
		if p = strings.TrimSpace(p); p != "" {
			out = append(out, p)
		}
	}
	return out
}

// singBoxOutbound — outbound sing-box для узла; server — уже разрешённый IP.
// На Android VLESS тоже обслуживает sing-box: xray в приложение не встроен.
func singBoxOutbound(l *ProxyLink, server string) (map[string]any, error) {
	tls := map[string]any{"enabled": true}
	if sni := l.sni(); sni != "" {
		tls["server_name"] = sni
	}
	if l.flag("insecure") || l.flag("allowInsecure") {
		tls["insecure"] = true
	}

	switch l.Scheme {
	case "hysteria2":
		if alpn := l.q("alpn"); alpn != "" {
			tls["alpn"] = splitList(alpn)
		}
		out := map[string]any{
			"type":        "hysteria2",
			"server":      server,
			"server_port": l.Port,
			"password":    l.UserInfo,
			"tls":         tls,
		}
		if obfs := l.q("obfs"); obfs != "" && obfs != "none" {
			out["obfs"] = map[string]any{"type": obfs, "password": l.q("obfs-password")}
		}
		if up, err := strconv.Atoi(l.q("upmbps")); err == nil {
			out["up_mbps"] = up
		}
		if down, err := strconv.Atoi(l.q("downmbps")); err == nil {
			out["down_mbps"] = down
		}
		return out, nil

	case "hysteria":
		if p := l.q("protocol"); p != "" && p != "udp" {
			return nil, fmt.Errorf("Hysteria v1: поддерживается только protocol=udp")
		}
		alpn := l.q("alpn")
		if alpn == "" {
			alpn = "hysteria"
		}
		tls["alpn"] = []string{alpn}
		up, err := strconv.Atoi(l.q("upmbps"))
		if err != nil {
			up = 50
		}
		down, err := strconv.Atoi(l.q("downmbps"))
		if err != nil {
			down = 100
		}
		out := map[string]any{
			"type":        "hysteria",
			"server":      server,
			"server_port": l.Port,
			"up_mbps":     up,
			"down_mbps":   down,
			"tls":         tls,
		}
		if auth := l.q("auth"); auth != "" {
			out["auth_str"] = auth
		}
		if obfs := l.q("obfsParam"); obfs != "" {
			out["obfs"] = obfs
		} else if obfs := l.q("obfs"); obfs != "" {
			out["obfs"] = obfs
		}
		return out, nil

	case "vless":
		return vlessOutbound(l, server, tls)
	}
	return nil, fmt.Errorf("неизвестная схема %s://", l.Scheme)
}

func vlessOutbound(l *ProxyLink, server string, tls map[string]any) (map[string]any, error) {
	if l.UserInfo == "" {
		return nil, fmt.Errorf("в ссылке VLESS нет UUID")
	}
	if enc := l.q("encryption"); enc != "" && enc != "none" {
		return nil, fmt.Errorf("VLESS: encryption=%s не поддерживается", enc)
	}
	out := map[string]any{
		"type":            "vless",
		"server":          server,
		"server_port":     l.Port,
		"uuid":            l.UserInfo,
		"packet_encoding": "xudp",
	}
	if flow := l.q("flow"); flow != "" {
		out["flow"] = flow
	}

	fp := l.q("fp")
	if fp == "" {
		fp = "chrome"
	}
	switch security := l.q("security"); security {
	case "reality":
		pbk := l.q("pbk")
		if pbk == "" {
			return nil, fmt.Errorf("Reality: в ссылке нет pbk")
		}
		reality := map[string]any{"enabled": true, "public_key": pbk}
		if sid := l.q("sid"); sid != "" {
			reality["short_id"] = sid
		}
		tls["reality"] = reality
		tls["utls"] = map[string]any{"enabled": true, "fingerprint": fp}
		out["tls"] = tls
	case "tls":
		if alpn := l.q("alpn"); alpn != "" {
			tls["alpn"] = splitList(alpn)
		}
		tls["utls"] = map[string]any{"enabled": true, "fingerprint": fp}
		out["tls"] = tls
	case "", "none":
	default:
		return nil, fmt.Errorf("VLESS: неизвестный security=%s", security)
	}

	path := l.q("path")
	if path == "" {
		path = "/"
	}
	host := l.q("host")
	switch network := l.q("type"); network {
	case "", "tcp", "raw":
		if l.q("headerType") == "http" {
			return nil, fmt.Errorf("VLESS: tcp с headerType=http на Android не поддерживается")
		}
	case "ws":
		ws := map[string]any{"type": "ws"}
		// path=/ws?ed=2048 — ранние данные в заголовке Sec-WebSocket-Protocol, как в xray
		if p, err := url.Parse(path); err == nil && p.Query().Get("ed") != "" {
			if ed, err := strconv.Atoi(p.Query().Get("ed")); err == nil {
				ws["max_early_data"] = ed
				ws["early_data_header_name"] = "Sec-WebSocket-Protocol"
				q := p.Query()
				q.Del("ed")
				p.RawQuery = q.Encode()
				path = p.String()
			}
		}
		ws["path"] = path
		if host != "" {
			ws["headers"] = map[string]any{"Host": host}
		}
		out["transport"] = ws
	case "httpupgrade":
		hu := map[string]any{"type": "httpupgrade", "path": path}
		if host != "" {
			hu["host"] = host
		}
		out["transport"] = hu
	case "grpc":
		out["transport"] = map[string]any{"type": "grpc", "service_name": l.q("serviceName")}
	case "xhttp", "splithttp":
		return nil, fmt.Errorf("VLESS: транспорт xhttp на Android не поддерживается (нужен xray)")
	default:
		return nil, fmt.Errorf("VLESS: транспорт type=%s не поддерживается", network)
	}
	return out, nil
}

func mustJSON(v any) string {
	b, _ := json.Marshal(v)
	return string(b)
}
