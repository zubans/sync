//go:build darwin

package tunnel

import (
	"net"
	"syscall"
)

const (
	// IP_DONTFRAG из <netinet/in.h> для macOS (Darwin).
	darwinIPDontFrag = 28
	darwinTCPMaxSeg  = 0x02
)

func setUDPSocketDFConn(conn net.Conn) bool {
	sc, ok := conn.(syscall.Conn)
	if !ok {
		return false
	}
	raw, err := sc.SyscallConn()
	if err != nil {
		return false
	}
	applied := false
	_ = raw.Control(func(fd uintptr) {
		if err := syscall.SetsockoptInt(int(fd), syscall.IPPROTO_IP, darwinIPDontFrag, 1); err == nil {
			applied = true
		}
	})
	return applied
}

func clearUDPSocketDFConn(conn net.Conn) {
	sc, ok := conn.(syscall.Conn)
	if !ok {
		return
	}
	raw, err := sc.SyscallConn()
	if err != nil {
		return
	}
	_ = raw.Control(func(fd uintptr) {
		_ = syscall.SetsockoptInt(int(fd), syscall.IPPROTO_IP, darwinIPDontFrag, 0)
	})
}

func queryKernelConnMTU(_ net.Conn) (int, bool) {
	return 0, false
}

func queryTCPConnMSS(conn net.Conn) (int, bool) {
	sc, ok := conn.(syscall.Conn)
	if !ok {
		return 0, false
	}
	raw, err := sc.SyscallConn()
	if err != nil {
		return 0, false
	}
	var mss int
	var gerr error
	if err := raw.Control(func(fd uintptr) {
		mss, gerr = syscall.GetsockoptInt(int(fd), syscall.IPPROTO_TCP, darwinTCPMaxSeg)
	}); err != nil || gerr != nil {
		return 0, false
	}
	if mss < 400 || mss > MaxPathMTU {
		return 0, false
	}
	return mss, true
}
