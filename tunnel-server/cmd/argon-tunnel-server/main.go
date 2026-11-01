package main

import (
	"flag"
	"fmt"
	"log"
	"net"
	"os"
	"os/signal"
	"strconv"
	"strings"
	"syscall"

	"github.com/example/contactsync/tunnel-server/internal/tunnel"
)

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

func envIntOr(key string, fallback int) int {
	if v := os.Getenv(key); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return fallback
}

func main() {
	var (
		listenIface = flag.String("interface", envOr("ARGON_LISTEN_INTERFACE", ""), "Сетевой интерфейс Ubuntu для приёма зашифрованных пакетов (например, eth0, ens3; пусто — все)")
		listenAddr  = flag.String("listen", envOr("ARGON_LISTEN_ADDR", "0.0.0.0:51820"), "UDP-адрес и порт приёма пакетов")
		tunName     = flag.String("tun", envOr("ARGON_TUN_NAME", "argon0"), "Имя создаваемого или оборачиваемого TUN-интерфейса на Ubuntu")
		subnetCIDR  = flag.String("subnet", envOr("ARGON_SUBNET", "10.44.0.1/24"), "Виртуальный IP/CIDR туннеля на Ubuntu")
		outIface    = flag.String("out-interface", envOr("ARGON_OUT_INTERFACE", ""), "Существующий сетевой интерфейс Ubuntu для оборачивания/NAT (например, eth0)")
		backendDest = flag.String("backend", envOr("ARGON_BACKEND_TARGET", ""), "Целевой хост:порт по умолчанию для проброса TCP-обращений к виртуальному IP шлюза (например, app:80)")
		enableNAT   = flag.Bool("nat", envOr("ARGON_ENABLE_NAT", "1") == "1", "Автоматически включать IPv4 forwarding и правила iptables MASQUERADE")
		noTun       = flag.Bool("no-tun", envOr("ARGON_NO_TUN", "0") == "1", "Запуск без открытия /dev/net/tun (для тестов без прав root)")
		masterPass  = flag.String("password", envOr("ARGON_MASTER_PASSWORD", ""), "Мастер-пароль для вывода ключа Argon2id")
		argonTime   = flag.Int("argon-time", envIntOr("ARGON_TIME", int(tunnel.DefaultArgonTime)), "Число итераций Argon2id (time cost)")
		argonMem    = flag.Int("argon-memory", envIntOr("ARGON_MEMORY_KIB", int(tunnel.DefaultArgonMemory)), "Объём памяти Argon2id в КиБ")
		argonLanes  = flag.Int("argon-parallelism", envIntOr("ARGON_PARALLELISM", int(tunnel.DefaultArgonThreads)), "Параллелизм Argon2id (число потоков)")
		mtu         = flag.Int("mtu", envIntOr("ARGON_MTU", 1380), "MTU виртуального интерфейса TUN")
		dnsList     = flag.String("dns", envOr("ARGON_DNS", "1.1.1.1,8.8.8.8"), "DNS-серверы через запятую для передачи клиентам")
		routesList  = flag.String("routes", envOr("ARGON_ROUTES", "0.0.0.0/0"), "Маршруты CIDR через запятую для передачи клиентам")
	)
	flag.Parse()

	if *masterPass == "" {
		log.Fatal("Не задан мастер-пароль: укажите флаг -password или переменную окружения ARGON_MASTER_PASSWORD")
	}

	var tunDev *tunnel.TunDevice
	var err error
	if !*noTun {
		tunDev, err = tunnel.OpenTun(*tunName)
		if err != nil {
			log.Fatalf("Не удалось открыть TUN-интерфейс %q: %v (запустите от root или с CAP_NET_ADMIN на Ubuntu)", *tunName, err)
		}
		serverIP := strings.Split(*subnetCIDR, "/")[0]
		resolvedBackend := resolveBackend(*backendDest)
		if err := tunnel.ConfigureInterface(tunDev.Name(), *subnetCIDR, serverIP, *mtu, *outIface, *enableNAT, resolvedBackend); err != nil {
			log.Fatalf("Не удалось настроить TUN-интерфейс %q: %v", tunDev.Name(), err)
		}
		fmt.Printf("[argon4-server] TUN-интерфейс %s настроен (%s, mtu=%d, nat=%v, out=%q, backend=%q)\n",
			tunDev.Name(), *subnetCIDR, *mtu, *enableNAT, *outIface, resolvedBackend)
	}

	cfg := tunnel.ServerConfig{
		ListenInterface: *listenIface,
		ListenAddr:      *listenAddr,
		TunName:         *tunName,
		SubnetCIDR:      *subnetCIDR,
		OutInterface:    *outIface,
		EnableNAT:       *enableNAT,
		MasterPassword:  *masterPass,
		ArgonParams: tunnel.ArgonParams{
			Time:    uint32(*argonTime),
			Memory:  uint32(*argonMem),
			Threads: uint8(*argonLanes),
		},
		MTU:    *mtu,
		DNS:    splitTrim(*dnsList),
		Routes: splitTrim(*routesList),
	}

	var rwc = func() *tunnel.TunDevice { return tunDev }()
	var srv *tunnel.Server
	if rwc != nil {
		srv, err = tunnel.NewServer(cfg, rwc)
	} else {
		srv, err = tunnel.NewServer(cfg, nil)
	}
	if err != nil {
		log.Fatalf("Failed to create server: %v", err)
	}

	if err := srv.StartUDP(); err != nil {
		log.Fatalf("Failed to start UDP listener: %v", err)
	}

	sigCh := make(chan os.Signal, 1)
	signal.Notify(sigCh, syscall.SIGINT, syscall.SIGTERM)
	go func() {
		<-sigCh
		log.Println("[argon4-server] shutting down...")
		_ = srv.Close()
		os.Exit(0)
	}()

	if err := srv.Run(); err != nil {
		log.Fatalf("Server stopped: %v", err)
	}
}

func splitTrim(s string) []string {
	var out []string
	for _, p := range strings.Split(s, ",") {
		p = strings.TrimSpace(p)
		if p != "" {
			out = append(out, p)
		}
	}
	return out
}

func resolveBackend(target string) string {
	if target == "" {
		return ""
	}
	host, port, err := net.SplitHostPort(target)
	if err != nil {
		return target
	}
	ips, err := net.LookupIP(host)
	if err != nil {
		return target
	}
	for _, ip := range ips {
		if ip4 := ip.To4(); ip4 != nil {
			return net.JoinHostPort(ip4.String(), port)
		}
	}
	return target
}
