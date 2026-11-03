package cepcore

import (
	"context"
	"errors"
	"fmt"
	"net"
	"os"
	"sync"
	"time"

	box "github.com/sagernet/sing-box"
	"github.com/sagernet/sing-box/adapter"
	"github.com/sagernet/sing-box/adapter/outbound"
	"github.com/sagernet/sing-box/include"
	"github.com/sagernet/sing-box/log"
	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
	"github.com/sagernet/sing/service"
)

// deadlineType — outbound-обёртка над узлом цепочки. OpenConnect ставит таймауты на соединение
// (SetDeadline на время рукопожатия), а соединения VLESS поверх gRPC их не поддерживают и отвечают
// invalid argument. Обёртка передаёт соединение как есть (RemoteAddr — настоящий сервер, по нему
// OpenConnect открывает туннель) и эмулирует таймаут: по его истечении соединение закрывается.
const deadlineType = "cep-deadline"

type deadlineOptions struct {
	Detour string `json:"detour"`
}

// engineContext — контекст sing-box со всеми протоколами сборки и обёрткой cep-deadline.
func engineContext(ctx context.Context) context.Context {
	outbounds := include.OutboundRegistry()
	outbound.Register[deadlineOptions](outbounds, deadlineType, newDeadlineOutbound)
	return box.Context(ctx, include.InboundRegistry(), outbounds, include.EndpointRegistry(),
		include.DNSTransportRegistry(), include.ServiceRegistry(), include.CertificateProviderRegistry())
}

type deadlineOutbound struct {
	outbound.Adapter
	ctx    context.Context
	detour string
}

func newDeadlineOutbound(ctx context.Context, _ adapter.Router, _ log.ContextLogger, tag string, options deadlineOptions) (adapter.Outbound, error) {
	if options.Detour == "" {
		return nil, fmt.Errorf("%s: не указан detour", deadlineType)
	}
	return &deadlineOutbound{
		Adapter: outbound.NewAdapter(deadlineType, tag, []string{N.NetworkTCP, N.NetworkUDP}, []string{options.Detour}),
		ctx:     ctx,
		detour:  options.Detour,
	}, nil
}

func (o *deadlineOutbound) next() (adapter.Outbound, error) {
	manager := service.FromContext[adapter.OutboundManager](o.ctx)
	if manager == nil {
		return nil, fmt.Errorf("%s: нет менеджера outbound'ов", deadlineType)
	}
	next, ok := manager.Outbound(o.detour)
	if !ok {
		return nil, fmt.Errorf("%s: нет outbound %s", deadlineType, o.detour)
	}
	return next, nil
}

func (o *deadlineOutbound) DialContext(ctx context.Context, network string, destination M.Socksaddr) (net.Conn, error) {
	next, err := o.next()
	if err != nil {
		return nil, err
	}
	conn, err := next.DialContext(ctx, network, destination)
	if err != nil {
		return nil, err
	}
	return &deadlineConn{Conn: conn}, nil
}

func (o *deadlineOutbound) ListenPacket(ctx context.Context, destination M.Socksaddr) (net.PacketConn, error) {
	next, err := o.next()
	if err != nil {
		return nil, err
	}
	return next.ListenPacket(ctx, destination)
}

// deadlineConn: если соединение само поддерживает таймауты — работают они; иначе таймаут
// эмулируется таймером, который закрывает соединение. Нулевое время снимает таймер.
type deadlineConn struct {
	net.Conn
	access sync.Mutex
	timer  *time.Timer
}

func (c *deadlineConn) SetDeadline(t time.Time) error      { return c.set(t, c.Conn.SetDeadline) }
func (c *deadlineConn) SetReadDeadline(t time.Time) error  { return c.set(t, c.Conn.SetReadDeadline) }
func (c *deadlineConn) SetWriteDeadline(t time.Time) error { return c.set(t, c.Conn.SetWriteDeadline) }

func (c *deadlineConn) set(t time.Time, native func(time.Time) error) error {
	err := native(t)
	if err == nil || !errors.Is(err, os.ErrInvalid) {
		return err
	}
	c.access.Lock()
	defer c.access.Unlock()
	if c.timer != nil {
		c.timer.Stop()
		c.timer = nil
	}
	if !t.IsZero() {
		c.timer = time.AfterFunc(time.Until(t), func() { c.Conn.Close() })
	}
	return nil
}

func (c *deadlineConn) Close() error {
	c.access.Lock()
	if c.timer != nil {
		c.timer.Stop()
		c.timer = nil
	}
	c.access.Unlock()
	return c.Conn.Close()
}
