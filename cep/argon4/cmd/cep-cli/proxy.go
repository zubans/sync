package main

import (
	"encoding/json"
	"fmt"
	"strconv"
	"strings"
)

func singBoxConfig(interfaceName, tunAddress string, outbound map[string]any) map[string]any {
	outbound["tag"] = "proxy"
	return map[string]any{
		"log": map[string]any{"level": "info", "timestamp": true},
		"inbounds": []any{map[string]any{
			"type": "tun", "tag": "tun-in", "interface_name": interfaceName,
			"address": []string{tunAddress}, "mtu": 1400,
			"auto_route": false, "stack": "gvisor",
		}},
		"outbounds": []any{outbound},
		"route":     map[string]any{"final": "proxy", "auto_detect_interface": false},
	}
}

func singBoxOutbound(link ProxyLink, server string) (map[string]any, error) {
	tls := map[string]any{"enabled": true}
	if sni := link.sni(); sni != "" {
		tls["server_name"] = sni
	}
	if link.flag("insecure") || link.flag("allowInsecure") {
		tls["insecure"] = true
	}
	switch link.Scheme {
	case "hysteria2":
		if alpn := link.q("alpn"); alpn != "" {
			tls["alpn"] = strings.Split(alpn, ",")
		}
		out := map[string]any{
			"type": "hysteria2", "server": server, "server_port": link.Port,
			"password": link.UserInfo, "tls": tls,
		}
		if obfs := link.q("obfs"); obfs != "" && obfs != "none" {
			out["obfs"] = map[string]any{"type": obfs, "password": link.q("obfs-password")}
		}
		setInt(link, out, "upmbps", "up_mbps")
		setInt(link, out, "downmbps", "down_mbps")
		return out, nil
	case "hysteria":
		if protocol := link.q("protocol"); protocol != "" && protocol != "udp" {
			return nil, fmt.Errorf("Hysteria v1 поддерживает только protocol=udp")
		}
		tls["alpn"] = []string{defaultString(link.q("alpn"), "hysteria")}
		out := map[string]any{
			"type": "hysteria", "server": server, "server_port": link.Port,
			"up_mbps": queryInt(link, "upmbps", 50), "down_mbps": queryInt(link, "downmbps", 100),
			"tls": tls,
		}
		if auth := link.q("auth"); auth != "" {
			out["auth_str"] = auth
		}
		if obfs := defaultString(link.q("obfsParam"), link.q("obfs")); obfs != "" {
			out["obfs"] = obfs
		}
		return out, nil
	default:
		return nil, fmt.Errorf("sing-box не обрабатывает %s:// напрямую", link.Scheme)
	}
}

func socksOutbound(port int) map[string]any {
	return map[string]any{"type": "socks", "server": "127.0.0.1", "server_port": port, "version": "5"}
}

func xrayConfig(link ProxyLink, server string, socksPort int) (map[string]any, error) {
	if link.Scheme != "vless" {
		return nil, fmt.Errorf("xray-конфиг строится только для VLESS")
	}
	if link.UserInfo == "" {
		return nil, fmt.Errorf("в ссылке VLESS нет UUID")
	}
	user := map[string]any{"id": link.UserInfo, "encryption": defaultString(link.q("encryption"), "none")}
	if flow := link.q("flow"); flow != "" {
		user["flow"] = flow
	}
	network := defaultString(link.q("type"), "tcp")
	security := defaultString(link.q("security"), "none")
	stream := map[string]any{"network": network, "security": security}
	switch security {
	case "reality":
		if link.q("pbk") == "" {
			return nil, fmt.Errorf("Reality: в ссылке нет pbk")
		}
		reality := map[string]any{
			"publicKey": link.q("pbk"), "fingerprint": defaultString(link.q("fp"), "chrome"),
		}
		if sni := link.sni(); sni != "" {
			reality["serverName"] = sni
		}
		if value := link.q("sid"); value != "" {
			reality["shortId"] = value
		}
		if value := link.q("spx"); value != "" {
			reality["spiderX"] = value
		}
		stream["realitySettings"] = reality
	case "tls":
		tls := map[string]any{"fingerprint": defaultString(link.q("fp"), "chrome")}
		if sni := link.sni(); sni != "" {
			tls["serverName"] = sni
		}
		if alpn := link.q("alpn"); alpn != "" {
			tls["alpn"] = strings.Split(alpn, ",")
		}
		if link.flag("allowInsecure") || link.flag("insecure") {
			tls["allowInsecure"] = true
		}
		stream["tlsSettings"] = tls
	case "none":
	default:
		return nil, fmt.Errorf("VLESS: неизвестный security=%s", security)
	}
	path, hostHeader := link.q("path"), link.q("host")
	switch network {
	case "tcp", "raw":
		if link.q("headerType") == "http" {
			request := map[string]any{"path": []string{defaultString(path, "/")}}
			if hostHeader != "" {
				request["headers"] = map[string]any{"Host": []string{hostHeader}}
			}
			stream["tcpSettings"] = map[string]any{"header": map[string]any{"type": "http", "request": request}}
		}
	case "ws", "httpupgrade":
		settings := map[string]any{"path": defaultString(path, "/")}
		if hostHeader != "" {
			settings["host"] = hostHeader
		}
		stream[network+"Settings"] = settings
	case "grpc":
		stream["grpcSettings"] = map[string]any{
			"serviceName": link.q("serviceName"), "multiMode": link.q("mode") == "multi",
			"idle_timeout": 60, "health_check_timeout": 20, "permit_without_stream": true,
		}
	case "xhttp", "splithttp":
		stream["network"] = "xhttp"
		settings := map[string]any{"path": defaultString(path, "/"), "mode": defaultString(link.q("mode"), "auto")}
		if hostHeader != "" {
			settings["host"] = hostHeader
		}
		if extra := link.q("extra"); extra != "" {
			var value any
			if err := json.Unmarshal([]byte(extra), &value); err == nil {
				settings["extra"] = value
			}
		}
		stream["xhttpSettings"] = settings
	default:
		return nil, fmt.Errorf("VLESS: транспорт type=%s не поддерживается", network)
	}
	stream["sockopt"] = map[string]any{"tcpKeepAliveInterval": 15, "tcpNoDelay": true}
	return map[string]any{
		"log": map[string]any{"loglevel": "warning"},
		"inbounds": []any{map[string]any{
			"listen": "127.0.0.1", "port": socksPort, "protocol": "socks",
			"settings": map[string]any{"auth": "noauth", "udp": true},
		}},
		"outbounds": []any{map[string]any{
			"protocol": "vless",
			"settings": map[string]any{"vnext": []any{map[string]any{
				"address": server, "port": link.Port, "users": []any{user},
			}}},
			"streamSettings": stream,
		}},
	}, nil
}

func queryInt(link ProxyLink, key string, fallback int) int {
	value, err := strconv.Atoi(link.q(key))
	if err != nil {
		return fallback
	}
	return value
}

func setInt(link ProxyLink, target map[string]any, queryKey, outputKey string) {
	if value, err := strconv.Atoi(link.q(queryKey)); err == nil {
		target[outputKey] = value
	}
}

func defaultString(value, fallback string) string {
	if value == "" {
		return fallback
	}
	return value
}
