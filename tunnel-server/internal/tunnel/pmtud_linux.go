//go:build linux

package tunnel

import (
	"net"
	"syscall"
)

const (
	linuxIPMTUDiscover = syscall.IP_MTU_DISCOVER // 10
	linuxIPPMTUDiscDo  = syscall.IP_PMTUDISC_DO  // 2
	linuxIPPMTUDiscOff = syscall.IP_PMTUDISC_DONT // 0
	linuxIPMTU         = syscall.IP_MTU          // 14
	linuxTCPMaxSeg     = syscall.TCP_MAXSEG      // 2
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
		if err := syscall.SetsockoptInt(int(fd), syscall.IPPROTO_IP, linuxIPMTUDiscover, linuxIPPMTUDiscDo); err == nil {
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
		_ = syscall.SetsockoptInt(int(fd), syscall.IPPROTO_IP, linuxIPMTUDiscover, linuxIPPMTUDiscOff)
	})
}

func queryKernelConnMTU(conn net.Conn) (int, bool) {
	sc, ok := conn.(syscall.Conn)
	if !ok {
		return 0, false
	}
	raw, err := sc.SyscallConn()
	if err != nil {
		return 0, false
	}
	var mtu int
	var gerr error
	if err := raw.Control(func(fd uintptr) {
		mtu, gerr = syscall.GetsockoptInt(int(fd), syscall.IPPROTO_IP, linuxIPMTU)
	}); err != nil || gerr != nil {
		return 0, false
	}
	if mtu < MinPathMTU || mtu > MaxPathMTU {
		return 0, false
	}
	return mtu, true
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
		mss, gerr = syscall.GetsockoptInt(int(fd), syscall.IPPROTO_TCP, linuxTCPMaxSeg)
	}); err != nil || gerr != nil {
		return 0, false
	}
	if mss < 400 || mss > MaxPathMTU {
		return 0, false
	}
	return mss, true
}
