package cepcore

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net"
	"strconv"
	"strings"
	"sync"
	"syscall"

	xcore "github.com/xtls/xray-core/core"
	xlog "github.com/xtls/xray-core/common/log"
	"github.com/xtls/xray-core/infra/conf/serial"
	_ "github.com/xtls/xray-core/main/distro/all"
	"github.com/xtls/xray-core/transport/internet"
)

// VLESS обслуживает встроенный xray-core, как xray на macOS: sing-box не умеет xhttp, gRPC в режиме multi,
// ML-DSA в Reality и расходится с xray в проверке Reality у части серверов. sing-box остаётся для TUN, маршрутов
// и остальных протоколов: узел VLESS в его конфиге становится SOCKS-выходом на локальный вход xray, а сам xray
// выходит в сеть через предыдущий узел цепочки — через локальный SOCKS-вход sing-box (dialerProxy).

var (
	xrayProtectOnce sync.Once
	xrayProtectMu   sync.Mutex
	xrayProtect     func(fd int32) bool
)

// setXrayProtect направляет сокеты xray в VpnService.protect: первый узел цепочки идёт мимо VPN, как и сокеты sing-box.
// Контроллер у xray общий на процесс, поэтому регистрируется один раз, а функция protect меняется при каждом старте.
func setXrayProtect(protect func(fd int32) bool) {
	xrayProtectMu.Lock()
	xrayProtect = protect
	xrayProtectMu.Unlock()
	xrayProtectOnce.Do(func() {
		_ = internet.RegisterDialerController(func(network, address string, conn syscall.RawConn) error {
			xrayProtectMu.Lock()
			p := xrayProtect
			xrayProtectMu.Unlock()
			if p == nil {
				return nil
			}
			var failed bool
			if err := conn.Control(func(fd uintptr) { failed = !p(int32(fd)) }); err != nil {
				return err
			}
			if failed {
				return fmt.Errorf("protect: не удалось вывести сокет xray из VPN")
			}
			return nil
		})
	})
}

// moveVLESSToXray заменяет outbound'ы VLESS в конфиге sing-box на SOCKS к xray и возвращает конфиг xray
// (nil — VLESS в цепочке нет). Конфиг sing-box меняется на месте.
func moveVLESSToXray(config map[string]any, plan *Plan) (map[string]any, error) {
	outbounds, _ := config["outbounds"].([]any)
	inbounds, _ := config["inbounds"].([]any)
	route, _ := config["route"].(map[string]any)
	var rules []any
	if route != nil {
		rules, _ = route["rules"].([]any)
	}
	var xIn, xOut, xRules, viaRules []any
	for i, raw := range outbounds {
		ob, ok := raw.(map[string]any)
		if !ok || ob["type"] != "vless" {
			continue
		}
		tag, _ := ob["tag"].(string)
		n, err := strconv.Atoi(strings.TrimPrefix(tag, "hop"))
		if err != nil || n < 1 || n > len(plan.Hops) {
			return nil, fmt.Errorf("VLESS: неожиданный тег outbound %q", tag)
		}
		hop := &plan.Hops[n-1]
		link, err := parseLink(hop.URI)
		if err != nil {
			return nil, fmt.Errorf("узел %d «%s»: %w", n, hop.Name, err)
		}
		server, _ := ob["server"].(string)
		port := link.Port
		if p, ok := ob["server_port"].(int); ok && p > 0 {
			port = p
		}
		out, err := xrayVLESSOutbound(link, server, port)
		if err != nil {
			return nil, fmt.Errorf("узел %d «%s»: %w", n, hop.Name, err)
		}
		out["tag"] = tag
		if detour, _ := ob["detour"].(string); detour != "" {
			viaPort, err := freeLocalPort()
			if err != nil {
				return nil, err
			}
			via := "via-" + tag
			inbounds = append(inbounds, map[string]any{"type": "socks", "tag": via, "listen": "127.0.0.1", "listen_port": viaPort})
			viaRules = append(viaRules, map[string]any{"inbound": []string{via}, "outbound": detour})
			xOut = append(xOut, map[string]any{
				"tag": via, "protocol": "socks",
				"settings": map[string]any{"servers": []any{map[string]any{"address": "127.0.0.1", "port": viaPort}}},
			})
			out["streamSettings"].(map[string]any)["sockopt"].(map[string]any)["dialerProxy"] = via
		}
		socksPort, err := freeLocalPort()
		if err != nil {
			return nil, err
		}
		in := "in-" + tag
		xIn = append(xIn, map[string]any{
			"tag": in, "listen": "127.0.0.1", "port": socksPort, "protocol": "socks",
			"settings": map[string]any{"auth": "noauth", "udp": true, "ip": "127.0.0.1"},
		})
		xRules = append(xRules, map[string]any{"type": "field", "inboundTag": []string{in}, "outboundTag": tag})
		xOut = append(xOut, out)
		outbounds[i] = map[string]any{"type": "socks", "tag": tag, "server": "127.0.0.1", "server_port": socksPort, "version": "5"}
	}
	if len(xOut) == 0 {
		return nil, nil
	}
	config["outbounds"] = outbounds
	config["inbounds"] = inbounds
	if route != nil {
		// вход xray в предыдущий узел — раньше «сетей через узел», иначе они перехватят трафик xray
		route["rules"] = append(viaRules, rules...)
	}
	return map[string]any{
		"log":       map[string]any{"loglevel": "warning"},
		"inbounds":  xIn,
		"outbounds": xOut,
		"routing":   map[string]any{"domainStrategy": "AsIs", "rules": xRules},
	}, nil
}

// xrayVLESSOutbound — outbound xray для ссылки VLESS; те же правила, что XrayConfig в CEP для macOS.
func xrayVLESSOutbound(l *ProxyLink, server string, port int) (map[string]any, error) {
	if l.Scheme != "vless" {
		return nil, fmt.Errorf("xray обслуживает только VLESS")
	}
	if l.UserInfo == "" {
		return nil, fmt.Errorf("в ссылке VLESS нет UUID")
	}
	encryption := l.q("encryption")
	if encryption == "" {
		encryption = "none"
	}
	user := map[string]any{"id": l.UserInfo, "encryption": encryption}
	if flow := l.q("flow"); flow != "" {
		user["flow"] = flow
	}
	network := l.q("type")
	if network == "" {
		network = "tcp"
	}
	security := l.q("security")
	if security == "" {
		security = "none"
	}
	fp := l.q("fp")
	if fp == "" {
		fp = "chrome"
	}
	stream := map[string]any{"network": network, "security": security}
	switch security {
	case "reality":
		pbk := l.q("pbk")
		if pbk == "" {
			return nil, fmt.Errorf("Reality: в ссылке нет pbk")
		}
		reality := map[string]any{"publicKey": pbk, "fingerprint": fp}
		if sni := l.sni(); sni != "" {
			reality["serverName"] = sni
		}
		if sid := l.q("sid"); sid != "" {
			reality["shortId"] = sid
		}
		if spx := l.q("spx"); spx != "" {
			reality["spiderX"] = spx
		}
		if pqv := l.q("pqv"); pqv != "" {
			reality["mldsa65Verify"] = pqv
		}
		stream["realitySettings"] = reality
	case "tls":
		tls := map[string]any{"fingerprint": fp}
		if sni := l.sni(); sni != "" {
			tls["serverName"] = sni
		} else {
			tls["serverName"] = l.Host
		}
		if alpn := splitList(l.q("alpn")); len(alpn) > 0 {
			tls["alpn"] = alpn
		}
		if l.flag("allowInsecure") || l.flag("insecure") {
			tls["allowInsecure"] = true
		}
		stream["tlsSettings"] = tls
	case "none":
	default:
		return nil, fmt.Errorf("VLESS: неизвестный security=%s", security)
	}

	path, host := l.q("path"), l.q("host")
	orRoot := func(p string) string {
		if p == "" {
			return "/"
		}
		return p
	}
	switch network {
	case "tcp", "raw":
		if l.q("headerType") == "http" {
			request := map[string]any{"path": []string{orRoot(path)}}
			if host != "" {
				request["headers"] = map[string]any{"Host": []string{host}}
			}
			stream["tcpSettings"] = map[string]any{"header": map[string]any{"type": "http", "request": request}}
		}
	case "ws":
		ws := map[string]any{"path": orRoot(path)}
		if host != "" {
			ws["host"] = host
		}
		stream["wsSettings"] = ws
	case "httpupgrade":
		hu := map[string]any{"path": orRoot(path)}
		if host != "" {
			hu["host"] = host
		}
		stream["httpupgradeSettings"] = hu
	case "grpc":
		grpc := map[string]any{
			"serviceName": l.q("serviceName"), "idle_timeout": 60, "health_check_timeout": 20, "permit_without_stream": true,
		}
		if l.q("mode") == "multi" {
			grpc["multiMode"] = true
		}
		if authority := l.q("authority"); authority != "" {
			grpc["authority"] = authority
		}
		stream["grpcSettings"] = grpc
	case "xhttp", "splithttp":
		stream["network"] = "xhttp"
		mode := l.q("mode")
		if mode == "" {
			mode = "auto"
		}
		xhttp := map[string]any{"path": orRoot(path), "mode": mode}
		if host != "" {
			xhttp["host"] = host
		}
		if extra := l.q("extra"); extra != "" {
			var obj any
			if json.Unmarshal([]byte(extra), &obj) == nil {
				xhttp["extra"] = obj
			}
		}
		stream["xhttpSettings"] = xhttp
	default:
		return nil, fmt.Errorf("VLESS: транспорт type=%s не поддерживается", network)
	}
	stream["sockopt"] = map[string]any{"tcpKeepAliveInterval": 15, "tcpNoDelay": true}
	return map[string]any{
		"protocol": "vless",
		"settings": map[string]any{"vnext": []any{map[string]any{"address": server, "port": port, "users": []any{user}}}},
		"streamSettings": stream,
	}, nil
}

// startXray запускает встроенный xray с готовым конфигом; журнал xray идёт в журнал CEP.
func startXray(config map[string]any, p Platform) (*xcore.Instance, error) {
	raw, err := json.Marshal(config)
	if err != nil {
		return nil, err
	}
	cfg, err := serial.LoadJSONConfig(bytes.NewReader(raw))
	if err != nil {
		return nil, fmt.Errorf("конфиг xray: %w", err)
	}
	instance, err := xcore.New(cfg)
	if err != nil {
		return nil, fmt.Errorf("xray: %w", err)
	}
	if err := instance.Start(); err != nil {
		instance.Close()
		return nil, fmt.Errorf("xray: %w", err)
	}
	// после Start: модуль журнала xray ставит свой обработчик при запуске
	xlog.RegisterHandler(xrayLog{p})
	return instance, nil
}

type xrayLog struct{ p Platform }

func (h xrayLog) Handle(msg xlog.Message) { h.p.Log("xray: " + msg.String()) }

// freeLocalPort — свободный порт на 127.0.0.1 сразу для TCP и UDP (SOCKS xray принимает UDP на том же порту).
func freeLocalPort() (int, error) {
	for range 20 {
		l, err := net.Listen("tcp", "127.0.0.1:0")
		if err != nil {
			return 0, fmt.Errorf("локальный порт: %w", err)
		}
		port := l.Addr().(*net.TCPAddr).Port
		u, err := net.ListenPacket("udp", net.JoinHostPort("127.0.0.1", strconv.Itoa(port)))
		l.Close()
		if err == nil {
			u.Close()
			return port, nil
		}
	}
	return 0, fmt.Errorf("не нашёлся свободный локальный порт")
}
