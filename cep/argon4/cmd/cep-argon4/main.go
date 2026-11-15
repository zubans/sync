package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"path/filepath"
	"strings"
	"sync"
	"syscall"
	"time"

	"cep/argon4"
	"github.com/sagernet/wireguard-go/tun"
)

type configuration struct {
	Server                    string `json:"server"`
	Password                  string `json:"password"`
	ClientID                  string `json:"client_id"`
	ClientSecret              string `json:"client_secret"`
	ReadyFile                 string `json:"ready_file"`
	TunName                   string `json:"tun_name"`
	Mode                      string `json:"mode,omitempty"`
	Target                    string `json:"target,omitempty"`
	Proto                     string `json:"proto,omitempty"`
	Listen                    string `json:"listen,omitempty"`
	ProtocolVersion           int    `json:"protocol_version,omitempty"`
	PaddingProfile            string `json:"padding_profile,omitempty"`
	FirstPacketProfile        string `json:"first_packet_profile,omitempty"`
	PathMTU                   int    `json:"path_mtu,omitempty"`
	HandshakeMaxAttempts      int    `json:"handshake_max_attempts,omitempty"`
	HandshakeInitialTimeoutMs int    `json:"handshake_initial_timeout_ms,omitempty"`
	HandshakeMaxTimeoutMs     int    `json:"handshake_max_timeout_ms,omitempty"`
	KeepaliveMinIdleSec       int    `json:"keepalive_min_idle_sec,omitempty"`
	KeepaliveMaxIdleSec       int    `json:"keepalive_max_idle_sec,omitempty"`
	BehaviorProfile           string `json:"behavior_profile,omitempty"`
	LowPowerMode              bool   `json:"low_power_mode,omitempty"`
	VideoCallMaxDurationSec   int    `json:"video_call_max_duration_sec,omitempty"`
}

type readyStatus struct {
	Mode      string   `json:"mode,omitempty"`
	Listen    string   `json:"listen,omitempty"`
	Port      int      `json:"port,omitempty"`
	Target    string   `json:"target,omitempty"`
	Interface string   `json:"interface,omitempty"`
	Address   string   `json:"address,omitempty"`
	ServerIP  string   `json:"server_ip,omitempty"`
	MTU       int      `json:"mtu,omitempty"`
	PathMTU   int      `json:"path_mtu,omitempty"`
	InnerMTU  int      `json:"inner_mtu,omitempty"`
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

	mode := strings.ToLower(strings.TrimSpace(config.Mode))
	if mode == "" {
		mode = argon4.ModeTunnel
	}
	clientCfg := argon4.ClientConfig{
		Server:             config.Server,
		MasterPassword:     config.Password,
		ClientID:           config.ClientID,
		ClientSecret:       config.ClientSecret,
		Mode:               mode,
		Target:             config.Target,
		Proto:              config.Proto,
		ProtocolVersion:    config.ProtocolVersion,
		PaddingProfile:     config.PaddingProfile,
		FirstPacketProfile: config.FirstPacketProfile,
		PathMTU:            config.PathMTU,
		AutoMTU:            config.PathMTU == 0,
		Timing: argon4.TimingConfig{
			HandshakeMaxAttempts:    config.HandshakeMaxAttempts,
			HandshakeInitialTimeout: time.Duration(config.HandshakeInitialTimeoutMs) * time.Millisecond,
			HandshakeMaxTimeout:     time.Duration(config.HandshakeMaxTimeoutMs) * time.Millisecond,
			KeepaliveMinIdle:        time.Duration(config.KeepaliveMinIdleSec) * time.Second,
			KeepaliveMaxIdle:        time.Duration(config.KeepaliveMaxIdleSec) * time.Second,
		},
		Profile: argon4.ProfileConfig{
			Name:         config.BehaviorProfile,
			LowPowerMode: config.LowPowerMode,
			MaxDuration:  time.Duration(config.VideoCallMaxDurationSec) * time.Second,
		},
	}

	var proxy *argon4.TransitProxy
	var client *argon4.Client
	switch mode {
	case argon4.ModeTransit:
		proxy, err = argon4.StartTransitProxy(ctx, clientCfg, config.Listen)
		if err != nil {
			return err
		}
	case argon4.ModeAuto:
		proxy, client, err = argon4.ConnectAuto(ctx, clientCfg, config.Listen)
		if err != nil {
			return err
		}
	default:
		client, err = argon4.NewClient(clientCfg)
		if err != nil {
			return err
		}
		connectContext, cancelConnect := context.WithTimeout(ctx, client.Timing().HandshakeMaxElapsed)
		if err := client.Connect(connectContext); err != nil {
			cancelConnect()
			return err
		}
		if clientCfg.AutoMTU {
			_, _ = client.DiscoverPathMTU(connectContext)
		}
		cancelConnect()
	}

	if proxy != nil {
		defer proxy.Close()
		if err := writeReady(config.ReadyFile, readyStatus{
			Mode:     argon4.ModeTransit,
			Listen:   proxy.ListenAddr(),
			Port:     proxy.Port(),
			Target:   proxy.Target(),
			MTU:      proxy.MTU(),
			PathMTU:  proxy.PathMTU(),
			InnerMTU: proxy.InnerUDPMTU(),
		}); err != nil {
			return err
		}
		log.Printf("Argon4: L4-транзит слушает %s -> %s (path_mtu=%d, inner_max=%d)", proxy.ListenAddr(), proxy.Target(), proxy.PathMTU(), proxy.InnerUDPMTU())
		select {
		case <-ctx.Done():
			log.Print("Argon4: остановка транзита")
		case <-proxy.Done():
		}
		stop()
		_ = proxy.Close()
		_ = os.Remove(config.ReadyFile)
		return nil
	}

	defer client.Close()
	response := client.Response()
	if tunMTU := client.EffectiveTunMTU(); tunMTU > 0 {
		response.MTU = tunMTU
	}
	log.Printf("Argon4: handshake выполнен, адрес %s/%d (path_mtu=%d, tun_mtu=%d)", response.AssignedIP, response.SubnetCidr, client.PathMTU(), response.MTU)

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
		Mode:      argon4.ModeTunnel,
		Interface: interfaceName,
		Address:   response.AssignedIP,
		ServerIP:  response.ServerIP,
		MTU:       response.MTU,
		PathMTU:   client.PathMTU(),
		InnerMTU:  response.MTU,
		DNS:       response.DNS,
		Routes:    response.Routes,
	}); err != nil {
		return err
	}
	log.Printf("Argon4: %s поднят, MTU %d", interfaceName, response.MTU)

	routerIP := net.ParseIP(response.ServerIP)
	errCh := make(chan error, 2)
	go func() { errCh <- tunToArgonWithMTU(device, client, routerIP) }()
	go func() { errCh <- argonToTun(device, client) }()
	var bgWG sync.WaitGroup
	bgWG.Add(2)
	go func() {
		defer bgWG.Done()
		client.RunKeepalive(ctx, func(err error) {
			if ctx.Err() == nil {
				log.Printf("Argon4: keepalive остановлен: %v", err)
			}
		})
	}()
	go func() {
		defer bgWG.Done()
		client.RunPLPMTUDMonitor(ctx)
	}()

	var runErr error
	select {
	case <-ctx.Done():
		log.Print("Argon4: остановка")
	case runErr = <-errCh:
		if runErr != nil && ctx.Err() == nil {
			runErr = fmt.Errorf("передача пакетов: %w", runErr)
		}
	}
	stop()
	_ = client.Close()
	_ = device.Close()
	bgWG.Wait()
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
	return tunToArgonWithMTU(device, client, nil)
}

func tunToArgonWithMTU(device tun.Device, client packetClient, routerIP net.IP) error {
	type mtuProvider interface {
		EffectiveTunMTU() int
	}
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
		pkt := buffer[tunOffset : tunOffset+sizes[0]]
		if err := client.WritePacket(pkt); err != nil {
			if errors.Is(err, argon4.ErrMTUExceeded) {
				nextMTU := argon4.MinPathMTU - argon4.OverheadV5
				if mp, ok := client.(mtuProvider); ok && mp.EffectiveTunMTU() > 0 {
					nextMTU = mp.EffectiveTunMTU()
				}
				if icmp, ok := argon4.BuildIPv4FragNeededPacket(pkt, routerIP, nextMTU); ok {
					out := make([]byte, tunOffset+len(icmp))
					copy(out[tunOffset:], icmp)
					_, _ = device.Write([][]byte{out}, tunOffset)
				}
				continue
			}
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
