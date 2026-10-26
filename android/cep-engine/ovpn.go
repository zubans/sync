package cepcore

import (
	"fmt"
	"strconv"
	"strings"
)

// ovpnConfig — то, что из .ovpn нужно endpoint'у openvpn-client sing-box.
type ovpnConfig struct {
	remoteHost   string
	remotePort   int
	network      string
	directives   map[string][]string
	blocks       map[string]string
	keyDirection string
}

func parseOVPN(text string) (*ovpnConfig, error) {
	c := &ovpnConfig{directives: map[string][]string{}, blocks: map[string]string{}, network: "udp", remotePort: 1194}
	var block string
	var body []string
	for _, raw := range strings.Split(strings.ReplaceAll(text, "\r\n", "\n"), "\n") {
		line := strings.TrimSpace(raw)
		if block != "" {
			if strings.EqualFold(line, "</"+block+">") {
				c.blocks[block] = strings.Join(body, "\n") + "\n"
				block, body = "", nil
			} else {
				body = append(body, raw)
			}
			continue
		}
		if strings.HasPrefix(line, "<") && strings.HasSuffix(line, ">") && !strings.HasPrefix(line, "</") {
			block = strings.ToLower(line[1 : len(line)-1])
			if block == "connection" {
				return nil, fmt.Errorf("блоки <connection> не поддерживаются — оставьте в .ovpn обычные строки remote")
			}
			continue
		}
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		words := strings.Fields(line)
		key := strings.ToLower(words[0])
		if _, seen := c.directives[key]; seen && key == "remote" {
			continue // из нескольких remote берётся первый, как на macOS
		}
		c.directives[key] = words[1:]
	}
	if p := c.directives["port"]; len(p) > 0 {
		if n, err := strconv.Atoi(p[0]); err == nil {
			c.remotePort = n
		}
	}
	if p := c.directives["proto"]; len(p) > 0 {
		c.network = p[0]
	}
	remote, ok := c.directives["remote"]
	if !ok || len(remote) == 0 {
		return nil, fmt.Errorf("в конфиге OpenVPN нет строки remote")
	}
	c.remoteHost = remote[0]
	if len(remote) >= 2 {
		if n, err := strconv.Atoi(remote[1]); err == nil {
			c.remotePort = n
		}
	}
	if len(remote) >= 3 {
		c.network = remote[2]
	}
	switch {
	case strings.HasPrefix(c.network, "tcp"):
		c.network = "tcp"
	case strings.HasPrefix(c.network, "udp"):
		c.network = "udp"
	default:
		return nil, fmt.Errorf("OpenVPN: неизвестный proto %s", c.network)
	}
	if kd := c.directives["key-direction"]; len(kd) > 0 {
		c.keyDirection = kd[0]
	}
	return c, nil
}

func (c *ovpnConfig) arg(key string) string {
	if v := c.directives[key]; len(v) > 0 {
		return v[0]
	}
	return ""
}

func (c *ovpnConfig) has(key string) bool {
	_, ok := c.directives[key]
	return ok
}

// pem — содержимое inline-блока; ссылки на файлы рядом с .ovpn в приложении недоступны.
func (c *ovpnConfig) pem(key string) (string, error) {
	if b, ok := c.blocks[key]; ok {
		return b, nil
	}
	if c.has(key) {
		return "", fmt.Errorf("OpenVPN: %s указан файлом — вставьте его в .ovpn блоком <%s>…</%s>", key, key, key)
	}
	return "", nil
}

func direction(d string) string {
	switch d {
	case "0":
		return "server"
	case "1":
		return "client"
	}
	return ""
}

func (c *ovpnConfig) options(hop *Profile, server string) (map[string]any, error) {
	if server == "" {
		server = c.remoteHost
	}
	if c.has("secret") {
		return nil, fmt.Errorf("OpenVPN: режим static key на Android не поддерживается")
	}
	tls := map[string]any{}
	ca, err := c.pem("ca")
	if err != nil {
		return nil, err
	}
	if ca == "" {
		return nil, fmt.Errorf("OpenVPN: в конфиге нет <ca>")
	}
	tls["certificate"] = ca
	cert, err := c.pem("cert")
	if err != nil {
		return nil, err
	}
	key, err := c.pem("key")
	if err != nil {
		return nil, err
	}
	if cert != "" {
		tls["client_certificate"] = cert
		tls["client_key"] = key
	}
	if c.has("tls-auth") || c.blocks["tls-auth"] != "" {
		k, err := c.pem("tls-auth")
		if err != nil {
			return nil, err
		}
		wrap := map[string]any{"type": "tls_auth", "key": k}
		dir := c.keyDirection
		if a := c.directives["tls-auth"]; len(a) >= 2 {
			dir = a[1]
		}
		if d := direction(dir); d != "" {
			wrap["direction"] = d
		}
		tls["control_wrap"] = wrap
	}
	for _, kind := range []string{"tls-crypt", "tls-crypt-v2"} {
		if c.has(kind) || c.blocks[kind] != "" {
			k, err := c.pem(kind)
			if err != nil {
				return nil, err
			}
			tls["control_wrap"] = map[string]any{"type": strings.ReplaceAll(kind, "-", "_"), "key": k}
		}
	}
	if v := c.directives["verify-x509-name"]; len(v) > 0 {
		tls["server_name"] = v[0]
		if len(v) >= 2 {
			tls["server_name_type"] = v[1]
		}
	}
	if v := c.arg("remote-cert-tls"); v != "" {
		tls["remote_certificate_tls"] = v
	}
	if v := c.arg("tls-version-min"); v != "" {
		tls["version_min"] = v
	}
	if v := c.arg("tls-cipher"); v != "" {
		tls["cipher"] = v
	}

	opts := map[string]any{
		"type":        "openvpn-client",
		"server":      server,
		"server_port": c.remotePort,
		"network":     c.network,
		"tls":         tls,
	}
	if c.has("auth-user-pass") {
		if hop.Username == "" {
			return nil, fmt.Errorf("OpenVPN: конфиг требует логин и пароль (auth-user-pass)")
		}
		opts["username"] = hop.Username
		opts["password"] = hop.Password
	}
	if v := c.directives["data-ciphers"]; len(v) > 0 {
		opts["data_ciphers"] = strings.Split(v[0], ":")
	} else if v := c.directives["ncp-ciphers"]; len(v) > 0 {
		opts["data_ciphers"] = strings.Split(v[0], ":")
	}
	if v := c.arg("data-ciphers-fallback"); v != "" {
		opts["data_ciphers_fallback"] = v
	} else if v := c.arg("cipher"); v != "" {
		opts["data_ciphers_fallback"] = v
	}
	if v := c.arg("auth"); v != "" {
		opts["auth"] = v
	}
	if c.has("comp-lzo") {
		opts["compression_lzo"] = orDefault(c.arg("comp-lzo"), "adaptive")
	}
	if c.has("compress") {
		opts["compression"] = orDefault(c.arg("compress"), "stub")
	}
	if v := c.arg("mssfix"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			opts["mss_fix"] = n
		}
	}
	if v := c.arg("fragment"); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			opts["fragment"] = n
		}
	}
	return opts, nil
}

func orDefault(s, def string) string {
	if s == "" {
		return def
	}
	return s
}
