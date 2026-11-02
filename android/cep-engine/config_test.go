package cepcore

import (
	"context"
	"encoding/json"
	"testing"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/option"
	sjson "github.com/sagernet/sing/common/json"
	"github.com/sagernet/sing/service"
)

type fakePlatform struct{}

func (fakePlatform) Protect(int32) bool            { return true }
func (fakePlatform) OpenTun(string) (int32, error) { return 0, nil }
func (fakePlatform) Interfaces() string            { return "[]" }
func (fakePlatform) Log(string)                    {}

// Конфиг цепочки принимает sing-box: имена полей и типы endpoint'ов верны.
func TestConfigAcceptedBySingBox(t *testing.T) {
	plan, err := parsePlan(testPlan(
		Profile{Name: "VLESS", Proto: "vless", URI: "vless://11111111-2222-3333-4444-555555555555@example.com:443?type=grpc&security=tls&fp=safari&serviceName=s"},
		Profile{Name: "Hy2", Proto: "hysteria2", URI: "hy2://pass@example.org:443?obfs=salamander&obfs-password=x"},
		Profile{Name: "OVPN", Proto: "openvpn", Config: testOVPN, Username: "u", Password: "p"},
		Profile{Name: "OC", Proto: "openconnect", Server: "https://94.103.9.172:4443", Username: "u", Password: "p",
			ServerCert: "pin-sha256:47DEQpj8HBSa+/TImW+5JCeuQeRkm5NMpJWZG3hSuFU=", Routes: "192.168.1.0/24"},
	))
	if err != nil {
		t.Fatal(err)
	}
	config, err := buildConfig(plan, []string{"1.1.1.1", "2.2.2.2", "3.3.3.3", "94.103.9.172"})
	if err != nil {
		t.Fatal(err)
	}
	raw, _ := json.Marshal(config)
	ctx, cancel := context.WithCancel(engineContext(context.Background()))
	defer cancel()
	options, err := sjson.UnmarshalExtendedContext[option.Options](ctx, raw)
	if err != nil {
		t.Fatalf("разбор конфига: %v\n%s", err, raw)
	}
	service.MustRegister[adapter.PlatformInterface](ctx, &platform{p: fakePlatform{}})
	instance, err := box.New(box.Options{Context: ctx, Options: options})
	if err != nil {
		t.Fatalf("box.New: %v", err)
	}
	instance.Close()
}
