package main

import (
	"context"
	"encoding/json"
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
			Profile:            profileConfigForProfile(profile),
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
			Profile:            profileConfigForProfile(profile),
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

func profileConfigForProfile(profile Profile) argon4.ProfileConfig {
	return argon4.ProfileConfig{
		Name:         profile.ArgonBehaviorProfile,
		LowPowerMode: profile.ArgonLowPowerMode,
		MaxDuration:  time.Duration(profile.ArgonVideoCallMaxDurationSec) * time.Second,
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

func probeNodeMTUForProfile(ctx context.Context, profile Profile, precedingMTU int, target string) argon4.ProbeNodeMTUResult {
	req := argon4.ProbeNodeMTURequest{
		Proto:                   profile.Proto,
		Server:                  profile.Server,
		Password:                profile.Password,
		ClientID:                valueOr(profile.ClientID, profile.ID),
		ClientSecret:            profile.ClientSecret,
		Target:                  strings.TrimSpace(target),
		Link:                    profile.URI,
		ProtocolVersion:         profile.ArgonProtocolVersion,
		PaddingProfile:          profile.ArgonPaddingProfile,
		FirstPacketProfile:      profile.ArgonFirstPacketProfile,
		UseArgon:                profile.UseArgon,
		ArgonPort:               profile.ArgonPort,
		ArgonPassword:           profile.ArgonPassword,
		ArgonProtocolVersion:    profile.ArgonProtocolVersion,
		ArgonPaddingProfile:     profile.ArgonPaddingProfile,
		ArgonFirstPacketProfile: profile.ArgonFirstPacketProfile,
		PrecedingMTU:            precedingMTU,
	}
	if profile.Proto == "openvpn" {
		req.OpenVPNConfig = profile.Config
	}
	if profile.Proto == "amneziawg" {
		req.WireGuardConfig = profile.Config
	}
	if strings.EqualFold(profile.Proto, "transit") {
		req.TransitProto = argon4.ProtoUDP
		if strings.TrimSpace(profile.Password) != "" {
			req.Mode = argon4.ModeTransit
		}
	}
	return argon4.ProbeNodeMTU(ctx, req)
}

func resolveChainPrecedingMTU(store *Store, selected *Chain, byID map[string]Profile, nodeID string) (int, bool) {
	prevMTU := 0
	for i, id := range selected.Hops {
		hop, ok := byID[id]
		if !ok {
			continue
		}
		if hop.ID == nodeID || strings.EqualFold(hop.Name, nodeID) {
			return prevMTU, true
		}
		if hop.Proto == "transit" || i < len(selected.Hops)-1 {
			mtu := hop.ArgonPathMTU
			if mtu <= 0 {
				mtu = argon4.DefaultPathMTU
			}
			if prevMTU > 0 {
				prevMTU = nextNestedUDPBudget(mtu, prevMTU)
			} else {
				prevMTU = mtu
			}
		} else {
			prevMTU = 0
		}
	}
	return 0, false
}

func precedingMTUInChain(store *Store, chainNameOrID, nodeID string) int {
	if store == nil {
		return 0
	}
	byID := make(map[string]Profile, len(store.Profiles))
	for _, p := range store.Profiles {
		byID[p.ID] = p
	}
	selector := strings.TrimSpace(chainNameOrID)
	if selector != "" {
		for i := range store.Chains {
			c := &store.Chains[i]
			if c.ID == selector || strings.EqualFold(c.Name, selector) {
				prev, found := resolveChainPrecedingMTU(store, c, byID, nodeID)
				if found {
					return prev
				}
				return 0
			}
		}
		return 0
	}

	best := 0
	for i := range store.Chains {
		prev, found := resolveChainPrecedingMTU(store, &store.Chains[i], byID, nodeID)
		if found && prev > 0 && (best == 0 || prev < best) {
			best = prev
		}
	}
	return best
}

func nextTargetInChain(store *Store, chainNameOrID, nodeID string) string {
	if store == nil || strings.TrimSpace(nodeID) == "" {
		return ""
	}
	byID := make(map[string]Profile, len(store.Profiles))
	for _, p := range store.Profiles {
		byID[p.ID] = p
	}
	selector := strings.TrimSpace(chainNameOrID)
	findInChain := func(c *Chain) string {
		for i, id := range c.Hops {
			hop, ok := byID[id]
			if !ok {
				continue
			}
			if hop.ID == nodeID || strings.EqualFold(hop.Name, nodeID) {
				if i+1 >= len(c.Hops) {
					return ""
				}
				nextHop, okNext := byID[c.Hops[i+1]]
				if !okNext {
					return ""
				}
				return profileTargetAddress(nextHop)
			}
		}
		return ""
	}

	if selector != "" {
		for i := range store.Chains {
			c := &store.Chains[i]
			if c.ID == selector || strings.EqualFold(c.Name, selector) {
				return findInChain(c)
			}
		}
		return ""
	}

	for i := range store.Chains {
		if target := findInChain(&store.Chains[i]); target != "" {
			return target
		}
	}
	return ""
}

func profileTargetAddress(p Profile) string {
	if p.Proto == "amneziawg" {
		for _, rawLine := range strings.Split(p.Config, "\n") {
			line := strings.TrimSpace(rawLine)
			if line == "" || strings.HasPrefix(line, "#") || strings.HasPrefix(line, ";") {
				continue
			}
			parts := strings.SplitN(line, "=", 2)
			if len(parts) == 2 && strings.EqualFold(strings.TrimSpace(parts[0]), "Endpoint") {
				if ep, err := parseHostPort(strings.TrimSpace(parts[1])); err == nil && ep.Host != "" {
					port := ep.Port
					if port == 0 {
						port = 51820
					}
					return net.JoinHostPort(ep.Host, strconv.Itoa(port))
				}
			}
		}
	}
	ep, err := endpointFor(p)
	if err != nil || strings.TrimSpace(ep.Host) == "" {
		return ""
	}
	port, perr := resolvedPortFor(p)
	if perr != nil || port == 0 {
		port = 443
	}
	return net.JoinHostPort(ep.Host, strconv.Itoa(port))
}

func runProbeMTUCommand(
	profilesPath string,
	chainSelector string,
	nodeSelector string,
	reqJSON string,
	protoFlag string,
	serverFlag string,
	linkFlag string,
	passwordFlag string,
	transitProtoFlag string,
	targetFlag string,
	precedingMTU int,
	apply bool,
	asJSON bool,
	out io.Writer,
) (Profile, *Store, bool, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 8*time.Second)
	defer cancel()

	var result argon4.ProbeNodeMTUResult
	var store *Store
	var targetProfile Profile
	updated := false

	switch {
	case strings.TrimSpace(reqJSON) != "":
		var req argon4.ProbeNodeMTURequest
		if err := json.Unmarshal([]byte(reqJSON), &req); err != nil {
			return targetProfile, nil, false, fmt.Errorf("разбор -req-json: %w", err)
		}
		if precedingMTU > 0 && req.PrecedingMTU == 0 {
			req.PrecedingMTU = precedingMTU
		}
		if strings.TrimSpace(targetFlag) != "" && strings.TrimSpace(req.Target) == "" {
			req.Target = strings.TrimSpace(targetFlag)
		}
		if strings.EqualFold(strings.TrimSpace(req.Proto), "transit") && strings.TrimSpace(req.Password) != "" {
			if strings.TrimSpace(req.Mode) == "" {
				req.Mode = argon4.ModeTransit
			}
			req.TransitProto = argon4.ProtoUDP
		}
		result = argon4.ProbeNodeMTU(ctx, req)

	case profilesPath != "" && strings.TrimSpace(nodeSelector) != "":
		var err error
		store, err = loadStore(profilesPath)
		if err != nil {
			return targetProfile, nil, false, err
		}
		idx := -1
		for i, p := range store.Profiles {
			if p.ID == nodeSelector || strings.EqualFold(p.Name, nodeSelector) {
				idx = i
				break
			}
		}
		if idx < 0 {
			return targetProfile, store, false, fmt.Errorf("узел %q не найден в %s", nodeSelector, profilesPath)
		}
		targetProfile = store.Profiles[idx]
		effPreceding := precedingMTU
		if effPreceding <= 0 {
			effPreceding = precedingMTUInChain(store, chainSelector, targetProfile.ID)
		}
		effTarget := strings.TrimSpace(targetFlag)
		if effTarget == "" {
			effTarget = nextTargetInChain(store, chainSelector, targetProfile.ID)
		}
		result = probeNodeMTUForProfile(ctx, targetProfile, effPreceding, effTarget)
		if apply && result.OK && result.OptimalPathMTU > 0 {
			store.Profiles[idx].ArgonPathMTU = result.OptimalPathMTU
			targetProfile = store.Profiles[idx]
			updated = true
		}

	case strings.TrimSpace(protoFlag) != "":
		req := argon4.ProbeNodeMTURequest{
			Proto:        protoFlag,
			Server:       serverFlag,
			Link:         linkFlag,
			Password:     passwordFlag,
			TransitProto: transitProtoFlag,
			Target:       strings.TrimSpace(targetFlag),
			PrecedingMTU: precedingMTU,
		}
		if strings.EqualFold(strings.TrimSpace(protoFlag), "transit") && strings.TrimSpace(passwordFlag) != "" {
			req.Mode = argon4.ModeTransit
			req.TransitProto = argon4.ProtoUDP
		}
		result = argon4.ProbeNodeMTU(ctx, req)

	default:
		return targetProfile, nil, false, fmt.Errorf("укажите (-profiles FILE -id NODE) или (-proto ... -server/-link ...) или -req-json")
	}

	if asJSON {
		enc := json.NewEncoder(out)
		enc.SetEscapeHTML(false)
		_ = enc.Encode(result)
	} else if result.OK {
		_, _ = fmt.Fprintf(out, "Узел %s (%s): %s\n", result.Server, result.Proto, result.Message)
		if updated {
			_, _ = fmt.Fprintf(out, "Применён optimal_path_mtu=%d для узла %q.\n", result.OptimalPathMTU, targetProfile.Name)
		}
	} else {
		_, _ = fmt.Fprintf(out, "Ошибка подбора MTU (%s): %s\n", result.Proto, result.Error)
	}
	if !result.OK && !asJSON {
		return targetProfile, store, updated, fmt.Errorf("подбор MTU не удался: %s", result.Error)
	}
	return targetProfile, store, updated, nil
}
