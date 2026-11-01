//go:build !linux

package tunnel

import (
	"errors"
	"io"
)

// TunDevice — заглушка виртуального TUN-интерфейса для сборок и тестов вне Linux.
type TunDevice struct {
	name string
	r    *io.PipeReader
	w    *io.PipeWriter
}

func OpenTun(ifName string) (*TunDevice, error) {
	return nil, errors.New("системный /dev/net/tun поддерживается только в Linux (Ubuntu)")
}

func NewMockTun(ifName string) (*TunDevice, *io.PipeReader, *io.PipeWriter) {
	tunInR, tunInW := io.Pipe()
	tunOutR, tunOutW := io.Pipe()
	return &TunDevice{name: ifName, r: tunInR, w: tunOutW}, tunOutR, tunInW
}

func (t *TunDevice) Name() string { return t.name }
func (t *TunDevice) Read(p []byte) (int, error) {
	if t.r == nil {
		return 0, io.EOF
	}
	return t.r.Read(p)
}
func (t *TunDevice) Write(p []byte) (int, error) {
	if t.w == nil {
		return len(p), nil
	}
	return t.w.Write(p)
}
func (t *TunDevice) Close() error {
	if t.r != nil {
		_ = t.r.Close()
	}
	if t.w != nil {
		_ = t.w.Close()
	}
	return nil
}

func ConfigureInterface(tunName, cidr, serverIP string, mtu int, outInterface string, enableNAT bool, backendTarget string) error {
	return nil
}
