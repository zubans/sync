package main

import (
	"encoding/json"
	"fmt"
	"os"
	"strings"
)

// Store повторяет экспорт profiles.json из macOS-версии CEP и не изменяет исходный файл.
type Store struct {
	Profiles []Profile `json:"profiles"`
	Chains   []Chain   `json:"chains"`
}

type Profile struct {
	ID                             string `json:"id"`
	Name                           string `json:"name"`
	Proto                          string `json:"proto"`
	URI                            string `json:"uri"`
	Config                         string `json:"config"`
	Server                         string `json:"server"`
	Username                       string `json:"username"`
	Password                       string `json:"password"`
	ClientID                       string `json:"clientID"`
	ClientSecret                   string `json:"clientSecret"`
	OpenConnectFlavor              string `json:"openconnectFlavor"`
	ServerCert                     string `json:"serverCert"`
	AuthGroup                      string `json:"authGroup"`
	IgnoreCertErrors               bool   `json:"ignoreCertErrors"`
	Routes                         string `json:"routes"`
	UseArgon                       bool   `json:"useArgon"`
	ArgonPort                      int    `json:"argonPort,omitempty"`
	ArgonPassword                  string `json:"argonPassword"`
	ArgonProtocolVersion           int    `json:"argonProtocolVersion,omitempty"`
	ArgonPaddingProfile            string `json:"argonPaddingProfile,omitempty"`
	ArgonFirstPacketProfile        string `json:"argonFirstPacketProfile,omitempty"`
	ArgonPathMTU                   int    `json:"argonPathMTU,omitempty"`
	ArgonHandshakeMaxAttempts      int    `json:"argonHandshakeMaxAttempts,omitempty"`
	ArgonHandshakeInitialTimeoutMs int    `json:"argonHandshakeInitialTimeoutMs,omitempty"`
	ArgonHandshakeMaxTimeoutMs     int    `json:"argonHandshakeMaxTimeoutMs,omitempty"`
	ArgonKeepaliveMinIdleSec       int    `json:"argonKeepaliveMinIdleSec,omitempty"`
	ArgonKeepaliveMaxIdleSec       int    `json:"argonKeepaliveMaxIdleSec,omitempty"`
	ArgonBehaviorProfile           string `json:"argonBehaviorProfile,omitempty"`
	ArgonLowPowerMode              bool   `json:"argonLowPowerMode,omitempty"`
	ArgonVideoCallMaxDurationSec   int    `json:"argonVideoCallMaxDurationSec,omitempty"`
}

type Chain struct {
	ID        string   `json:"id"`
	Name      string   `json:"name"`
	Hops      []string `json:"hops"`
	DNS       []string `json:"dns"`
	BlockIPv6 *bool    `json:"blockIPv6"`
}

type Plan struct {
	ChainName string
	Hops      []Profile
	DNS       []string
	BlockIPv6 bool
}

var supportedProtocols = map[string]bool{
	"argon4": true, "transit": true, "vless": true, "hysteria2": true, "hysteria": true,
	"openconnect": true, "openvpn": true,
}

func loadStore(path string) (*Store, error) {
	data, err := os.ReadFile(path)
	if err != nil {
		return nil, fmt.Errorf("чтение profiles.json: %w", err)
	}
	var store Store
	if err := json.Unmarshal(data, &store); err != nil {
		return nil, fmt.Errorf("разбор profiles.json: %w", err)
	}
	return &store, nil
}

func selectPlan(store *Store, selector string) (*Plan, error) {
	if len(store.Chains) == 0 {
		return nil, fmt.Errorf("в файле нет цепочек")
	}
	var selected *Chain
	for i := range store.Chains {
		chain := &store.Chains[i]
		if selector == chain.ID || selector == chain.Name {
			if selected != nil {
				return nil, fmt.Errorf("несколько цепочек подходят под %q: укажите UUID", selector)
			}
			selected = chain
		}
	}
	if selected == nil {
		if selector == "" && len(store.Chains) == 1 {
			selected = &store.Chains[0]
		} else if selector == "" {
			return nil, fmt.Errorf("не указана цепочка: используйте -chain и команду list для просмотра")
		} else {
			return nil, fmt.Errorf("цепочка %q не найдена", selector)
		}
	}
	profiles := make(map[string]Profile, len(store.Profiles))
	for _, profile := range store.Profiles {
		profiles[profile.ID] = profile
	}
	plan := &Plan{ChainName: selected.Name, DNS: selected.DNS, BlockIPv6: true}
	if selected.BlockIPv6 != nil {
		plan.BlockIPv6 = *selected.BlockIPv6
	}
	if plan.DNS == nil {
		plan.DNS = []string{"1.1.1.1", "8.8.8.8"}
	}
	for _, id := range selected.Hops {
		profile, ok := profiles[id]
		if !ok {
			return nil, fmt.Errorf("цепочка %q ссылается на отсутствующий профиль %s", selected.Name, id)
		}
		plan.Hops = append(plan.Hops, profile)
	}
	if err := validatePlan(plan); err != nil {
		return nil, err
	}
	return plan, nil
}

func validatePlan(plan *Plan) error {
	if len(plan.Hops) == 0 {
		return fmt.Errorf("в цепочке нет ни одного узла")
	}
	for i, hop := range plan.Hops {
		if !supportedProtocols[hop.Proto] {
			return fmt.Errorf("«%s»: протокол %s пока не поддерживается в Linux CLI", hop.Name, hop.Proto)
		}
		if _, err := endpointFor(hop); err != nil {
			return fmt.Errorf("«%s»: %w", hop.Name, err)
		}
		if _, err := parseProfileRoutes(hop.Routes); err != nil {
			return fmt.Errorf("«%s»: %w", hop.Name, err)
		}
		if hop.Proto == "argon4" && hop.Password == "" {
			return fmt.Errorf("«%s»: Argon4: не задан мастер-пароль", hop.Name)
		}
		if hop.UseArgon {
			if hop.Proto == "argon4" || hop.Proto == "transit" {
				return fmt.Errorf("«%s»: Argon4-надстройка не нужна внутри профиля %s", hop.Name, hop.Proto)
			}
			if hop.ArgonPassword == "" {
				return fmt.Errorf("«%s»: Argon4-надстройка: не задан мастер-пароль", hop.Name)
			}
		}
		if hop.ArgonBehaviorProfile != "" {
			if hop.ArgonBehaviorProfile != "baseline" && hop.ArgonBehaviorProfile != "video-call" {
				return fmt.Errorf("«%s»: неизвестный поведенческий профиль Argon4 %q", hop.Name, hop.ArgonBehaviorProfile)
			}
			if hop.ArgonProtocolVersion == 4 {
				return fmt.Errorf("«%s»: поведенческие профили Argon4 требуют версию v5 и недоступны в v4", hop.Name)
			}
		}
		if hop.Proto == "transit" {
			if i == len(plan.Hops)-1 {
				return fmt.Errorf("«%s»: транзитный шлюз не может быть последним узлом цепочки", hop.Name)
			}
			if hop.Password == "" {
				if hop.ArgonBehaviorProfile != "" {
					return fmt.Errorf("«%s»: слепой транзитный шлюз не поддерживает поведенческие профили Argon4", hop.Name)
				}
				next := plan.Hops[i+1]
				if next.Proto != "argon4" && !next.UseArgon && next.Proto != "transit" {
					return fmt.Errorf("«%s»: для транзитного шлюза без пароля следующий узел должен использовать Argon4", hop.Name)
				}
			} else if hop.ArgonBehaviorProfile == "video-call" {
				next := plan.Hops[i+1]
				if !next.UseArgon && next.Proto != "argon4" && next.Proto != "hysteria2" && next.Proto != "hysteria" {
					return fmt.Errorf("«%s»: профиль video-call в транзитном шлюзе поддерживается только поверх UDP-узлов", hop.Name)
				}
			}
		}
	}
	for _, server := range plan.DNS {
		if !isIPv4(strings.TrimSpace(server)) {
			return fmt.Errorf("DNS %q не является IPv4-адресом: Linux CLI пока поддерживает только IPv4", server)
		}
	}
	return nil
}

// argonUnderlayPort — порт обёртки Argon4 на узле (useArgon): из профиля или 51820 по умолчанию.
func argonUnderlayPort(profile Profile) int {
	if profile.ArgonPort > 0 && profile.ArgonPort <= 65535 {
		return profile.ArgonPort
	}
	return 51820
}
