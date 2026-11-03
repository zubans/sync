package main

import (
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

func TestRealSingBoxAcceptsLinuxConfig(t *testing.T) {
	path, err := exec.LookPath("sing-box")
	if err != nil {
		t.Skip("sing-box не установлен")
	}
	config := singBoxConfig("ceps0", "172.19.0.1/30", socksOutbound(20800))
	checkRealToolConfig(t, path, config, "check")
}

func TestRealXrayAcceptsLinuxConfig(t *testing.T) {
	path, err := exec.LookPath("xray")
	if err != nil {
		t.Skip("xray не установлен")
	}
	link, err := parseProxyLink("vless://11111111-2222-3333-4444-555555555555@h.example.com:4430?type=xhttp&security=reality&sni=yandex.net&fp=chrome&pbk=SbVKOEMjK0sIlbwg4akyBg5mL5KZwwB-ed4eEE7YnRc&sid=ab12&path=%2Fvlx")
	if err != nil {
		t.Fatal(err)
	}
	config, err := xrayConfig(link, "192.0.2.1", 20800)
	if err != nil {
		t.Fatal(err)
	}
	checkRealToolConfig(t, path, config, "run", "-test")
}

func checkRealToolConfig(t *testing.T, tool string, config any, args ...string) {
	t.Helper()
	path := filepath.Join(t.TempDir(), "config.json")
	data, err := json.Marshal(config)
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, data, 0o600); err != nil {
		t.Fatal(err)
	}
	args = append(args, "-c", path)
	output, err := exec.Command(tool, args...).CombinedOutput()
	if err != nil {
		t.Fatalf("%s %v: %v\n%s", tool, args, err, output)
	}
}
