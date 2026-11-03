package cepcore

import (
	"context"
	"encoding/json"
	"os"
	"testing"
	"time"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/option"
	sjson "github.com/sagernet/sing/common/json"
)

// TestLiveChain поднимает настоящую цепочку из задания CEP_LIVE_PLAN с SOCKS на 127.0.0.1:20999 вместо TUN
// и держит её CEP_LIVE_SECONDS секунд. Без CEP_LIVE_PLAN пропускается.
func TestLiveChain(t *testing.T) {
	path := os.Getenv("CEP_LIVE_PLAN")
	if path == "" {
		t.Skip("CEP_LIVE_PLAN не задан")
	}
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	plan, err := parsePlan(string(data))
	if err != nil {
		t.Fatal(err)
	}
	ips, err := resolveAll(plan, func(s string) { t.Log(s) })
	if err != nil {
		t.Fatal(err)
	}
	config, err := buildConfig(plan, ips)
	if err != nil {
		t.Fatal(err)
	}
	inbounds := config["inbounds"].([]any)
	inbounds[0] = map[string]any{"type": "mixed", "tag": "in", "listen": "127.0.0.1", "listen_port": 20999}
	route := config["route"].(map[string]any)
	delete(route, "auto_detect_interface")
	config["log"] = map[string]any{"level": "debug"}
	raw, _ := json.Marshal(config)
	ctx, cancel := context.WithCancel(engineContext(context.Background()))
	defer cancel()
	options, err := sjson.UnmarshalExtendedContext[option.Options](ctx, raw)
	if err != nil {
		t.Fatal(err)
	}
	instance, err := box.New(box.Options{Context: ctx, Options: options})
	if err != nil {
		t.Fatal(err)
	}
	defer instance.Close()
	if err := instance.Start(); err != nil {
		t.Fatal(err)
	}
	seconds := 30
	if s := os.Getenv("CEP_LIVE_SECONDS"); s != "" {
		json.Unmarshal([]byte(s), &seconds)
	}
	time.Sleep(time.Duration(seconds) * time.Second)
}
