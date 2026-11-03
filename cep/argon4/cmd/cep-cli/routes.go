package main

import (
	"fmt"
	"net/netip"
	"strconv"
	"strings"
)

type innerNetwork struct {
	Routes  []string
	DNS     []string
	Domains []string
}

func parseProfileRoutes(text string) ([]string, error) {
	var result []string
	for _, value := range strings.FieldsFunc(text, func(r rune) bool {
		return strings.ContainsRune(",; \n\t", r)
	}) {
		route, err := normalizeRoute(value, false)
		if err != nil {
			return nil, err
		}
		if !contains(result, route) {
			result = append(result, route)
		}
	}
	return result, nil
}

func normalizeRoute(value string, allowDefault bool) (string, error) {
	if !strings.Contains(value, "/") {
		value += "/32"
	}
	prefix, err := netip.ParsePrefix(value)
	if err != nil || !prefix.Addr().Is4() {
		return "", fmt.Errorf("«%s» — не IPv4-сеть; пример: 192.168.1.119 или 10.0.0.0/8", value)
	}
	if prefix.Bits() == 0 && !allowDefault {
		return "", fmt.Errorf("«%s»: маршрут по умолчанию управляется цепочкой", value)
	}
	if allowDefault && prefix.Bits() == 0 {
		return "", nil
	}
	return prefix.String(), nil
}

func parseOpenConnectInner(text string) innerNetwork {
	var result innerNetwork
	for _, line := range strings.Split(text, "\n") {
		key, value, ok := strings.Cut(line, "=")
		if !ok {
			continue
		}
		value = strings.TrimSpace(value)
		switch key {
		case "INC":
			route, err := normalizeRoute(value, true)
			if err == nil && route != "" && !contains(result.Routes, route) {
				result.Routes = append(result.Routes, route)
			}
		case "DNS":
			for _, server := range strings.Fields(value) {
				if isIPv4(server) {
					result.DNS = append(result.DNS, server)
				}
			}
		case "DOMAIN":
			for _, domain := range strings.FieldsFunc(value, func(r rune) bool { return r == ' ' || r == ',' }) {
				if !isIPv4(domain) && strings.IndexFunc(domain, func(r rune) bool {
					return r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z'
				}) >= 0 {
					result.Domains = append(result.Domains, domain)
				}
			}
		}
	}
	return result
}

func maskLength(mask string) (int, bool) {
	address, err := netip.ParseAddr(mask)
	if err != nil || !address.Is4() {
		return 0, false
	}
	bytes := address.As4()
	value := uint32(bytes[0])<<24 | uint32(bytes[1])<<16 | uint32(bytes[2])<<8 | uint32(bytes[3])
	length := 0
	for value&0x80000000 != 0 {
		length++
		value <<= 1
	}
	if value != 0 {
		return 0, false
	}
	return length, true
}

func parseOpenVPNInner(logText, config string) innerNetwork {
	var result innerNetwork
	directives := openVPNDirectives(config)
	if start := strings.LastIndex(logText, "PUSH_REPLY,"); start >= 0 {
		tail := logText[start+len("PUSH_REPLY,"):]
		if end := strings.IndexAny(tail, "'\n"); end >= 0 {
			tail = tail[:end]
		}
		directives = append(directives, strings.Split(tail, ",")...)
	}
	for _, directive := range directives {
		fields := strings.Fields(directive)
		if len(fields) >= 2 && fields[0] == "route" && isIPv4(fields[1]) {
			length := 32
			if len(fields) >= 3 {
				if parsed, ok := maskLength(fields[2]); ok {
					length = parsed
				} else if parsed, err := strconv.Atoi(fields[2]); err == nil {
					length = parsed
				}
			}
			route, err := normalizeRoute(fmt.Sprintf("%s/%d", fields[1], length), true)
			if err == nil && route != "" && !contains(result.Routes, route) {
				result.Routes = append(result.Routes, route)
			}
		}
		if len(fields) >= 3 && fields[0] == "dhcp-option" {
			if fields[1] == "DNS" && isIPv4(fields[2]) {
				result.DNS = append(result.DNS, fields[2])
			}
			if fields[1] == "DOMAIN" || fields[1] == "DOMAIN-SEARCH" {
				result.Domains = append(result.Domains, fields[2])
			}
		}
	}
	return result
}

func contains(values []string, wanted string) bool {
	for _, value := range values {
		if value == wanted {
			return true
		}
	}
	return false
}
