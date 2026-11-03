package main

import (
	"bytes"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"
)

func TestWizardCreatesArgonChainAndSavesSecureStore(t *testing.T) {
	store := &Store{Profiles: []Profile{}, Chains: []Chain{}}
	input := strings.NewReader("Основная\n1\n\n127.0.0.1:51820\nsecret\n\n\n\n0\n")
	var output bytes.Buffer
	changed, err := runWizard(store, input, &output)
	if err != nil {
		t.Fatal(err)
	}
	if !changed || len(store.Chains) != 1 || len(store.Profiles) != 1 {
		t.Fatalf("цепочка не создана: %+v", store)
	}
	chain := store.Chains[0]
	profile := store.Profiles[0]
	if chain.Name != "Основная" || len(chain.Hops) != 1 || chain.Hops[0] != profile.ID {
		t.Fatalf("неверная цепочка: %+v", chain)
	}
	if profile.Proto != "argon4" || profile.Password != "secret" || profile.ClientID != profile.ID {
		t.Fatalf("неверный профиль: %+v", profile)
	}
	if !regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`).MatchString(profile.ID) {
		t.Fatalf("неверный UUID: %s", profile.ID)
	}

	path := filepath.Join(t.TempDir(), "private", "profiles.json")
	if err := saveStore(path, store); err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(path)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Fatalf("права profiles.json = %o", info.Mode().Perm())
	}
	loaded, err := loadStore(path)
	if err != nil {
		t.Fatal(err)
	}
	plan, err := selectPlan(loaded, chain.ID)
	if err != nil || len(plan.Hops) != 1 {
		t.Fatalf("сохранённая цепочка не читается: %+v, %v", plan, err)
	}
}

func TestAddNodeAppendsVLESSWithoutChangingExistingChain(t *testing.T) {
	store := &Store{
		Profiles: []Profile{{ID: "a1", Name: "Argon", Proto: "argon4", Server: "example.com:51820", Password: "secret"}},
		Chains:   []Chain{{ID: "c1", Name: "Chain", Hops: []string{"a1"}}},
	}
	input := strings.NewReader("1\n2\nVLESS\nvless://uuid@example.com:443?security=reality&sni=example.com\n\n\n\n")
	var output bytes.Buffer
	changed, err := runAddNode(store, input, &output)
	if err != nil {
		t.Fatal(err)
	}
	if !changed || len(store.Profiles) != 2 || len(store.Chains[0].Hops) != 2 {
		t.Fatalf("узел не добавлен: %+v", store)
	}
	profile := store.Profiles[1]
	if profile.Proto != "vless" || profile.Name != "VLESS" || profile.UseArgon {
		t.Fatalf("неверный VLESS: %+v", profile)
	}
	if store.Chains[0].Hops[0] != "a1" || store.Chains[0].Hops[1] != profile.ID {
		t.Fatalf("изменён порядок узлов: %+v", store.Chains[0].Hops)
	}
}

func TestSelectChainReturnsStableUUID(t *testing.T) {
	store := &Store{Chains: []Chain{{ID: "c1", Name: "Первая"}, {ID: "c2", Name: "Вторая"}}}
	id, err := runSelectChain(store, strings.NewReader("2\n"), &bytes.Buffer{})
	if err != nil {
		t.Fatal(err)
	}
	if id != "c2" {
		t.Fatalf("выбран %q", id)
	}
}

func TestLoadStoreForEditAllowsMissingFile(t *testing.T) {
	path := filepath.Join(t.TempDir(), "missing", "profiles.json")
	store, err := loadStoreForEdit(path)
	if err != nil {
		t.Fatal(err)
	}
	if store == nil || len(store.Profiles) != 0 || len(store.Chains) != 0 {
		t.Fatalf("неожиданное пустое хранилище: %+v", store)
	}
}

func TestWizardCancelLeavesStoreUnchanged(t *testing.T) {
	store := &Store{}
	changed, err := runWizard(store, strings.NewReader("\n"), &bytes.Buffer{})
	if err != nil {
		t.Fatal(err)
	}
	if changed || len(store.Profiles) != 0 || len(store.Chains) != 0 {
		t.Fatalf("отмена изменила хранилище: %+v", store)
	}
}
