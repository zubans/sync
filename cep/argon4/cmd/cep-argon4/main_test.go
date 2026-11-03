package main

import (
	"errors"
	"io"
	"os"
	"testing"

	"github.com/sagernet/wireguard-go/tun"
)

var errTestStop = errors.New("стоп после первого пакета")

type fakePacketClient struct {
	readPacket []byte
	written    []byte
}

func (c *fakePacketClient) WritePacket(packet []byte) error {
	c.written = append([]byte(nil), packet...)
	return errTestStop
}

func (c *fakePacketClient) ReadPacket() ([]byte, error) {
	return append([]byte(nil), c.readPacket...), nil
}

type fakeTUN struct {
	readPacket []byte
	written    []byte
}

func (d *fakeTUN) Read(buffers [][]byte, sizes []int, offset int) (int, error) {
	if offset != tunOffset {
		return 0, errors.New("неверное смещение чтения")
	}
	if len(buffers) != 1 || len(sizes) != 1 || len(buffers[0]) < offset+len(d.readPacket) {
		return 0, io.ErrShortBuffer
	}
	copy(buffers[0][offset:], d.readPacket)
	sizes[0] = len(d.readPacket)
	return 1, nil
}

func (d *fakeTUN) Write(buffers [][]byte, offset int) (int, error) {
	if offset != tunOffset {
		return 0, errors.New("неверное смещение записи")
	}
	if len(buffers) != 1 || len(buffers[0]) < offset {
		return 0, io.ErrShortBuffer
	}
	d.written = append([]byte(nil), buffers[0][offset:]...)
	return 1, errTestStop
}

func (d *fakeTUN) File() *os.File        { return nil }
func (d *fakeTUN) MTU() (int, error)     { return 1380, nil }
func (d *fakeTUN) Name() (string, error) { return "utun-test", nil }
func (d *fakeTUN) Events() <-chan tun.Event {
	return nil
}
func (d *fakeTUN) Close() error   { return nil }
func (d *fakeTUN) BatchSize() int { return 1 }

func TestTunToArgonUsesPlatformOffset(t *testing.T) {
	packet := []byte{0x45, 0, 0, 20, 1, 2, 3, 4}
	device := &fakeTUN{readPacket: packet}
	client := &fakePacketClient{}

	if err := tunToArgon(device, client); !errors.Is(err, errTestStop) {
		t.Fatalf("tunToArgon: %v", err)
	}
	if string(client.written) != string(packet) {
		t.Fatalf("пакет изменился: got %v, want %v", client.written, packet)
	}
}

func TestArgonToTunUsesPlatformOffset(t *testing.T) {
	packet := []byte{0x45, 0, 0, 20, 5, 6, 7, 8}
	device := &fakeTUN{}
	client := &fakePacketClient{readPacket: packet}

	if err := argonToTun(device, client); !errors.Is(err, errTestStop) {
		t.Fatalf("argonToTun: %v", err)
	}
	if string(device.written) != string(packet) {
		t.Fatalf("пакет изменился: got %v, want %v", device.written, packet)
	}
}
