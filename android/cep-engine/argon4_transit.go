package cepcore

import (
	"context"
	"io"
	"net"
	"sync"
	"time"

	"contactsync/cep-engine/argon4"
	E "github.com/sagernet/sing/common/exceptions"
	M "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
)

func (e *argon4Endpoint) transitTarget(destination M.Socksaddr) string {
	if e.options.Target != "" {
		return e.options.Target
	}
	return destination.String()
}

func (e *argon4Endpoint) transitConfig(target, proto string) argon4.ClientConfig {
	return argon4.ClientConfig{
		Server:             e.options.Server,
		MasterPassword:     e.options.Password,
		ClientID:           e.options.ClientID,
		ClientSecret:       e.options.ClientSecret,
		Mode:               argon4.ModeTransit,
		Target:             target,
		Proto:              proto,
		ProtocolVersion:    e.options.ProtocolVersion,
		PaddingProfile:     e.options.PaddingProfile,
		FirstPacketProfile: e.options.FirstPacketProfile,
		PathMTU:            e.options.PathMTU,
	}
}

func (e *argon4Endpoint) dialTransit(ctx context.Context, network string, destination M.Socksaddr) (net.Conn, error) {
	target := e.transitTarget(destination)
	switch N.NetworkName(network) {
	case N.NetworkTCP:
		conn, _, err := argon4.DialTransitTCP(ctx, e.transitConfig(target, argon4.ProtoTCP), e.dialer)
		if err != nil {
			return nil, err
		}
		return conn, nil
	case N.NetworkUDP:
		client, remoteAddr, stopKeepalive, err := e.connectTransitUDP(ctx, target, destination)
		if err != nil {
			return nil, err
		}
		return &argonTransitUDPConn{
			client:        client,
			remoteAddr:    remoteAddr,
			stopKeepalive: stopKeepalive,
		}, nil
	default:
		return nil, E.New("Argon4 транзит: неподдерживаемая сеть ", network)
	}
}

func (e *argon4Endpoint) listenTransitPacket(ctx context.Context, destination M.Socksaddr) (net.PacketConn, error) {
	target := e.transitTarget(destination)
	client, remoteAddr, stopKeepalive, err := e.connectTransitUDP(ctx, target, destination)
	if err != nil {
		return nil, err
	}
	return &argonTransitUDPConn{
		client:        client,
		remoteAddr:    remoteAddr,
		stopKeepalive: stopKeepalive,
	}, nil
}

func (e *argon4Endpoint) connectTransitUDP(ctx context.Context, target string, destination M.Socksaddr) (*argon4.Client, *net.UDPAddr, func(), error) {
	client, err := argon4.NewClient(e.transitConfig(target, argon4.ProtoUDP), e.dialer)
	if err != nil {
		return nil, nil, nil, err
	}
	if err := client.Connect(ctx); err != nil {
		_ = client.Close()
		return nil, nil, nil, err
	}
	keepCtx, cancelKeepalive := context.WithCancel(e.loopContext)
	var wg sync.WaitGroup
	wg.Add(1)
	go func() {
		defer wg.Done()
		ticker := time.NewTicker(30 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-keepCtx.Done():
				return
			case <-ticker.C:
				if err := client.WriteKeepalive(); err != nil && keepCtx.Err() == nil {
					e.logger.Debug("Argon4 транзит keepalive: ", err)
				}
			}
		}
	}()
	stopKeepalive := func() {
		cancelKeepalive()
		wg.Wait()
	}
	return client, transitRemoteUDPAddr(destination, target), stopKeepalive, nil
}

func transitRemoteUDPAddr(destination M.Socksaddr, target string) *net.UDPAddr {
	if destination.IsIP() && destination.Port > 0 {
		return destination.UDPAddr()
	}
	if addr, err := net.ResolveUDPAddr("udp", target); err == nil {
		return addr
	}
	return &net.UDPAddr{IP: net.IPv4(127, 0, 0, 1), Port: 1}
}

// argonTransitUDPConn реализует одновременно net.Conn и net.PacketConn поверх UDP-сессии Argon4-транзита.
type argonTransitUDPConn struct {
	client        *argon4.Client
	remoteAddr    *net.UDPAddr
	stopKeepalive func()
	closeOnce     sync.Once
}

func (c *argonTransitUDPConn) Read(b []byte) (int, error) {
	n, _, err := c.ReadFrom(b)
	return n, err
}

func (c *argonTransitUDPConn) Write(b []byte) (int, error) {
	return c.WriteTo(b, c.remoteAddr)
}

func (c *argonTransitUDPConn) ReadFrom(p []byte) (int, net.Addr, error) {
	pkt, err := c.client.ReadPacket()
	if err != nil {
		return 0, nil, err
	}
	n := copy(p, pkt)
	if n < len(pkt) {
		return n, c.remoteAddr, io.ErrShortBuffer
	}
	return n, c.remoteAddr, nil
}

func (c *argonTransitUDPConn) WriteTo(p []byte, _ net.Addr) (int, error) {
	if err := c.client.WritePacket(p); err != nil {
		return 0, err
	}
	return len(p), nil
}

func (c *argonTransitUDPConn) Close() error {
	var err error
	c.closeOnce.Do(func() {
		err = c.client.Close()
		if c.stopKeepalive != nil {
			c.stopKeepalive()
		}
	})
	return err
}

func (c *argonTransitUDPConn) LocalAddr() net.Addr {
	if addr := c.client.LocalAddr(); addr != nil {
		return addr
	}
	return &net.UDPAddr{IP: net.IPv4zero, Port: 0}
}

func (c *argonTransitUDPConn) RemoteAddr() net.Addr {
	return c.remoteAddr
}

func (c *argonTransitUDPConn) SetDeadline(t time.Time) error {
	return c.client.SetDeadline(t)
}

func (c *argonTransitUDPConn) SetReadDeadline(t time.Time) error {
	return c.client.SetReadDeadline(t)
}

func (c *argonTransitUDPConn) SetWriteDeadline(t time.Time) error {
	return c.client.SetWriteDeadline(t)
}
