package main

import (
	"context"
	"fmt"
	"io"
	"net"
	"strconv"
	"strings"
	"time"

	"cep/argon4"
)

const probeTimeout = 4 * time.Second

// checkPlan отделяет обязательную проверку синтаксиса от необязательной проверки сети.
// Следующий узел цепочки часто намеренно недоступен без предыдущего туннеля.
func checkPlan(plan *Plan, output io.Writer) error {
	if err := validatePlan(plan); err != nil {
		return err
	}
	_, _ = fmt.Fprintf(output, "Синтаксис цепочки %q корректен: %d узлов.\n", plan.ChainName, len(plan.Hops))
	for index, profile := range plan.Hops {
		_, _ = fmt.Fprintf(output, "%d. %s: ", index+1, profile.Name)
		result := probeProfile(profile)
		_, _ = fmt.Fprintln(output, result)
	}
	_, _ = fmt.Fprintln(output, "Предупреждения о доступности не блокируют сохранение и запуск: следующий узел может быть доступен только через предыдущий.")
	return nil
}

func probeProfile(profile Profile) string {
	endpoint, err := endpointFor(profile)
	if err != nil {
		return "ОШИБКА синтаксиса: " + err.Error()
	}
	switch profile.Proto {
	case "hysteria2", "hysteria":
		return fmt.Sprintf("ПРЕДУПРЕЖДЕНИЕ: UDP %s не проверяется простым подключением; доступность подтвердит handshake при запуске", endpointText(endpoint))
	case "openvpn":
		if openVPNUsesUDP(profile.Config) {
			return fmt.Sprintf("ПРЕДУПРЕЖДЕНИЕ: UDP %s не проверяется простым подключением; доступность подтвердит handshake при запуске", endpointText(endpoint))
		}
	case "transit":
		if profile.Password == "" {
			connection, err := net.DialTimeout("tcp", endpointText(endpoint), probeTimeout)
			if err != nil {
				return fmt.Sprintf("слепой транзит %s (TCP недоступен или работает только по UDP: %v)", endpointText(endpoint), err)
			}
			_ = connection.Close()
			return fmt.Sprintf("слепой транзит %s (TCP доступен)", endpointText(endpoint))
		}
		client, err := argon4.NewClient(argon4.ClientConfig{
			Server:             endpointText(endpoint),
			MasterPassword:     profile.Password,
			ClientID:           valueOr(profile.ClientID, profile.ID),
			ClientSecret:       profile.ClientSecret,
			Mode:               argon4.ModeTransit,
			Proto:              argon4.ProtoUDP,
			ProtocolVersion:    profile.ArgonProtocolVersion,
			PaddingProfile:     profile.ArgonPaddingProfile,
			FirstPacketProfile: profile.ArgonFirstPacketProfile,
			PathMTU:            profile.ArgonPathMTU,
			Timing:             timingConfigForProfile(profile),
		})
		if err != nil {
			return "ОШИБКА: " + err.Error()
		}
		ctx, cancel := context.WithTimeout(context.Background(), probeTimeout)
		defer cancel()
		err = client.Connect(ctx)
		_ = client.Close()
		if err != nil {
			return unavailableWarning(endpoint, err)
		}
		return "Argon4-транзит handshake успешен"
	case "argon4":
		client, err := argon4.NewClient(argon4.ClientConfig{
			Server:             endpointText(endpoint),
			MasterPassword:     profile.Password,
			ClientID:           valueOr(profile.ClientID, profile.ID),
			ClientSecret:       profile.ClientSecret,
			ProtocolVersion:    profile.ArgonProtocolVersion,
			PaddingProfile:     profile.ArgonPaddingProfile,
			FirstPacketProfile: profile.ArgonFirstPacketProfile,
			PathMTU:            profile.ArgonPathMTU,
			Timing:             timingConfigForProfile(profile),
		})
		if err != nil {
			return "ОШИБКА: " + err.Error()
		}
		ctx, cancel := context.WithTimeout(context.Background(), probeTimeout)
		defer cancel()
		err = client.Connect(ctx)
		_ = client.Close()
		if err != nil {
			return unavailableWarning(endpoint, err)
		}
		return "Argon4 handshake успешен"
	}

	connection, err := net.DialTimeout("tcp", endpointText(endpoint), probeTimeout)
	if err != nil {
		return unavailableWarning(endpoint, err)
	}
	_ = connection.Close()
	return fmt.Sprintf("TCP %s доступен напрямую", endpointText(endpoint))
}

func timingConfigForProfile(profile Profile) argon4.TimingConfig {
	return argon4.TimingConfig{
		HandshakeMaxAttempts:    profile.ArgonHandshakeMaxAttempts,
		HandshakeInitialTimeout: time.Duration(profile.ArgonHandshakeInitialTimeoutMs) * time.Millisecond,
		HandshakeMaxTimeout:     time.Duration(profile.ArgonHandshakeMaxTimeoutMs) * time.Millisecond,
		KeepaliveMinIdle:        time.Duration(profile.ArgonKeepaliveMinIdleSec) * time.Second,
		KeepaliveMaxIdle:        time.Duration(profile.ArgonKeepaliveMaxIdleSec) * time.Second,
	}
}

func unavailableWarning(endpoint Endpoint, err error) string {
	return fmt.Sprintf("ПРЕДУПРЕЖДЕНИЕ: синтаксис корректен, но %s напрямую сейчас недоступен (%v); если узел должен открываться через предыдущий, это ожидаемо", endpointText(endpoint), err)
}

func endpointText(endpoint Endpoint) string {
	port := endpoint.Port
	if port == 0 {
		port = 443
	}
	return net.JoinHostPort(endpoint.Host, strconv.Itoa(port))
}

func openVPNUsesUDP(config string) bool {
	protocol := "udp"
	for _, line := range openVPNDirectives(config) {
		fields := strings.Fields(line)
		if len(fields) >= 2 && fields[0] == "proto" {
			protocol = strings.ToLower(fields[1])
		}
		if len(fields) >= 4 && fields[0] == "remote" {
			protocol = strings.ToLower(fields[3])
			break
		}
	}
	return strings.HasPrefix(protocol, "udp")
}

func valueOr(value, fallback string) string {
	if value == "" {
		return fallback
	}
	return value
}
