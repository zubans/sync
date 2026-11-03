package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"path/filepath"
	"syscall"
	"time"

	"cep/argon4"
	"github.com/sagernet/wireguard-go/tun"
)

type configuration struct {
	Server       string `json:"server"`
	Password     string `json:"password"`
	ClientID     string `json:"client_id"`
	ClientSecret string `json:"client_secret"`
	ReadyFile    string `json:"ready_file"`
	TunName      string `json:"tun_name"`
}

type readyStatus struct {
	Interface string   `json:"interface"`
	Address   string   `json:"address"`
	ServerIP  string   `json:"server_ip"`
	MTU       int      `json:"mtu"`
	DNS       []string `json:"dns,omitempty"`
	Routes    []string `json:"routes,omitempty"`
}

func main() {
	log.SetFlags(log.LstdFlags | log.Lmicroseconds)
	configPath := flag.String("config", "", "путь к JSON-конфигурации")
	flag.Parse()
	if *configPath == "" || flag.NArg() != 0 {
		fmt.Fprintln(os.Stderr, "usage: cep-argon4 --config FILE")
		os.Exit(64)
	}
	if err := run(*configPath); err != nil {
		log.Printf("Argon4: %v", err)
		os.Exit(1)
	}
}

func run(configPath string) error {
	data, err := os.ReadFile(configPath)
	if err != nil {
		return fmt.Errorf("чтение конфигурации: %w", err)
	}
	_ = os.Remove(configPath)
	var config configuration
	if err := json.Unmarshal(data, &config); err != nil {
		return fmt.Errorf("разбор конфигурации: %w", err)
	}
	clear(data)
	if config.ReadyFile == "" {
		return fmt.Errorf("не указан ready_file")
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT, syscall.SIGHUP)
	defer stop()
	connectContext, cancelConnect := context.WithTimeout(ctx, 20*time.Second)
	client, err := argon4.NewClient(argon4.ClientConfig{
		Server:         config.Server,
		MasterPassword: config.Password,
		ClientID:       config.ClientID,
		ClientSecret:   config.ClientSecret,
	})
	if err != nil {
		cancelConnect()
		return err
	}
	if err := client.Connect(connectContext); err != nil {
		cancelConnect()
		return err
	}
	cancelConnect()
	defer client.Close()
	response := client.Response()
	log.Printf("Argon4: handshake выполнен, адрес %s/%d", response.AssignedIP, response.SubnetCidr)

	tunName := config.TunName
	if tunName == "" {
		tunName = defaultTunName
	}
	device, err := tun.CreateTUN(tunName, response.MTU)
	if err != nil {
		return fmt.Errorf("создание TUN %s: %w", tunName, err)
	}
	defer device.Close()
	interfaceName, err := device.Name()
	if err != nil {
		return fmt.Errorf("имя TUN: %w", err)
	}
	if err := configureInterface(interfaceName, response); err != nil {
		return err
	}
	if err := writeReady(config.ReadyFile, readyStatus{
		Interface: interfaceName,
		Address:   response.AssignedIP,
		ServerIP:  response.ServerIP,
		MTU:       response.MTU,
		DNS:       response.DNS,
		Routes:    response.Routes,
	}); err != nil {
		return err
	}
	log.Printf("Argon4: %s поднят, MTU %d", interfaceName, response.MTU)

	errors := make(chan error, 2)
	go func() { errors <- tunToArgon(device, client) }()
	go func() { errors <- argonToTun(device, client) }()
	keepaliveDone := make(chan struct{})
	go func() {
		defer close(keepaliveDone)
		ticker := time.NewTicker(30 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-ctx.Done():
				return
			case <-ticker.C:
				if err := client.WriteKeepalive(); err != nil && ctx.Err() == nil {
					log.Printf("Argon4: keepalive: %v", err)
					return
				}
			}
		}
	}()

	var runErr error
	select {
	case <-ctx.Done():
		log.Print("Argon4: остановка")
	case runErr = <-errors:
		if runErr != nil && ctx.Err() == nil {
			runErr = fmt.Errorf("передача пакетов: %w", runErr)
		}
	}
	stop()
	_ = client.Close()
	_ = device.Close()
	<-keepaliveDone
	_ = os.Remove(config.ReadyFile)
	return runErr
}

func writeReady(path string, status readyStatus) error {
	data, err := json.Marshal(status)
	if err != nil {
		return err
	}
	temporary := filepath.Join(filepath.Dir(path), ".argon4-ready.tmp")
	if err := os.WriteFile(temporary, data, 0o600); err != nil {
		return fmt.Errorf("запись статуса: %w", err)
	}
	if err := os.Rename(temporary, path); err != nil {
		_ = os.Remove(temporary)
		return fmt.Errorf("публикация статуса: %w", err)
	}
	return nil
}

type packetClient interface {
	WritePacket([]byte) error
	ReadPacket() ([]byte, error)
}

func tunToArgon(device tun.Device, client packetClient) error {
	buffer := make([]byte, 65535+tunOffset)
	for {
		sizes := []int{0}
		n, err := device.Read([][]byte{buffer}, sizes, tunOffset)
		if err != nil {
			return err
		}
		if n != 1 || sizes[0] == 0 {
			continue
		}
		if err := client.WritePacket(buffer[tunOffset : tunOffset+sizes[0]]); err != nil {
			return err
		}
	}
}

func argonToTun(device tun.Device, client packetClient) error {
	for {
		packet, err := client.ReadPacket()
		if err != nil {
			return err
		}
		buffer := make([]byte, tunOffset+len(packet))
		copy(buffer[tunOffset:], packet)
		n, err := device.Write([][]byte{buffer}, tunOffset)
		if err != nil {
			return err
		}
		if n != 1 {
			return fmt.Errorf("TUN принял %d пакетов вместо одного", n)
		}
	}
}
