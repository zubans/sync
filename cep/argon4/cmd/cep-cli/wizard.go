package main

import (
	"bufio"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
)

type wizard struct {
	input    io.Reader
	reader   *bufio.Reader
	output   io.Writer
	terminal *os.File
}

func newWizard(input io.Reader, output io.Writer) *wizard {
	w := &wizard{input: input, reader: bufio.NewReader(input), output: output}
	if file, ok := input.(*os.File); ok {
		if info, err := file.Stat(); err == nil && info.Mode()&os.ModeCharDevice != 0 {
			w.terminal = file
		}
	}
	return w
}

func (w *wizard) printf(format string, args ...any) {
	_, _ = fmt.Fprintf(w.output, format, args...)
}

func (w *wizard) ask(prompt string) (string, error) {
	w.printf("%s", prompt)
	value, err := w.reader.ReadString('\n')
	if err != nil && !(err == io.EOF && value != "") {
		return "", fmt.Errorf("чтение ответа: %w", err)
	}
	return strings.TrimSpace(value), nil
}

func (w *wizard) askDefault(prompt, fallback string) (string, error) {
	value, err := w.ask(prompt)
	if err != nil {
		return "", err
	}
	if value == "" {
		return fallback, nil
	}
	return value, nil
}

func (w *wizard) secret(prompt string) (string, error) {
	if w.terminal == nil {
		return w.ask(prompt)
	}
	w.printf("%s", prompt)
	disable := exec.Command("stty", "-echo")
	disable.Stdin = w.terminal
	if err := disable.Run(); err != nil {
		value, readErr := w.reader.ReadString('\n')
		if readErr != nil && !(readErr == io.EOF && value != "") {
			return "", fmt.Errorf("чтение пароля: %w", readErr)
		}
		return strings.TrimSpace(value), nil
	}
	value, readErr := w.reader.ReadString('\n')
	enable := exec.Command("stty", "echo")
	enable.Stdin = w.terminal
	_ = enable.Run()
	w.printf("\n")
	if readErr != nil && !(readErr == io.EOF && value != "") {
		return "", fmt.Errorf("чтение пароля: %w", readErr)
	}
	return strings.TrimSpace(value), nil
}

func (w *wizard) confirm(prompt string, fallback bool) (bool, error) {
	suffix := " [y/N]: "
	if fallback {
		suffix = " [Y/n]: "
	}
	for {
		value, err := w.ask(prompt + suffix)
		if err != nil {
			return false, err
		}
		switch strings.ToLower(value) {
		case "":
			return fallback, nil
		case "y", "yes", "д", "да":
			return true, nil
		case "n", "no", "н", "нет":
			return false, nil
		default:
			w.printf("Введите y или n.\n")
		}
	}
}

func (w *wizard) choice(prompt string, maximum int) (int, error) {
	for {
		value, err := w.ask(prompt)
		if err != nil {
			return 0, err
		}
		number, err := strconv.Atoi(value)
		if err == nil && number >= 0 && number <= maximum {
			return number, nil
		}
		w.printf("Введите число от 0 до %d.\n", maximum)
	}
}

func newUUID() (string, error) {
	var value [16]byte
	if _, err := rand.Read(value[:]); err != nil {
		return "", fmt.Errorf("генерация UUID: %w", err)
	}
	value[6] = value[6]&0x0f | 0x40
	value[8] = value[8]&0x3f | 0x80
	encoded := hex.EncodeToString(value[:])
	return encoded[0:8] + "-" + encoded[8:12] + "-" + encoded[12:16] + "-" + encoded[16:20] + "-" + encoded[20:32], nil
}

func saveStore(path string, store *Store) error {
	data, err := json.MarshalIndent(store, "", "  ")
	if err != nil {
		return fmt.Errorf("сериализация profiles.json: %w", err)
	}
	data = append(data, '\n')
	return atomicWrite(path, data, 0o600)
}

func atomicWrite(path string, data []byte, mode os.FileMode) (returnErr error) {
	directory := filepath.Dir(path)
	if err := os.MkdirAll(directory, 0o700); err != nil {
		return fmt.Errorf("создание каталога %s: %w", directory, err)
	}
	temporary, err := os.CreateTemp(directory, ".cep-*.tmp")
	if err != nil {
		return fmt.Errorf("создание временного файла: %w", err)
	}
	name := temporary.Name()
	defer func() {
		_ = temporary.Close()
		if returnErr != nil {
			_ = os.Remove(name)
		}
	}()
	if err := temporary.Chmod(mode); err != nil {
		return err
	}
	if _, err := temporary.Write(data); err != nil {
		return fmt.Errorf("запись %s: %w", name, err)
	}
	if err := temporary.Sync(); err != nil {
		return fmt.Errorf("сохранение %s: %w", name, err)
	}
	if err := temporary.Close(); err != nil {
		return fmt.Errorf("закрытие %s: %w", name, err)
	}
	if err := os.Rename(name, path); err != nil {
		return fmt.Errorf("замена %s: %w", path, err)
	}
	return nil
}

func loadStoreForEdit(path string) (*Store, error) {
	store, err := loadStore(path)
	if errors.Is(err, os.ErrNotExist) {
		return &Store{Profiles: []Profile{}, Chains: []Chain{}}, nil
	}
	return store, err
}

func runWizard(store *Store, input io.Reader, output io.Writer) (bool, error) {
	w := newWizard(input, output)
	changed := false
	w.printf("\nМастер CEP: узлы подключаются в порядке их добавления.\n")
	for {
		if len(store.Chains) == 0 {
			w.printf("\nЦепочек пока нет. Создайте первую.\n")
			created, err := w.createChain(store)
			if err != nil {
				return changed, err
			}
			changed = changed || created
			if len(store.Chains) == 0 {
				return changed, nil
			}
			continue
		}
		w.printf("\n1. Создать новую цепочку\n2. Добавить узел в цепочку\n3. Проверить цепочку\n0. Сохранить и выйти\n")
		choice, err := w.choice("Выбор: ", 3)
		if err != nil {
			return changed, err
		}
		switch choice {
		case 0:
			return changed, nil
		case 1:
			created, err := w.createChain(store)
			if err != nil {
				return changed, err
			}
			changed = changed || created
		case 2:
			added, err := w.addNode(store)
			if err != nil {
				return changed, err
			}
			changed = changed || added
		case 3:
			chain, err := w.chooseChain(store)
			if err != nil {
				return changed, err
			}
			if chain == nil {
				continue
			}
			plan, err := selectPlan(store, chain.ID)
			if err != nil {
				w.printf("ОШИБКА: %v\nИсправьте профиль или цепочку перед запуском.\n", err)
				continue
			}
			checkPlan(plan, w.output)
		}
	}
}

func runAddNode(store *Store, input io.Reader, output io.Writer) (bool, error) {
	w := newWizard(input, output)
	if len(store.Chains) == 0 {
		return false, fmt.Errorf("сначала создайте цепочку командой wizard")
	}
	return w.addNode(store)
}

func runSelectChain(store *Store, input io.Reader, output io.Writer) (string, error) {
	w := newWizard(input, output)
	chain, err := w.chooseChain(store)
	if err != nil {
		return "", err
	}
	if chain == nil {
		return "", fmt.Errorf("цепочка не выбрана")
	}
	return chain.ID, nil
}

func (w *wizard) createChain(store *Store) (bool, error) {
	name, err := w.ask("Название новой цепочки (пусто — отмена): ")
	if err != nil || name == "" {
		return false, err
	}
	id, err := newUUID()
	if err != nil {
		return false, err
	}
	chain := Chain{ID: id, Name: name, Hops: []string{}, DNS: []string{"1.1.1.1", "8.8.8.8"}}
	before := len(store.Profiles)
	added, err := w.addNodes(store, &chain, true)
	if err != nil {
		return false, err
	}
	if !added {
		store.Profiles = store.Profiles[:before]
		w.printf("Пустая цепочка не создана.\n")
		return false, nil
	}
	store.Chains = append(store.Chains, chain)
	w.printf("Цепочка %q создана: %d узлов.\n", chain.Name, len(chain.Hops))
	return true, nil
}

func (w *wizard) addNode(store *Store) (bool, error) {
	chain, err := w.chooseChain(store)
	if err != nil || chain == nil {
		return false, err
	}
	return w.addNodes(store, chain, false)
}

func (w *wizard) addNodes(store *Store, chain *Chain, firstRequired bool) (bool, error) {
	added := false
	for {
		profile, err := w.promptProfile(len(chain.Hops) + 1)
		if err != nil {
			return added, err
		}
		if profile == nil {
			if firstRequired && len(chain.Hops) == 0 {
				return added, nil
			}
			w.printf("Узел не добавлен.\n")
			return added, nil
		}
		store.Profiles = append(store.Profiles, *profile)
		chain.Hops = append(chain.Hops, profile.ID)
		added = true
		w.printf("Узел %d %q добавлен. Синтаксис корректен.\n", len(chain.Hops), profile.Name)
		another, err := w.confirm("Добавить следующий узел?", false)
		if err != nil || !another {
			return added, err
		}
	}
}

func (w *wizard) chooseChain(store *Store) (*Chain, error) {
	if len(store.Chains) == 0 {
		return nil, fmt.Errorf("в файле нет цепочек")
	}
	w.printf("\nЦепочки:\n")
	for i, chain := range store.Chains {
		w.printf("  %d. %s (%d узлов)\n", i+1, chain.Name, len(chain.Hops))
	}
	choice, err := w.choice("Номер цепочки (0 — назад): ", len(store.Chains))
	if err != nil || choice == 0 {
		return nil, err
	}
	return &store.Chains[choice-1], nil
}

func (w *wizard) promptProfile(position int) (*Profile, error) {
	w.printf("\nТип узла %d:\n1. Argon4\n2. VLESS\n3. Hysteria 2\n4. Hysteria v1\n5. OpenConnect\n6. OpenVPN\n0. Назад\n", position)
	choice, err := w.choice("Выбор: ", 6)
	if err != nil || choice == 0 {
		return nil, err
	}
	protocols := []string{"", "argon4", "vless", "hysteria2", "hysteria", "openconnect", "openvpn"}
	profile := &Profile{Proto: protocols[choice]}
	profile.ID, err = newUUID()
	if err != nil {
		return nil, err
	}
	profile.Name, err = w.askDefault("Название узла: ", fmt.Sprintf("%s %d", profile.Proto, position))
	if err != nil {
		return nil, err
	}

	switch profile.Proto {
	case "argon4":
		if err := w.fillArgon(profile); err != nil {
			return nil, err
		}
	case "vless", "hysteria2", "hysteria":
		if err := w.fillProxyLink(profile); err != nil {
			return nil, err
		}
	case "openconnect":
		if err := w.fillOpenConnect(profile); err != nil {
			return nil, err
		}
	case "openvpn":
		if err := w.fillOpenVPN(profile); err != nil {
			return nil, err
		}
	}
	if profile.Proto != "argon4" {
		for {
			profile.Routes, err = w.ask("Сети через этот узел, через запятую (необязательно): ")
			if err != nil {
				return nil, err
			}
			if _, err := parseProfileRoutes(profile.Routes); err != nil {
				w.printf("Ошибка: %v\n", err)
				continue
			}
			break
		}
		overlay, err := w.confirm("Шифровать подключение к этому узлу через Argon4?", false)
		if err != nil {
			return nil, err
		}
		if overlay {
			profile.UseArgon = true
			for {
				profile.ArgonServer, err = w.ask("Argon4-сервер host[:port]: ")
				if err != nil {
					return nil, err
				}
				if _, err := parseArgonEndpoint(profile.ArgonServer); err != nil {
					w.printf("Ошибка: %v\n", err)
					continue
				}
				break
			}
			for profile.ArgonPassword == "" {
				profile.ArgonPassword, err = w.secret("Argon4 мастер-пароль (обязательно): ")
				if err != nil {
					return nil, err
				}
			}
		}
	}
	plan := &Plan{ChainName: "Проверка узла", Hops: []Profile{*profile}, DNS: []string{"1.1.1.1"}}
	if err := validatePlan(plan); err != nil {
		return nil, fmt.Errorf("узел не сохранён: %w", err)
	}
	return profile, nil
}

func (w *wizard) fillArgon(profile *Profile) error {
	for {
		server, err := w.askDefault("Сервер host[:port] (порт по умолчанию 51820): ", "")
		if err != nil {
			return err
		}
		if _, err := parseArgonEndpoint(server); err != nil {
			w.printf("Ошибка: %v\n", err)
			continue
		}
		profile.Server = server
		break
	}
	for profile.Password == "" {
		password, err := w.secret("Мастер-пароль (обязательно): ")
		if err != nil {
			return err
		}
		profile.Password = password
	}
	var err error
	profile.ClientID, err = w.askDefault("Client ID (пусто — UUID профиля): ", profile.ID)
	if err != nil {
		return err
	}
	profile.ClientSecret, err = w.secret("Client Secret (необязательно): ")
	return err
}

func (w *wizard) fillProxyLink(profile *Profile) error {
	for {
		uri, err := w.ask("Ссылка подключения: ")
		if err != nil {
			return err
		}
		candidate := *profile
		candidate.URI = uri
		if _, err := endpointFor(candidate); err != nil {
			w.printf("Ошибка: %v\n", err)
			continue
		}
		profile.URI = uri
		return nil
	}
}

func (w *wizard) fillOpenConnect(profile *Profile) error {
	for {
		server, err := w.ask("Сервер https://host[:port]: ")
		if err != nil {
			return err
		}
		if _, err := parseHostPort(server); err != nil {
			w.printf("Ошибка: %v\n", err)
			continue
		}
		profile.Server = server
		break
	}
	var err error
	profile.Username, err = w.ask("Имя пользователя: ")
	if err != nil {
		return err
	}
	profile.Password, err = w.secret("Пароль: ")
	if err != nil {
		return err
	}
	profile.OpenConnectFlavor, err = w.askDefault("Тип сервера [anyconnect]: ", "anyconnect")
	if err != nil {
		return err
	}
	profile.ServerCert, err = w.ask("Отпечаток сертификата (необязательно): ")
	if err != nil {
		return err
	}
	profile.AuthGroup, err = w.ask("Auth group (необязательно): ")
	if err != nil {
		return err
	}
	profile.IgnoreCertErrors, err = w.confirm("Игнорировать ошибки сертификата?", false)
	return err
}

func (w *wizard) fillOpenVPN(profile *Profile) error {
	for {
		path, err := w.ask("Путь к .ovpn: ")
		if err != nil {
			return err
		}
		if strings.HasPrefix(path, "~/") {
			home, homeErr := os.UserHomeDir()
			if homeErr == nil {
				path = filepath.Join(home, strings.TrimPrefix(path, "~/"))
			}
		}
		data, err := os.ReadFile(path)
		if err != nil {
			w.printf("Ошибка чтения: %v\n", err)
			continue
		}
		if _, err := firstOpenVPNRemote(string(data)); err != nil {
			w.printf("Ошибка конфига: %v\n", err)
			continue
		}
		profile.Config = string(data)
		break
	}
	var err error
	profile.Username, err = w.ask("Имя пользователя (необязательно): ")
	if err != nil {
		return err
	}
	profile.Password, err = w.secret("Пароль (необязательно): ")
	return err
}
