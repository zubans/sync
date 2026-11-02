// Минимальный Argon4 UDP-сервер для сквозных Kotlin/CEP ↔ Go тестов.
// Системный TUN не создаётся; опциональный echo-TUN проверяет data-кадры без root.
package main

import (
	"encoding/binary"
	"flag"
	"io"
	"log"
	"net"
	"os"
	"sync"

	"github.com/example/contactsync/tunnel-server/internal/tunnel"
)

type echoTun struct {
	packets chan []byte
	done    chan struct{}
	once    sync.Once
}

func newEchoTun() *echoTun {
	return &echoTun{packets: make(chan []byte, 16), done: make(chan struct{})}
}

func (t *echoTun) Read(p []byte) (int, error) {
	select {
	case packet := <-t.packets:
		if len(p) < len(packet) {
			return 0, io.ErrShortBuffer
		}
		return copy(p, packet), nil
	case <-t.done:
		return 0, io.EOF
	}
}

func (t *echoTun) Write(p []byte) (int, error) {
	packet := append([]byte(nil), p...)
	if len(packet) >= 20 && packet[0]>>4 == 4 {
		var source, destination [4]byte
		copy(source[:], packet[12:16])
		copy(destination[:], packet[16:20])
		copy(packet[12:16], destination[:])
		copy(packet[16:20], source[:])
		headerLength := int(packet[0]&0x0f) * 4
		if headerLength >= 20 && len(packet) >= headerLength {
			packet[10], packet[11] = 0, 0
			var sum uint32
			for i := 0; i < headerLength; i += 2 {
				sum += uint32(binary.BigEndian.Uint16(packet[i : i+2]))
			}
			for sum>>16 != 0 {
				sum = (sum & 0xffff) + (sum >> 16)
			}
			binary.BigEndian.PutUint16(packet[10:12], ^uint16(sum))
			if (packet[9] == 6 || packet[9] == 17) && len(packet) >= headerLength+4 {
				sourcePort := binary.BigEndian.Uint16(packet[headerLength : headerLength+2])
				destinationPort := binary.BigEndian.Uint16(packet[headerLength+2 : headerLength+4])
				binary.BigEndian.PutUint16(packet[headerLength:headerLength+2], destinationPort)
				binary.BigEndian.PutUint16(packet[headerLength+2:headerLength+4], sourcePort)
			}
		}
	}
	select {
	case t.packets <- packet:
		return len(p), nil
	case <-t.done:
		return 0, io.EOF
	}
}

func (t *echoTun) Close() error {
	t.once.Do(func() { close(t.done) })
	return nil
}

func main() {
	listen := flag.String("listen", "127.0.0.1:0", "адрес UDP listener")
	password := flag.String("password", "", "мастер-пароль тестового сервера")
	addrFile := flag.String("addr-file", "", "файл, куда записать фактический адрес listener")
	dropResponses := flag.Int("drop-responses", 0, "число первых ответов, которые нужно потерять")
	echoData := flag.Bool("echo-data", false, "возвращать data-кадры с переставленными IPv4-адресами")
	flag.Parse()
	if *password == "" || *addrFile == "" {
		log.Fatal("flags -password and -addr-file are required")
	}

	var tunDevice io.ReadWriteCloser
	var echo *echoTun
	if *echoData {
		echo = newEchoTun()
		tunDevice = echo
		defer echo.Close()
	}
	srv, err := tunnel.NewServer(tunnel.ServerConfig{
		SubnetCIDR:     "10.44.0.1/24",
		MasterPassword: *password,
		ArgonParams:    tunnel.DefaultParams,
		MTU:            1380,
		DNS:            []string{"1.1.1.1"},
		Routes:         []string{"0.0.0.0/0"},
	}, tunDevice)
	if err != nil {
		log.Fatal(err)
	}
	defer srv.Close()

	udpAddr, err := net.ResolveUDPAddr("udp4", *listen)
	if err != nil {
		log.Fatal(err)
	}
	conn, err := net.ListenUDP("udp4", udpAddr)
	if err != nil {
		log.Fatal(err)
	}
	defer conn.Close()
	if err := os.WriteFile(*addrFile, []byte(conn.LocalAddr().String()), 0o600); err != nil {
		log.Fatal(err)
	}

	if echo != nil {
		go func() {
			buffer := make([]byte, 65535)
			for {
				n, err := echo.Read(buffer)
				if err != nil {
					return
				}
				response, remoteAddr := srv.SealTunPacket(buffer[:n])
				if response != nil && remoteAddr != nil {
					_, _ = conn.WriteToUDP(response, remoteAddr)
				}
			}
		}()
	}

	buf := make([]byte, 65535)
	for {
		n, remoteAddr, err := conn.ReadFromUDP(buf)
		if err != nil {
			log.Fatal(err)
		}
		response := srv.HandlePacket(buf[:n], remoteAddr)
		if response == nil {
			continue
		}
		if *dropResponses > 0 {
			*dropResponses--
			continue
		}
		if _, err := conn.WriteToUDP(response, remoteAddr); err != nil {
			log.Printf("write response: %v", err)
		}
	}
}
