package main

import (
	"os"
	"path/filepath"
	"testing"
)

func TestSelectPlanReadsMacOSStore(t *testing.T) {
	path := filepath.Join(t.TempDir(), "profiles.json")
	content := `{
	  "profiles": [
	    {"id":"p1","name":"Argon4 1","proto":"argon4","server":"94.103.12.175:51820","password":"secret"},
	    {"id":"p2","name":"OpenConnect 1","proto":"openconnect","server":"https://94.103.9.172:4443/","username":"user","password":"pass"}
	  ],
	  "chains": [{"id":"c1","name":"Test","hops":["p1","p2"]}]
	}`
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	store, err := loadStore(path)
	if err != nil {
		t.Fatal(err)
	}
	plan, err := selectPlan(store, "Test")
	if err != nil {
		t.Fatal(err)
	}
	if len(plan.Hops) != 2 || !plan.BlockIPv6 || len(plan.DNS) != 2 {
		t.Fatalf("неожиданный план: %+v", plan)
	}
	if plan.Hops[1].OpenConnectFlavor != "" {
		t.Fatalf("файл не должен изменяться при чтении")
	}
}

func TestSelectPlanRejectsMissingProfile(t *testing.T) {
	store := &Store{Chains: []Chain{{ID: "c1", Name: "Broken", Hops: []string{"missing"}}}}
	if _, err := selectPlan(store, "c1"); err == nil {
		t.Fatal("ожидалась ошибка отсутствующего профиля")
	}
}

func TestValidatePlanRejectsUnsupportedProtocol(t *testing.T) {
	plan := &Plan{Hops: []Profile{{Name: "SSTP", Proto: "sstp", Server: "example.com:443"}}}
	if err := validatePlan(plan); err == nil {
		t.Fatal("ожидалась ошибка неподдерживаемого протокола")
	}
}

func TestValidatePlanTransitRulesAndUseArgonWithoutServer(t *testing.T) {
	lastTransit := &Plan{Hops: []Profile{{Name: "T", Proto: "transit", Server: "relay.example.com", Password: "p"}}}
	if err := validatePlan(lastTransit); err == nil {
		t.Fatal("ожидалась ошибка: транзит не может быть последним узлом")
	}

	blindWithoutArgonNext := &Plan{Hops: []Profile{
		{Name: "Blind", Proto: "transit", Server: "relay.example.com"},
		{Name: "VLESS", Proto: "vless", URI: "vless://uuid@example.com:443?security=tls"},
	}}
	if err := validatePlan(blindWithoutArgonNext); err == nil {
		t.Fatal("ожидалась ошибка: слепой транзит без Argon4 на следующем узле")
	}

	blindWithUseArgon := &Plan{Hops: []Profile{
		{Name: "Blind", Proto: "transit", Server: "relay.example.com"},
		{Name: "VLESS", Proto: "vless", URI: "vless://uuid@example.com:443?security=tls", UseArgon: true, ArgonPassword: "secret"},
	}}
	if err := validatePlan(blindWithUseArgon); err != nil {
		t.Fatalf("ожидался успех слепого транзита + UseArgon без ArgonServer: %v", err)
	}

	authTransitWithVLESS := &Plan{Hops: []Profile{
		{Name: "T", Proto: "transit", Server: "relay.example.com:51820", Password: "secret"},
		{Name: "VLESS", Proto: "vless", URI: "vless://uuid@example.com:443?security=tls"},
	}}
	if err := validatePlan(authTransitWithVLESS); err != nil {
		t.Fatalf("ожидался успех авторизованного транзита к любому узлу: %v", err)
	}

	useArgonOnTransit := &Plan{Hops: []Profile{
		{Name: "T", Proto: "transit", Server: "relay.example.com", Password: "p", UseArgon: true, ArgonPassword: "x"},
		{Name: "A", Proto: "argon4", Server: "vpn.example.com", Password: "p"},
	}}
	if err := validatePlan(useArgonOnTransit); err == nil {
		t.Fatal("ожидалась ошибка: UseArgon на transit запрещён")
	}
}
