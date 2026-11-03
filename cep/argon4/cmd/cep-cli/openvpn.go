package main

import (
	"fmt"
	"strconv"
	"strings"
)

func openVPNDirectives(config string) []string {
	var result []string
	block := ""
	for _, raw := range strings.Split(config, "\n") {
		line := strings.TrimSpace(raw)
		if block != "" {
			if strings.EqualFold(line, "</"+block+">") {
				block = ""
			}
			continue
		}
		if strings.HasPrefix(line, "<") && strings.HasSuffix(line, ">") && !strings.HasPrefix(line, "</") {
			block = strings.ToLower(strings.TrimSuffix(strings.TrimPrefix(line, "<"), ">"))
			continue
		}
		if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
			continue
		}
		result = append(result, line)
	}
	return result
}

func firstOpenVPNRemote(config string) (Endpoint, error) {
	directives := openVPNDirectives(config)
	defaultPort := 1194
	for _, line := range directives {
		fields := strings.Fields(line)
		if len(fields) >= 2 && fields[0] == "port" {
			if port, err := strconv.Atoi(fields[1]); err == nil {
				defaultPort = port
			}
		}
	}
	for _, line := range directives {
		fields := strings.Fields(line)
		if len(fields) >= 2 && fields[0] == "remote" {
			port := defaultPort
			if len(fields) >= 3 {
				parsed, err := strconv.Atoi(fields[2])
				if err != nil || parsed < 1 || parsed > 65535 {
					return Endpoint{}, fmt.Errorf("неверный порт в remote OpenVPN")
				}
				port = parsed
			}
			return Endpoint{Host: fields[1], Port: port}, nil
		}
	}
	return Endpoint{}, fmt.Errorf("в конфиге OpenVPN нет строки remote")
}

func pinOpenVPNRemote(config, ip, interfaceName string) (string, error) {
	if _, err := firstOpenVPNRemote(config); err != nil {
		return "", err
	}
	if strings.Contains(strings.ToLower(config), "<connection>") {
		return "", fmt.Errorf("блоки <connection> не поддерживаются")
	}
	remote, _ := firstOpenVPNRemote(config)
	protocol := ""
	for _, line := range openVPNDirectives(config) {
		fields := strings.Fields(line)
		if len(fields) >= 2 && fields[0] == "remote" {
			if len(fields) >= 4 {
				protocol = " " + fields[3]
			}
			break
		}
	}
	var lines []string
	block := ""
	for _, raw := range strings.Split(config, "\n") {
		line := strings.TrimSpace(raw)
		if block != "" {
			lines = append(lines, raw)
			if strings.EqualFold(line, "</"+block+">") {
				block = ""
			}
			continue
		}
		if strings.HasPrefix(line, "<") && strings.HasSuffix(line, ">") && !strings.HasPrefix(line, "</") {
			block = strings.ToLower(strings.TrimSuffix(strings.TrimPrefix(line, "<"), ">"))
			lines = append(lines, raw)
			continue
		}
		fields := strings.Fields(line)
		if len(fields) > 0 && (fields[0] == "remote" || fields[0] == "remote-random" || fields[0] == "remote-random-hostname") {
			continue
		}
		lines = append(lines, raw)
	}
	pinned := fmt.Sprintf("remote %s %d%s", ip, remote.Port, protocol)
	lines = append([]string{pinned}, lines...)
	lines = append(lines, "dev "+interfaceName, "dev-type tun")
	return strings.Join(lines, "\n"), nil
}
