package tunnel

import (
	"bytes"
	"encoding/binary"
	"errors"
	"fmt"
	"net"
	"strconv"
	"strings"
	"syscall"
)

const (
	// KeepalivePMTUProbePrefix — клиентский контрольный запрос проверки внешнего размера кадра ("pmtu:<outerBytes>").
	KeepalivePMTUProbePrefix = "pmtu:"
	// KeepalivePMTUAckPrefix — подтверждение сервера с доставленным размером кадра ("pmtu-ack:<outerBytes>").
	KeepalivePMTUAckPrefix = "pmtu-ack:"
	// KeepalivePMTULimitPrefix — серверное уведомление об уменьшении лимита внешнего кадра ("pmtu-limit:<maxOuterBytes>").
	KeepalivePMTULimitPrefix = "pmtu-limit:"

	// MinTransitStepMTU — минимальный шаг уменьшения MTU при срабатывании EMSGSIZE без точного значения от ядра.
	pmtuFallbackStep = 64
)

// ParseKeepalivePMTUCommand разбирает управляющую полезную нагрузку FrameTypeKeepalive для автоподстройки MTU.
func ParseKeepalivePMTUCommand(plaintext []byte) (kind string, value int, ok bool) {
	s := strings.TrimSpace(string(plaintext))
	switch {
	case strings.HasPrefix(s, KeepalivePMTUProbePrefix):
		n, err := strconv.Atoi(strings.TrimPrefix(s, KeepalivePMTUProbePrefix))
		if err != nil || n <= 0 {
			return "", 0, false
		}
		return "probe", n, true
	case strings.HasPrefix(s, KeepalivePMTUAckPrefix):
		n, err := strconv.Atoi(strings.TrimPrefix(s, KeepalivePMTUAckPrefix))
		if err != nil || n <= 0 {
			return "", 0, false
		}
		return "ack", n, true
	case strings.HasPrefix(s, KeepalivePMTULimitPrefix):
		n, err := strconv.Atoi(strings.TrimPrefix(s, KeepalivePMTULimitPrefix))
		if err != nil || n <= 0 {
			return "", 0, false
		}
		return "limit", n, true
	default:
		return "", 0, false
	}
}

// ClampPathMTUValue нормализует внешний path_mtu в допустимый диапазон [MinPathMTU..MaxPathMTU].
func ClampPathMTUValue(pathMTU int) int {
	if pathMTU <= 0 {
		return 0
	}
	if pathMTU < MinPathMTU {
		return MinPathMTU
	}
	if pathMTU > MaxPathMTU {
		return MaxPathMTU
	}
	return pathMTU
}

// PathMTUFromMaxOuter переводит максимальный размер UDP-payload в IPv4 path MTU.
func PathMTUFromMaxOuter(maxOuter int) int {
	if maxOuter <= 0 {
		return DefaultPathMTU
	}
	return ClampPathMTUValue(maxOuter + IPv4UDPOverhead)
}

// MaxInnerPayloadForOuter возвращает предел полезной нагрузки (в байтах) внутри кадра Argon4 указанной длины.
func MaxInnerPayloadForOuter(maxOuter int, version byte) int {
	if maxOuter <= 0 {
		maxOuter = DefaultMaxOuterUDPSize
	}
	overhead := OverheadV5
	if version == ProtocolVersionV4 {
		overhead = OverheadV4
	}
	if maxOuter <= overhead {
		return 0
	}
	return maxOuter - overhead
}

// ComputeNestedPathMTU вычисляет оптимальный path_mtu для вложенного UDP-транспорта поверх транзита Argon4.
func ComputeNestedPathMTU(outerPathMTU int, version byte) int {
	if outerPathMTU <= 0 {
		outerPathMTU = DefaultPathMTU
	}
	overhead := OverheadV5
	if version == ProtocolVersionV4 {
		overhead = OverheadV4
	}
	// Резервируем место под минимальный случайный padding (>= 14 байт) и заголовок кадра Argon4.
	budget := outerPathMTU - (overhead + 14)
	return ClampPathMTUValue(budget)
}

// ExactPadLenForOuter вычисляет фиксированную длину padding v5 для достижения точного внешнего размера кадра.
func ExactPadLenForOuter(payloadLen, targetOuter int, version byte) (int, error) {
	if version != ProtocolVersionV5 {
		return 0, fmt.Errorf("точный padding зонда поддерживается только в v5")
	}
	padLen := targetOuter - OverheadV5 - payloadLen
	if padLen < 0 {
		return 0, fmt.Errorf("%w: полезная нагрузка %d не помещается в %d байт", ErrMTUExceeded, payloadLen, targetOuter)
	}
	return padLen, nil
}

// isMsgSizeError распознаёт системную ошибку превышения MTU (EMSGSIZE) на Linux, Android и macOS.
func isMsgSizeError(err error) bool {
	if err == nil {
		return false
	}
	if errors.Is(err, syscall.EMSGSIZE) {
		return true
	}
	msg := strings.ToLower(err.Error())
	return strings.Contains(msg, "message too long") || strings.Contains(msg, "emsgsize")
}

// OutgoingInterfaceMTUForAddr определяет локальный MTU исходящего интерфейса для указанного адреса назначения.
// Возвращает 0, если определить интерфейс не удалось (например, на ограниченных окружениях).
func OutgoingInterfaceMTUForAddr(remoteAddr string) int {
	probeConn, err := net.Dial("udp4", remoteAddr)
	if err != nil {
		return 0
	}
	defer probeConn.Close()
	return InterfaceMTUByLocalAddr(probeConn.LocalAddr())
}

// InterfaceMTUByLocalAddr возвращает MTU локального сетевого интерфейса, которому принадлежит addr.
func InterfaceMTUByLocalAddr(localAddr net.Addr) int {
	var localIP net.IP
	switch v := localAddr.(type) {
	case *net.UDPAddr:
		localIP = v.IP
	case *net.TCPAddr:
		localIP = v.IP
	case *net.IPAddr:
		localIP = v.IP
	}
	if localIP == nil || localIP.IsUnspecified() {
		return 0
	}
	ifaces, err := net.Interfaces()
	if err != nil {
		return 0
	}
	for _, iface := range ifaces {
		if iface.MTU < MinPathMTU || iface.MTU > MaxPathMTU {
			continue
		}
		addrs, err := iface.Addrs()
		if err != nil {
			continue
		}
		for _, a := range addrs {
			var ip net.IP
			switch v := a.(type) {
			case *net.IPNet:
				ip = v.IP
			case *net.IPAddr:
				ip = v.IP
			}
			if ip != nil && ip.Equal(localIP) {
				return iface.MTU
			}
		}
	}
	return 0
}

// BuildIPv4FragNeededPacket формирует корректный IPv4 ICMP Type 3 Code 4 (Fragmentation Needed) для TUN/стека ОС,
// если исходящий IPv4-пакет с флагом DF не помещается в адаптированный MTU туннеля.
func BuildIPv4FragNeededPacket(origIPv4 []byte, routerIP net.IP, nextHopMTU int) ([]byte, bool) {
	if len(origIPv4) < 20 || (origIPv4[0]>>4) != 4 {
		return nil, false
	}
	// Проверяем флаг DF (bit 14 = 0x40 в байте 6 IPv4).
	if origIPv4[6]&0x40 == 0 {
		return nil, false
	}
	ihl := int(origIPv4[0]&0x0f) * 4
	if ihl < 20 || len(origIPv4) < ihl {
		return nil, false
	}
	if origIPv4[9] == 1 { // Не шлём ICMP об ошибке в ответ на ICMP (RFC 1122).
		return nil, false
	}
	if nextHopMTU < 68 {
		nextHopMTU = 68
	}
	sampleLen := ihl + 8
	if sampleLen > len(origIPv4) {
		sampleLen = len(origIPv4)
	}

	srcOrig := origIPv4[12:16]
	srcRouter := routerIP.To4()
	if srcRouter == nil || bytes.Equal(srcRouter, net.IPv4zero.To4()) {
		srcRouter = origIPv4[16:20]
	}

	icmpLen := 8 + sampleLen
	totalLen := 20 + icmpLen
	pkt := make([]byte, totalLen)
	pkt[0] = 0x45
	pkt[1] = 0xc0
	binary.BigEndian.PutUint16(pkt[2:4], uint16(totalLen))
	binary.BigEndian.PutUint16(pkt[4:6], 0)
	pkt[6] = 0
	pkt[7] = 0
	pkt[8] = 64
	pkt[9] = 1
	copy(pkt[12:16], srcRouter)
	copy(pkt[16:20], srcOrig)
	binary.BigEndian.PutUint16(pkt[10:12], ipChecksum(pkt[:20]))

	pkt[20] = 3 // Destination Unreachable
	pkt[21] = 4 // Fragmentation Needed and DF was Set
	binary.BigEndian.PutUint16(pkt[24:26], 0)
	binary.BigEndian.PutUint16(pkt[26:28], uint16(nextHopMTU))
	copy(pkt[28:], origIPv4[:sampleLen])
	binary.BigEndian.PutUint16(pkt[22:24], ipChecksum(pkt[20:]))
	return pkt, true
}

func ipChecksum(data []byte) uint16 {
	var sum uint32
	for i := 0; i+1 < len(data); i += 2 {
		sum += uint32(binary.BigEndian.Uint16(data[i : i+2]))
	}
	if len(data)%2 == 1 {
		sum += uint32(data[len(data)-1]) << 8
	}
	for (sum >> 16) != 0 {
		sum = (sum & 0xffff) + (sum >> 16)
	}
	return ^uint16(sum)
}
