//go:build !linux && !darwin

package tunnel

import "net"

func setUDPSocketDFConn(_ net.Conn) bool { return false }

func clearUDPSocketDFConn(_ net.Conn) {}

func queryKernelConnMTU(_ net.Conn) (int, bool) { return 0, false }

func queryTCPConnMSS(_ net.Conn) (int, bool) { return 0, false }
