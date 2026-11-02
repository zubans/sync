package main

import (
	"bytes"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"flag"
	"fmt"
	"io"
	"log"
	"net"
	"net/http"
	"os"
	"sync/atomic"
	"time"

	"github.com/example/contactsync/tunnel-server/internal/tunnel"
)

func main() {
	var (
		serverAddr  = flag.String("server", envOr("ARGON_SERVER_ADDR", "tunnel:51820"), "UDP-адрес и порт сервера Argon4 на Ubuntu")
		bindIface   = flag.String("interface", envOr("ARGON_CLIENT_INTERFACE", ""), "Исходящий сетевой интерфейс клиента для отправки зашифрованных пакетов (например, eth0)")
		tunName     = flag.String("tun", envOr("ARGON_CLIENT_TUN", "argon-client0"), "Имя виртуального TUN-интерфейса на клиенте")
		masterPass  = flag.String("password", envOr("ARGON_MASTER_PASSWORD", ""), "Мастер-пароль для вывода ключа Argon2id")
		clientID    = flag.String("client-id", envOr("ARGON_CLIENT_ID", "docker-e2e-client"), "Идентификатор клиента")
		probeURL    = flag.String("probe-url", envOr("ARGON_PROBE_URL", ""), "HTTP URL для контрольного запроса через шифрованный TUN-интерфейс (например, http://10.44.0.1:80/admin/login)")
		sendPayload = flag.String("send-material", "", "Строка материала для одноразового шифрования и отправки через выбранный интерфейс")
	)
	flag.Parse()

	if *masterPass == "" {
		log.Fatal("Не задан мастер-пароль: укажите флаг -password или переменную окружения ARGON_MASTER_PASSWORD")
	}

	remoteUDP, err := net.ResolveUDPAddr("udp4", *serverAddr)
	if err != nil {
		log.Fatalf("Не удалось разрешить адрес сервера %q: %v", *serverAddr, err)
	}

	var localUDP *net.UDPAddr
	if *bindIface != "" {
		localUDP, err = tunnel.ResolveInterfaceUDPAddr(*bindIface, "0.0.0.0:0")
		if err != nil {
			log.Fatalf("Не удалось привязаться к интерфейсу клиента %q: %v", *bindIface, err)
		}
	}

	conn, err := net.DialUDP("udp4", localUDP, remoteUDP)
	if err != nil {
		log.Fatalf("DialUDP завершился с ошибкой: %v", err)
	}
	defer conn.Close()

	salt := make([]byte, tunnel.SaltSize)
	if _, err := rand.Read(salt); err != nil {
		log.Fatalf("rand.Read salt failed: %v", err)
	}
	psk := tunnel.DerivePSK(*masterPass, tunnel.DefaultParams)
	hsKey := tunnel.DeriveKey(*masterPass, salt, tunnel.DefaultParams)

	clientPriv, clientPub, err := tunnel.GenerateX25519Keypair()
	if err != nil {
		log.Fatalf("GenerateX25519Keypair failed: %v", err)
	}

	log.Printf("[argon4-client] привязан к %s (интерфейс=%q) -> отправка на %s", conn.LocalAddr(), *bindIface, remoteUDP)

	// 1. Выполнение зашифрованного рукопожатия (Handshake) с эфемерным X25519
	clientAuth := tunnel.ClientAuthToken(psk, *clientID, *clientID)
	hsReq := tunnel.HandshakeRequest{
		ClientID:   *clientID,
		ClientAuth: hex.EncodeToString(clientAuth),
		ClientPub:  hex.EncodeToString(clientPub),
		Apps:       []string{"com.example.contactsync", "kb", "cep"},
		Timestamp:  time.Now().Unix(),
	}
	reqBytes, _ := json.Marshal(hsReq)
	wireReq, err := tunnel.SealPacketWithSeq(tunnel.FrameTypeHandshakeReq, salt, psk, hsKey, 1, reqBytes)
	if err != nil {
		log.Fatalf("SealPacketWithSeq handshake failed: %v", err)
	}

	if _, err := conn.Write(wireReq); err != nil {
		log.Fatalf("Write handshake failed: %v", err)
	}

	buf := make([]byte, 65535)
	_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
	n, err := conn.Read(buf)
	if err != nil {
		log.Fatalf("Read handshake response failed: %v", err)
	}

	fType, _, _, plainResp, err := tunnel.OpenPacketWithKey(buf[:n], psk, hsKey)
	if err != nil || fType != tunnel.FrameTypeHandshakeResp {
		log.Fatalf("OpenPacketWithKey handshake response failed: type=%d err=%v", fType, err)
	}

	var hsResp tunnel.HandshakeResponse
	if err := json.Unmarshal(plainResp, &hsResp); err != nil {
		log.Fatalf("Unmarshal handshake response failed: %v", err)
	}
	srvPub, err := hex.DecodeString(hsResp.ServerPub)
	if err != nil || len(srvPub) != tunnel.X25519KeySize {
		log.Fatalf("Invalid server X25519 public key in handshake response")
	}
	sharedSecret, err := tunnel.ComputeX25519Shared(clientPriv, srvPub)
	for i := range clientPriv {
		clientPriv[i] = 0
	}
	if err != nil {
		log.Fatalf("ComputeX25519Shared failed: %v", err)
	}
	c2sKey, s2cKey := tunnel.DeriveSessionKeys(hsKey, sharedSecret, salt)

	log.Printf("[argon4-client] рукопожатие ОК: assignedIP=%s/%d serverIP=%s mtu=%d routes=%v",
		hsResp.AssignedIP, hsResp.SubnetCidr, hsResp.ServerIP, hsResp.MTU, hsResp.Routes)

	var c2sSeq atomic.Uint64
	var replay tunnel.ReplayWindow

	if *sendPayload != "" {
		pkt, err := tunnel.SealPacketWithSeq(tunnel.FrameTypeKeepalive, salt, psk, c2sKey, c2sSeq.Add(1), []byte(*sendPayload))
		if err != nil {
			log.Fatalf("SealPacketWithSeq material failed: %v", err)
		}
		if _, err := conn.Write(pkt); err != nil {
			log.Fatalf("Write material failed: %v", err)
		}
		_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
		n, err := conn.Read(buf)
		if err != nil {
			log.Fatalf("Read keepalive pong failed: %v", err)
		}
		_, _, seq, pong, err := tunnel.OpenPacketWithKey(buf[:n], psk, s2cKey)
		if err != nil || !replay.Accept(seq) {
			log.Fatalf("OpenPacketWithKey keepalive pong failed: %v", err)
		}
		log.Printf("[argon4-client] зашифрованный материал доставлен и подтверждён сервером: %q", string(pong))
	}

	if *probeURL == "" {
		return
	}

	// 2. Поднятие клиентского TUN-интерфейса для сквозной маршрутизации IP-пакетов в контейнере Linux/Ubuntu
	tunDev, err := tunnel.OpenTun(*tunName)
	if err != nil {
		log.Fatalf("OpenTun(%s) failed: %v", *tunName, err)
	}
	defer tunDev.Close()

	clientCIDR := fmt.Sprintf("%s/%d", hsResp.AssignedIP, hsResp.SubnetCidr)
	if err := tunnel.ConfigureInterface(tunDev.Name(), clientCIDR, "", hsResp.MTU, "", false, ""); err != nil {
		log.Fatalf("ConfigureInterface(%s) failed: %v", tunDev.Name(), err)
	}
	log.Printf("[argon4-client] TUN %s поднят с адресом %s", tunDev.Name(), clientCIDR)

	_ = conn.SetReadDeadline(time.Time{})

	// Двунаправленная пересылка между клиентским TUN и UDP-сокетом
	go func() {
		tunBuf := make([]byte, 65535)
		for {
			rn, rerr := tunDev.Read(tunBuf)
			if rerr != nil {
				return
			}
			wire, serr := tunnel.SealPacketWithSeq(tunnel.FrameTypeData, salt, psk, c2sKey, c2sSeq.Add(1), tunBuf[:rn])
			if serr == nil {
				_, _ = conn.Write(wire)
			}
		}
	}()

	go func() {
		udpBuf := make([]byte, 65535)
		for {
			rn, rerr := conn.Read(udpBuf)
			if rerr != nil {
				return
			}
			ft, _, seq, pt, oerr := tunnel.OpenPacketWithKey(udpBuf[:rn], psk, s2cKey)
			if oerr == nil && ft == tunnel.FrameTypeData && replay.Accept(seq) && len(pt) > 0 {
				_, _ = tunDev.Write(pt)
			}
		}
	}()

	// 3. Реальный HTTP-запрос через шифрованный TUN-интерфейс
	httpClient := &http.Client{Timeout: 10 * time.Second}
	resp, err := httpClient.Get(*probeURL)
	if err != nil {
		log.Fatalf("HTTP-запрос через туннель Argon4 на %s завершился с ошибкой: %v", *probeURL, err)
	}
	defer resp.Body.Close()
	body, _ := io.ReadAll(resp.Body)
	log.Printf("[argon4-client] HTTP-запрос через TUN Argon4 на %s выполнен успешно: status=%d bytes=%d",
		*probeURL, resp.StatusCode, len(bytes.TrimSpace(body)))
}

func envOr(k, def string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return def
}
