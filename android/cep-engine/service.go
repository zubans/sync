// Package cepcore — движок CEP для Android: цепочка узлов в одном экземпляре sing-box внутри VpnService.
// Узел N выходит в сеть через узел N-1 (detour), весь трафик TUN уходит в последний узел.
package cepcore

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"regexp"
	"strings"
	"sync"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	sjson "github.com/sagernet/sing/common/json"
	"github.com/sagernet/sing/service"
)

// Platform реализует VpnService на стороне Kotlin.
type Platform interface {
	// Protect выводит сокет движка из VPN (VpnService.protect), иначе узлы зациклятся сами на себя.
	Protect(fd int32) bool
	// OpenTun поднимает VPN по JSON TunSettings и возвращает дескриптор TUN.
	OpenTun(settingsJSON string) (int32, error)
	// Interfaces — сетевые интерфейсы JSON-массивом (Go на Android не может прочитать их сам через netlink).
	Interfaces() string
	// Log — строка журнала.
	Log(message string)
}

// TunSettings — что Kotlin передаёт в VpnService.Builder.
type TunSettings struct {
	Address   string   `json:"address"`
	Prefix    int      `json:"prefix"`
	MTU       int      `json:"mtu"`
	DNS       []string `json:"dns"`
	BlockIPv6 bool     `json:"blockIPv6"`
}

// Service — запущенная цепочка.
type Service struct {
	instance *box.Box
	cancel   context.CancelFunc
	platform *platform
	closed   sync.Once
}

// BuildConfig — конфиг sing-box для цепочки без разрешения адресов (для журнала и проверки).
func BuildConfig(planJSON string) (string, error) {
	plan, err := parsePlan(planJSON)
	if err != nil {
		return "", err
	}
	ips := make([]string, len(plan.Hops))
	config, err := buildConfig(plan, ips)
	if err != nil {
		return "", err
	}
	b, _ := json.MarshalIndent(redact(config), "", "  ")
	return string(b), nil
}

// Start разрешает адреса серверов, собирает конфиг и запускает sing-box.
// Вызывается до того, как VPN поднят: OpenTun придёт из sing-box во время старта.
// workDir — каталог приложения: sing-box держит там свой кэш (у процесса Android текущий каталог — «/», только чтение).
func Start(planJSON, workDir string, p Platform) (*Service, error) {
	if err := os.Chdir(workDir); err != nil {
		return nil, fmt.Errorf("рабочий каталог %s: %w", workDir, err)
	}
	plan, err := parsePlan(planJSON)
	if err != nil {
		return nil, err
	}
	logf := func(s string) { p.Log(s) }
	ips, err := resolveAll(plan, logf)
	if err != nil {
		return nil, err
	}
	config, err := buildConfig(plan, ips)
	if err != nil {
		return nil, err
	}
	pretty, _ := json.MarshalIndent(redact(config), "", "  ")
	logf("конфиг sing-box:\n" + string(pretty))

	raw, _ := json.Marshal(config)
	ctx, cancel := context.WithCancel(engineContext(context.Background()))
	options, err := sjson.UnmarshalExtendedContext[option.Options](ctx, raw)
	if err != nil {
		cancel()
		return nil, fmt.Errorf("конфиг sing-box: %w", err)
	}
	dns := plan.DNS
	if len(dns) == 0 {
		dns = []string{"1.1.1.1", "8.8.8.8"}
	}
	pl := &platform{p: p, settings: TunSettings{
		Address:   strings.Split(tunAddress, "/")[0],
		Prefix:    30,
		MTU:       tunMTU,
		DNS:       dns,
		BlockIPv6: plan.BlockIPv6,
	}}
	service.MustRegister[adapter.PlatformInterface](ctx, pl)
	instance, err := box.New(box.Options{
		Context:           ctx,
		Options:           options,
		PlatformLogWriter: logWriter{p},
	})
	if err != nil {
		cancel()
		return nil, err
	}
	if err := instance.Start(); err != nil {
		instance.Close()
		cancel()
		return nil, err
	}
	return &Service{instance: instance, cancel: cancel, platform: pl}, nil
}

// UpdateDefaultInterface — Kotlin сообщает о смене сети (Wi-Fi ↔ мобильная); index -1 — сети нет.
func (s *Service) UpdateDefaultInterface(name string, index int32) {
	s.platform.updateDefaultInterface(name, int(index))
}

func (s *Service) Close() {
	s.closed.Do(func() {
		s.instance.Close()
		s.cancel()
	})
}

type logWriter struct{ p Platform }

var ansi = regexp.MustCompile("\x1b\\[[0-9;]*m")

// WriteMessage получает от sing-box все уровни. В журнал идут info и выше без строк о каждом соединении, а из debug — сообщения
// endpoint'ов о повторах подключения (OpenConnect, OpenVPN): иначе отказ сервера не виден.
func (w logWriter) WriteMessage(level log.Level, message string) {
	retry := level == log.LevelDebug && strings.Contains(message, "endpoint/") && strings.Contains(message, "retrying")
	// «failed to search process»: sing-box на Android ищет приложение-владельца соединения через netlink, а он закрыт
	perConnection := level == log.LevelInfo && (strings.Contains(message, " connection to ") ||
		strings.Contains(message, " connection from ") || strings.Contains(message, "failed to search process"))
	if (level <= log.LevelInfo && !perConnection) || retry {
		w.p.Log(ansi.ReplaceAllString(message, ""))
	}
}

// redact убирает из конфига пароли и ключи перед записью в журнал.
func redact(v any) any {
	switch t := v.(type) {
	case map[string]any:
		out := map[string]any{}
		for k, val := range t {
			switch k {
			case "password", "uuid", "client_key", "client_secret", "key", "auth_str", "public_key", "short_id":
				out[k] = "***"
			default:
				out[k] = redact(val)
			}
		}
		return out
	case []any:
		out := make([]any, len(t))
		for i := range t {
			out[i] = redact(t[i])
		}
		return out
	}
	return v
}
