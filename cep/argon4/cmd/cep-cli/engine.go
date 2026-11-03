package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"time"
)

type Engine struct {
	plan       *Plan
	runDir     string
	privateDir string
	ctx        context.Context

	processes   [][]*childProcess
	hostRoutes  []string
	netRoutes   []string
	v6Added     bool
	dns         *DNSManager
	argonRoutes map[int][]string
}

type defaultRoute struct {
	Gateway   string `json:"gateway"`
	Interface string `json:"dev"`
}

type argonReady struct {
	Interface string   `json:"interface"`
	Address   string   `json:"address"`
	ServerIP  string   `json:"server_ip"`
	MTU       int      `json:"mtu"`
	DNS       []string `json:"dns"`
	Routes    []string `json:"routes"`
}

func newEngine(plan *Plan, runDir string) *Engine {
	return &Engine{
		plan: plan, runDir: runDir, privateDir: filepath.Join(runDir, "private"),
		argonRoutes: make(map[int][]string),
	}
}

func (e *Engine) log(format string, args ...any) {
	log.Printf(format, args...)
}

func (e *Engine) Run(ctx context.Context) error {
	e.ctx = ctx
	if err := os.MkdirAll(e.runDir, 0o700); err != nil {
		return fmt.Errorf("создание каталога запуска: %w", err)
	}
	if err := os.Chmod(e.runDir, 0o700); err != nil {
		return fmt.Errorf("защита каталога запуска: %w", err)
	}
	_ = os.RemoveAll(e.privateDir)
	if err := os.Mkdir(e.privateDir, 0o700); err != nil {
		return fmt.Errorf("создание защищённого каталога: %w", err)
	}
	defer e.teardown()
	if err := e.connect(); err != nil {
		return err
	}
	e.log("цепочка поднята; для остановки нажмите Ctrl-C")
	return e.supervise()
}

func (e *Engine) preflight() error {
	if _, err := exec.LookPath("ip"); err != nil {
		return fmt.Errorf("не найдена утилита ip: установите пакет iproute2")
	}
	if len(e.plan.DNS) > 0 {
		if _, err := exec.LookPath("resolvectl"); err != nil {
			return fmt.Errorf("не найден resolvectl: установите systemd-resolved или задайте пустой dns в цепочке")
		}
	}
	for _, hop := range e.plan.Hops {
		tools := []string{}
		switch hop.Proto {
		case "argon4":
			tools = append(tools, "cep-argon4")
		case "vless":
			tools = append(tools, "xray", "sing-box")
		case "hysteria2", "hysteria":
			tools = append(tools, "sing-box")
		case "openconnect", "openvpn":
			tools = append(tools, hop.Proto)
		}
		if hop.UseArgon {
			tools = append(tools, "cep-argon4")
		}
		for _, tool := range tools {
			if _, err := locateTool(tool); err != nil {
				return fmt.Errorf("«%s»: %w", hop.Name, err)
			}
		}
	}
	return nil
}

func (e *Engine) connect() error {
	if err := validatePlan(e.plan); err != nil {
		return err
	}
	if err := e.preflight(); err != nil {
		return err
	}
	original, err := readDefaultRoute()
	if err != nil {
		return err
	}
	e.log("исходный маршрут: %s через %s", original.Gateway, original.Interface)

	serverIPs := make([][]string, len(e.plan.Hops))
	argonIPs := make([][]string, len(e.plan.Hops))
	for i, hop := range e.plan.Hops {
		endpoint, _ := endpointFor(hop)
		serverIPs[i], err = resolveIPv4(endpoint.Host)
		if err != nil {
			return fmt.Errorf("«%s»: %w", hop.Name, err)
		}
		e.log("%s: %s → %s", hop.Name, endpoint.Host, strings.Join(serverIPs[i], ", "))
		if hop.UseArgon {
			argonEndpoint, _ := parseArgonEndpoint(hop.ArgonServer)
			argonIPs[i], err = resolveIPv4(argonEndpoint.Host)
			if err != nil {
				return fmt.Errorf("«%s», Argon4: %w", hop.Name, err)
			}
		}
	}

	previousInterface := ""
	for i, hop := range e.plan.Hops {
		if err := e.ctx.Err(); err != nil {
			return err
		}
		nextInterface := previousInterface
		if hop.UseArgon {
			if err := e.addHostRoutes(argonIPs[i], nextInterface, original); err != nil {
				return fmt.Errorf("узел %d, Argon4-надстройка: %w", i+1, err)
			}
			ready, err := e.startArgon(i, hop, argonIPs[i][0], true)
			if err != nil {
				return fmt.Errorf("узел %d «%s», Argon4-надстройка: %w", i+1, hop.Name, err)
			}
			nextInterface = ready.Interface
		}
		if err := e.addHostRoutes(serverIPs[i], nextInterface, original); err != nil {
			return fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
		}
		interfaceName, address, err := e.startHop(i, hop, serverIPs[i][0])
		if err != nil {
			return fmt.Errorf("узел %d «%s»: %w", i+1, hop.Name, err)
		}
		e.log("узел %d «%s» поднят на %s (%s)", i+1, hop.Name, interfaceName, address)
		e.routeInner(i, hop, interfaceName)
		previousInterface = interfaceName
	}
	if previousInterface == "" {
		return fmt.Errorf("пустая цепочка")
	}
	for _, network := range []string{"0.0.0.0/1", "128.0.0.0/1"} {
		if err := e.addNetRoute(network, previousInterface); err != nil {
			return fmt.Errorf("маршрут по умолчанию: %w", err)
		}
	}
	if e.plan.BlockIPv6 {
		if output, err := exec.Command("ip", "-6", "route", "add", "blackhole", "2000::/3").CombinedOutput(); err == nil {
			e.v6Added = true
		} else {
			e.log("IPv6 blackhole не добавлен: %s", strings.TrimSpace(string(output)))
		}
	}
	e.dns = newDNSManager(previousInterface, e.plan.DNS)
	if err := e.dns.apply(); err != nil {
		return err
	}
	if len(e.plan.DNS) > 0 {
		e.log("DNS: %s", strings.Join(e.plan.DNS, ", "))
	}
	return nil
}

func (e *Engine) startHop(index int, profile Profile, ip string) (string, string, error) {
	switch profile.Proto {
	case "vless", "hysteria2", "hysteria":
		return e.startProxy(index, profile, ip)
	case "openconnect":
		return e.startOpenConnect(index, profile, ip)
	case "openvpn":
		return e.startOpenVPN(index, profile, ip)
	case "argon4":
		ready, err := e.startArgon(index, profile, ip, false)
		return ready.Interface, ready.Address, err
	default:
		return "", "", fmt.Errorf("неподдерживаемый протокол %s", profile.Proto)
	}
}

func (e *Engine) startArgon(index int, profile Profile, ip string, underlay bool) (argonReady, error) {
	var result argonReady
	readyFile := e.privateFile(index, "argon-ready.json")
	server, password := profile.Server, profile.Password
	tunName := fmt.Sprintf("cepa%d", index)
	if underlay {
		server, password = profile.ArgonServer, profile.ArgonPassword
		tunName = fmt.Sprintf("cepu%d", index)
	}
	endpoint, err := parseArgonEndpoint(server)
	if err != nil {
		return result, err
	}
	clientID := strings.TrimSpace(profile.ClientID)
	if clientID == "" {
		clientID = profile.ID
	}
	if underlay {
		clientID += "-underlay"
	}
	configuration := map[string]any{
		"server": fmt.Sprintf("%s:%d", ip, endpoint.Port), "password": password,
		"client_id": clientID, "client_secret": profile.ClientSecret,
		"ready_file": readyFile, "tun_name": tunName,
	}
	configFile := e.privateFile(index, "argon.json")
	if underlay {
		configFile = e.privateFile(index, "argon-underlay.json")
	}
	if err := writeSecretJSON(configFile, configuration); err != nil {
		return result, err
	}
	if err := e.spawn(index, "cep-argon4", []string{"--config", configFile}, "", nil); err != nil {
		return result, err
	}
	if err := e.wait(index, "Argon4 handshake и TUN", 60*time.Second, func() (bool, error) {
		data, err := os.ReadFile(readyFile)
		if os.IsNotExist(err) {
			return false, nil
		}
		if err != nil {
			return false, err
		}
		if err := json.Unmarshal(data, &result); err != nil {
			return false, nil
		}
		return result.Interface != "" && result.Address != "", nil
	}); err != nil {
		return result, err
	}
	e.argonRoutes[index] = result.Routes
	return result, nil
}

func (e *Engine) startProxy(index int, profile Profile, ip string) (string, string, error) {
	link, err := parseProxyLink(profile.URI)
	if err != nil {
		return "", "", err
	}
	var outbound map[string]any
	if link.Scheme == "vless" {
		port := 20800 + index
		config, err := xrayConfig(link, ip, port)
		if err != nil {
			return "", "", err
		}
		path := e.privateFile(index, "xray.json")
		if err := writeSecretJSON(path, config); err != nil {
			return "", "", err
		}
		if err := e.spawn(index, "xray", []string{"run", "-c", path}, "", nil); err != nil {
			return "", "", err
		}
		if err := e.waitForPort(index, port); err != nil {
			return "", "", err
		}
		outbound = socksOutbound(port)
	} else {
		outbound, err = singBoxOutbound(link, ip)
		if err != nil {
			return "", "", err
		}
	}
	interfaceName := fmt.Sprintf("ceps%d", index)
	address := fmt.Sprintf("172.19.%d.1", index)
	config := singBoxConfig(interfaceName, address+"/30", outbound)
	path := e.privateFile(index, "sing-box.json")
	if err := writeSecretJSON(path, config); err != nil {
		return "", "", err
	}
	if err := e.spawn(index, "sing-box", []string{"run", "-c", path, "-D", e.privateDir, "--disable-color"}, "", nil); err != nil {
		return "", "", err
	}
	if err := e.waitInterface(index, interfaceName); err != nil {
		return "", "", err
	}
	return interfaceName, address, nil
}

func (e *Engine) startOpenConnect(index int, profile Profile, ip string) (string, string, error) {
	script := e.privateFile(index, "openconnect.sh")
	innerFile := e.privateFile(index, "openconnect-inner")
	readyFile := e.privateFile(index, "openconnect-ready")
	scriptText := `#!/bin/sh
case "$reason" in
  connect)
    ip addr replace "$INTERNAL_IP4_ADDRESS/32" dev "$TUNDEV"
    ip link set dev "$TUNDEV" mtu "${INTERNAL_IP4_MTU:-1300}" up
    {
      n=0
      while [ "$n" -lt "${CISCO_SPLIT_INC:-0}" ]; do
        eval "echo INC=\${CISCO_SPLIT_INC_${n}_ADDR}/\${CISCO_SPLIT_INC_${n}_MASKLEN}"
        n=$((n + 1))
      done
      echo "DNS=$INTERNAL_IP4_DNS"
      echo "DOMAIN=$CISCO_DEF_DOMAIN $CISCO_SPLIT_DNS"
    } > "$CEP_INNER_FILE.tmp" && mv "$CEP_INNER_FILE.tmp" "$CEP_INNER_FILE"
    printf '%s\n%s\n' "$TUNDEV" "$INTERNAL_IP4_ADDRESS" > "$CEP_READY_FILE.tmp" &&
      mv "$CEP_READY_FILE.tmp" "$CEP_READY_FILE" ;;
  disconnect)
    ip link set dev "$TUNDEV" down 2>/dev/null ;;
esac
exit 0
`
	if err := os.WriteFile(script, []byte(scriptText), 0o700); err != nil {
		return "", "", err
	}
	flavor := defaultString(profile.OpenConnectFlavor, "anyconnect")
	interfaceName := fmt.Sprintf("cepc%d", index)
	args := []string{
		"--protocol=" + flavor, "--non-inter", "--passwd-on-stdin",
		"--script=" + script, "--no-dtls", "--interface=" + interfaceName,
	}
	endpoint, _ := parseHostPort(profile.Server)
	if endpoint.Host != ip {
		args = append(args, "--resolve="+endpoint.Host+":"+ip)
	}
	if profile.Username != "" {
		args = append(args, "--user="+profile.Username)
	}
	if profile.ServerCert != "" {
		args = append(args, "--servercert="+profile.ServerCert)
	}
	if profile.AuthGroup != "" {
		args = append(args, "--authgroup="+profile.AuthGroup)
	}
	if profile.IgnoreCertErrors {
		args = append(args, "--no-cert-check")
	}
	args = append(args, strings.TrimSpace(profile.Server))
	environment := []string{"CEP_INNER_FILE=" + innerFile, "CEP_READY_FILE=" + readyFile}
	if err := e.spawn(index, "openconnect", args, profile.Password+"\n", environment); err != nil {
		return "", "", err
	}
	address := ""
	if err := e.wait(index, "интерфейс OpenConnect", 60*time.Second, func() (bool, error) {
		data, err := os.ReadFile(readyFile)
		if os.IsNotExist(err) {
			return false, nil
		}
		if err != nil {
			return false, err
		}
		lines := strings.Split(strings.TrimSpace(string(data)), "\n")
		if len(lines) < 2 {
			return false, nil
		}
		interfaceName, address = lines[0], lines[1]
		return interfaceName != "" && address != "", nil
	}); err != nil {
		return "", "", err
	}
	return interfaceName, address, nil
}

func (e *Engine) startOpenVPN(index int, profile Profile, ip string) (string, string, error) {
	interfaceName := fmt.Sprintf("cepo%d", index)
	config, err := pinOpenVPNRemote(profile.Config, ip, interfaceName)
	if err != nil {
		return "", "", err
	}
	path := e.privateFile(index, "openvpn.conf")
	if err := os.WriteFile(path, []byte(config), 0o600); err != nil {
		return "", "", err
	}
	args := []string{
		"--config", path, "--route-noexec", "--pull-filter", "ignore", "redirect-gateway",
		"--pull-filter", "ignore", "dhcp-option", "--connect-retry-max", "3", "--verb", "3",
		"--script-security", "1",
	}
	if profile.Username != "" {
		auth := e.privateFile(index, "openvpn-auth")
		if err := os.WriteFile(auth, []byte(profile.Username+"\n"+profile.Password+"\n"), 0o600); err != nil {
			return "", "", err
		}
		args = append(args, "--auth-user-pass", auth, "--auth-nocache")
	}
	if err := e.spawn(index, "openvpn", args, "", nil); err != nil {
		return "", "", err
	}
	if err := e.waitInterface(index, interfaceName); err != nil {
		return "", "", err
	}
	address, _ := interfaceAddress(interfaceName)
	return interfaceName, address, nil
}

func (e *Engine) routeInner(index int, profile Profile, interfaceName string) {
	var inner innerNetwork
	switch profile.Proto {
	case "openconnect":
		data, _ := os.ReadFile(e.privateFile(index, "openconnect-inner"))
		inner = parseOpenConnectInner(string(data))
	case "openvpn":
		data, _ := os.ReadFile(filepath.Join(e.runDir, fmt.Sprintf("hop%d.log", index+1)))
		inner = parseOpenVPNInner(string(data), profile.Config)
	case "argon4":
		for _, value := range e.argonRoutes[index] {
			route, err := normalizeRoute(value, true)
			if err == nil && route != "" {
				inner.Routes = append(inner.Routes, route)
			}
		}
	}
	profileRoutes, _ := parseProfileRoutes(profile.Routes)
	for _, route := range profileRoutes {
		if !contains(inner.Routes, route) {
			inner.Routes = append(inner.Routes, route)
		}
	}
	for _, route := range inner.Routes {
		if err := e.addNetRoute(route, interfaceName); err != nil {
			e.log("узел %d: маршрут %s не добавлен: %v", index+1, route, err)
		} else {
			e.log("узел %d: через %s — %s", index+1, interfaceName, route)
		}
	}
}

func (e *Engine) addHostRoutes(ips []string, previousInterface string, original defaultRoute) error {
	for _, ip := range ips {
		args := []string{"route", "add", ip + "/32"}
		if previousInterface != "" {
			args = append(args, "dev", previousInterface)
		} else if original.Gateway != "" {
			args = append(args, "via", original.Gateway, "dev", original.Interface)
		} else {
			args = append(args, "dev", original.Interface)
		}
		if err := runIP(args...); err != nil {
			return err
		}
		e.hostRoutes = append(e.hostRoutes, ip+"/32")
	}
	return nil
}

func (e *Engine) addNetRoute(network, interfaceName string) error {
	if err := runIP("route", "add", network, "dev", interfaceName); err != nil {
		return err
	}
	e.netRoutes = append(e.netRoutes, network)
	return nil
}

func runIP(args ...string) error {
	output, err := exec.Command("ip", args...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("ip %s: %w: %s", strings.Join(args, " "), err, strings.TrimSpace(string(output)))
	}
	return nil
}

func readDefaultRoute() (defaultRoute, error) {
	output, err := exec.Command("ip", "-j", "-4", "route", "show", "default").CombinedOutput()
	if err != nil {
		return defaultRoute{}, fmt.Errorf("чтение маршрута по умолчанию: %w: %s", err, output)
	}
	var routes []defaultRoute
	if err := json.Unmarshal(output, &routes); err != nil || len(routes) == 0 {
		return defaultRoute{}, fmt.Errorf("нет маршрута по умолчанию — нет сети?")
	}
	if routes[0].Interface == "" {
		return defaultRoute{}, fmt.Errorf("не удалось определить интерфейс маршрута по умолчанию")
	}
	return routes[0], nil
}

func resolveIPv4(host string) ([]string, error) {
	if isIPv4(host) {
		return []string{host}, nil
	}
	addresses, err := net.LookupIP(host)
	if err != nil {
		return nil, fmt.Errorf("DNS %s: %w", host, err)
	}
	var result []string
	for _, address := range addresses {
		if ip := address.To4(); ip != nil && !contains(result, ip.String()) {
			result = append(result, ip.String())
		}
	}
	if len(result) == 0 {
		return nil, fmt.Errorf("DNS %s не вернул IPv4-адресов", host)
	}
	return result, nil
}

func interfaceAddress(interfaceName string) (string, bool) {
	output, err := exec.Command("ip", "-4", "-o", "addr", "show", "dev", interfaceName).CombinedOutput()
	if err != nil {
		return "", false
	}
	fields := strings.Fields(string(output))
	for i, field := range fields {
		if field == "inet" && i+1 < len(fields) {
			address, _, _ := strings.Cut(fields[i+1], "/")
			return address, true
		}
	}
	return "", false
}

func (e *Engine) waitInterface(index int, interfaceName string) error {
	return e.wait(index, "интерфейс "+interfaceName, 60*time.Second, func() (bool, error) {
		_, ok := interfaceAddress(interfaceName)
		return ok, nil
	})
}

func (e *Engine) waitForPort(index, port int) error {
	return e.wait(index, fmt.Sprintf("локальный порт %d", port), 10*time.Second, func() (bool, error) {
		connection, err := net.DialTimeout("tcp", fmt.Sprintf("127.0.0.1:%d", port), 200*time.Millisecond)
		if err != nil {
			return false, nil
		}
		_ = connection.Close()
		return true, nil
	})
}

func (e *Engine) wait(index int, what string, timeout time.Duration, probe func() (bool, error)) error {
	deadline := time.Now().Add(timeout)
	for time.Now().Before(deadline) {
		if err := e.ctx.Err(); err != nil {
			return err
		}
		ready, err := probe()
		if err != nil {
			return fmt.Errorf("%s: %w", what, err)
		}
		if ready {
			return nil
		}
		if index < len(e.processes) {
			for _, process := range e.processes[index] {
				if err, exited := process.exitError(); exited {
					return fmt.Errorf("%s: %w; %s", what, err, e.logTail(index))
				}
			}
		}
		time.Sleep(200 * time.Millisecond)
	}
	return fmt.Errorf("не дождались: %s за %s; %s", what, timeout, e.logTail(index))
}

func (e *Engine) logTail(index int) string {
	data, err := os.ReadFile(filepath.Join(e.runDir, fmt.Sprintf("hop%d.log", index+1)))
	if err != nil || len(data) == 0 {
		return "журнал пуст"
	}
	if len(data) > 4000 {
		data = data[len(data)-4000:]
	}
	return "журнал: " + strings.TrimSpace(string(data))
}

func (e *Engine) supervise() error {
	ticker := time.NewTicker(500 * time.Millisecond)
	defer ticker.Stop()
	for {
		select {
		case <-e.ctx.Done():
			e.log("получена команда остановки")
			return nil
		case <-ticker.C:
			for i, processes := range e.processes {
				for _, process := range processes {
					if err, exited := process.exitError(); exited {
						return fmt.Errorf("узел %d «%s»: %w", i+1, e.plan.Hops[i].Name, err)
					}
				}
			}
		}
	}
}

func (e *Engine) teardown() {
	if e.dns != nil {
		e.dns.restore()
	}
	for i := len(e.netRoutes) - 1; i >= 0; i-- {
		_ = exec.Command("ip", "route", "del", e.netRoutes[i]).Run()
	}
	for i := len(e.processes) - 1; i >= 0; i-- {
		for j := len(e.processes[i]) - 1; j >= 0; j-- {
			e.processes[i][j].stop()
		}
	}
	for i := len(e.hostRoutes) - 1; i >= 0; i-- {
		_ = exec.Command("ip", "route", "del", e.hostRoutes[i]).Run()
	}
	if e.v6Added {
		_ = exec.Command("ip", "-6", "route", "del", "blackhole", "2000::/3").Run()
	}
	_ = os.RemoveAll(e.privateDir)
	e.log("остановлено")
}

func (e *Engine) privateFile(index int, name string) string {
	return filepath.Join(e.privateDir, fmt.Sprintf("hop%d-%s", index+1, name))
}

func writeSecretJSON(path string, value any) error {
	data, err := json.Marshal(value)
	if err != nil {
		return err
	}
	return os.WriteFile(path, data, 0o600)
}
