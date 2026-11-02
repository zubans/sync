package cepcore

import (
	"context"
	"fmt"
	"net"
	"net/netip"
	"sync"
	"sync/atomic"
	"time"

	"contactsync/cep-engine/argon4"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/adapter/endpoint"
	"github.com/sagernet/sing-box/common/dialer"
	C "github.com/sagernet/sing-box/constant"
	"github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	packetdevice "github.com/sagernet/sing-box/transport/openconnect"
	"github.com/sagernet/sing-tun"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	E "github.com/sagernet/sing/common/exceptions"
	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"github.com/sagernet/sing/service"
)

const argon4Type = "argon4"

func withArgon4Endpoint(registry *endpoint.Registry) *endpoint.Registry {
	endpoint.Register[argon4Options](registry, argon4Type, newArgon4Endpoint)
	return registry
}

type argon4Options struct {
	Server       string `json:"server"`
	Password     string `json:"password"`
	ClientID     string `json:"client_id"`
	ClientSecret string `json:"client_secret"`
	Detour       string `json:"detour"`
}

// argon4Endpoint передаёт сырые IPv4-пакеты Argon4 через packet-device sing-box.
// Наружу endpoint предоставляет обычные TCP/UDP Dialer, поэтому может быть как
// конечным узлом TUN, так и underlay для следующего узла цепочки.
type argon4Endpoint struct {
	endpoint.Adapter
	loopContext context.Context
	cancelLoop  context.CancelFunc
	dnsRouter   adapter.DNSRouter
	logger      log.ContextLogger
	device      packetdevice.Device
	client      *argon4.Client
	ready       atomic.Bool
	readGroup   sync.WaitGroup
}

func newArgon4Endpoint(ctx context.Context, _ adapter.Router, logger log.ContextLogger, tag string, options argon4Options) (adapter.Endpoint, error) {
	loopContext, cancelLoop := context.WithCancel(ctx)
	outboundDialer, err := dialer.NewWithOptions(dialer.Options{
		Context:   ctx,
		Options:   option.DialerOptions{Detour: options.Detour},
		NewDialer: true,
	})
	if err != nil {
		cancelLoop()
		return nil, err
	}
	client, err := argon4.NewClient(argon4.ClientConfig{
		Server:         options.Server,
		MasterPassword: options.Password,
		ClientID:       options.ClientID,
		ClientSecret:   options.ClientSecret,
	}, outboundDialer)
	if err != nil {
		cancelLoop()
		return nil, err
	}
	networkManager := service.FromContext[adapter.NetworkManager](ctx)
	device, err := packetdevice.NewDevice(packetdevice.DeviceOptions{
		Context:         loopContext,
		Logger:          logger,
		UDPTimeout:      C.UDPTimeout,
		ICMPTimeout:     C.ICMPTimeout,
		InterfaceFinder: networkManager.InterfaceFinder(),
		Name:            tag,
		MTU:             1380,
		Configuration: packetdevice.Configuration{
			MTU: 1380,
		},
	})
	if err != nil {
		cancelLoop()
		return nil, err
	}
	var dependencies []string
	if options.Detour != "" {
		dependencies = append(dependencies, options.Detour)
	}
	result := &argon4Endpoint{
		Adapter:     endpoint.NewAdapter(argon4Type, tag, []string{N.NetworkTCP, N.NetworkUDP}, dependencies),
		loopContext: loopContext,
		cancelLoop:  cancelLoop,
		dnsRouter:   service.FromContext[adapter.DNSRouter](ctx),
		logger:      logger,
		device:      device,
		client:      client,
	}
	device.SetPacketWriter(result.writePacketBuffers)
	return result, nil
}

func (e *argon4Endpoint) Start(stage adapter.StartStage, scope *adapter.Scope) error {
	switch stage {
	case adapter.StartStateInitialize:
		scope.Add(e.device.Close)
	case adapter.StartStatePostStart:
		if err := e.client.Connect(scope.Context()); err != nil {
			return err
		}
		scope.Add(func() error {
			e.ready.Store(false)
			e.cancelLoop()
			err := e.client.Close()
			e.readGroup.Wait()
			return err
		})
		response := e.client.Response()
		assignedIP := netip.MustParseAddr(response.AssignedIP)
		if err := e.device.UpdateConfiguration(packetdevice.Configuration{
			MTU:       uint32(response.MTU),
			Addresses: []netip.Prefix{netip.PrefixFrom(assignedIP, response.SubnetCidr)},
		}); err != nil {
			return fmt.Errorf("Argon4: настройка виртуального адреса: %w", err)
		}
		if err := e.device.Start(); err != nil {
			return fmt.Errorf("Argon4: запуск packet-device: %w", err)
		}
		e.ready.Store(true)
		e.logger.Info("Argon4: подключено, адрес ", assignedIP, "/", response.SubnetCidr)
		e.readGroup.Add(2)
		go func() {
			defer e.readGroup.Done()
			e.readLoop()
		}()
		go func() {
			defer e.readGroup.Done()
			e.keepaliveLoop()
		}()
	}
	return nil
}

func (e *argon4Endpoint) readLoop() {
	for {
		plaintext, err := e.client.ReadPacket()
		if err != nil {
			if e.loopContext.Err() == nil {
				e.ready.Store(false)
				e.logger.Error("Argon4: приём остановлен: ", err)
			}
			return
		}
		packetBuffer := buf.NewSize(len(plaintext))
		_, _ = packetBuffer.Write(plaintext)
		err = e.device.WriteInboundBuffers([]*buf.Buffer{packetBuffer})
		packetBuffer.Release()
		if err != nil && e.loopContext.Err() == nil {
			e.logger.Warn("Argon4: входящий пакет отброшен: ", err)
		}
	}
}

func (e *argon4Endpoint) keepaliveLoop() {
	ticker := time.NewTicker(30 * time.Second)
	defer ticker.Stop()
	for {
		select {
		case <-e.loopContext.Done():
			return
		case <-ticker.C:
			if err := e.client.WriteKeepalive(); err != nil && e.loopContext.Err() == nil {
				e.logger.Debug("Argon4: keepalive: ", err)
			}
		}
	}
}

func (e *argon4Endpoint) writePacketBuffers(packetBuffers []*buf.Buffer) error {
	defer buf.ReleaseMulti(packetBuffers)
	if !e.ready.Load() {
		return E.New("Argon4: endpoint ещё не готов")
	}
	for _, packetBuffer := range packetBuffers {
		if err := e.client.WritePacket(packetBuffer.Bytes()); err != nil {
			return err
		}
	}
	return nil
}

// WritePackets принимает пакеты от return-пути sing-box.
func (e *argon4Endpoint) WritePackets(packets [][]byte) error {
	if !e.ready.Load() {
		return E.New("Argon4: endpoint ещё не готов")
	}
	for _, packet := range packets {
		if err := e.client.WritePacket(packet); err != nil {
			return err
		}
	}
	return nil
}

func (e *argon4Endpoint) DialContext(ctx context.Context, network string, destination M.Socksaddr) (net.Conn, error) {
	if !e.ready.Load() {
		return nil, E.New("Argon4: endpoint ещё не готов")
	}
	if destination.IsDomain() {
		if e.dnsRouter == nil {
			return nil, E.New("Argon4: DNS router недоступен")
		}
		addresses, err := e.dnsRouter.Lookup(ctx, destination.Fqdn, adapter.DNSQueryOptions{})
		if err != nil {
			return nil, err
		}
		return N.DialSerial(ctx, e.device, network, destination, addresses)
	}
	if !destination.IsIP() {
		return nil, E.New("Argon4: неверный адрес назначения")
	}
	return e.device.DialContext(ctx, network, destination)
}

func (e *argon4Endpoint) ListenPacket(ctx context.Context, destination M.Socksaddr) (net.PacketConn, error) {
	if !e.ready.Load() {
		return nil, E.New("Argon4: endpoint ещё не готов")
	}
	if destination.IsDomain() {
		if e.dnsRouter == nil {
			return nil, E.New("Argon4: DNS router недоступен")
		}
		addresses, err := e.dnsRouter.Lookup(ctx, destination.Fqdn, adapter.DNSQueryOptions{})
		if err != nil {
			return nil, err
		}
		packetConn, destinationAddress, err := N.ListenSerial(ctx, e.device, destination, addresses)
		if err != nil {
			return nil, err
		}
		return bufio.NewNATPacketConn(bufio.NewPacketConn(packetConn), M.SocksaddrFrom(destinationAddress, destination.Port), destination), nil
	}
	if !destination.IsIPv4() {
		return nil, E.New("Argon4: поддерживается только IPv4")
	}
	return e.device.ListenPacket(ctx, destination)
}

var _ adapter.Endpoint = (*argon4Endpoint)(nil)
var _ tun.Port = (*argon4Endpoint)(nil)

// Port-интерфейс нужен sing-box для прямых return-путей; Argon4 использует
// тот же packet-device, что OpenConnect, поэтому делегируем ему эти операции.
func (e *argon4Endpoint) PortAddresses() (netip.Addr, netip.Addr) { return e.device.PortAddresses() }
func (e *argon4Endpoint) PortMTU() uint32                         { return e.device.PortMTU() }
func (e *argon4Endpoint) AttachReturn(returnPath tun.Return) error {
	return e.device.AttachReturn(returnPath)
}
func (e *argon4Endpoint) DetachReturn(returnPath tun.Return) error {
	return e.device.DetachReturn(returnPath)
}
