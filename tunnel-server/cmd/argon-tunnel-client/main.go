package main

import (
	"bytes"
	"context"
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
	"strconv"
	"sync/atomic"
	"time"

	"github.com/example/contactsync/tunnel-server/internal/tunnel"
)

func main() {
	var (
		serverAddr            = flag.String("server", envOr("ARGON_SERVER_ADDR", "tunnel:51820"), "UDP-адрес и порт сервера Argon4 на Ubuntu")
		bindIface             = flag.String("interface", envOr("ARGON_CLIENT_INTERFACE", ""), "Исходящий сетевой интерфейс клиента для отправки зашифрованных пакетов (например, eth0)")
		tunName               = flag.String("tun", envOr("ARGON_CLIENT_TUN", "argon-client0"), "Имя виртуального TUN-интерфейса на клиенте")
		masterPass            = flag.String("password", envOr("ARGON_MASTER_PASSWORD", ""), "Мастер-пароль для вывода ключа Argon2id")
		clientID              = flag.String("client-id", envOr("ARGON_CLIENT_ID", "docker-e2e-client"), "Идентификатор клиента")
		probeURL              = flag.String("probe-url", envOr("ARGON_PROBE_URL", ""), "HTTP URL для контрольного запроса через шифрованный TUN-интерфейс (например, http://10.44.0.1:80/admin/login)")
		sendPayload           = flag.String("send-material", "", "Строка материала для одноразового шифрования и отправки через выбранный интерфейс")
		protoVersion          = flag.Int("protocol-version", envIntOr("ARGON_PROTOCOL_VERSION", int(tunnel.ProtocolVersionV5)), "Версия протокола Argon4 (4 или 5)")
		paddingProfile        = flag.String("padding-profile", envOr("ARGON_PADDING_PROFILE", tunnel.PaddingProfileBalanced), "Профиль padding v5 (balanced, compact, wide)")
		firstPacketProfile    = flag.String("first-packet-profile", envOr("ARGON_FIRST_PACKET_PROFILE", ""), "Профиль padding первого handshake-пакета")
		pathMTU               = flag.Int("path-mtu", envIntOr("ARGON_PATH_MTU", tunnel.DefaultPathMTU), "Внешний path MTU")
		behaviorProfile       = flag.String("behavior-profile", envOr("ARGON_BEHAVIOR_PROFILE", ""), "Явный поведенческий профиль трафика v5 (пусто, baseline, video-call)")
		lowPowerMode          = flag.Bool("low-power", envOr("ARGON_LOW_POWER", "0") == "1", "Экономный режим (снижает служебный трафик профиля video-call)")
		videoCallMaxDuration  = flag.Duration("video-call-max-duration", envDurationOr("ARGON_VIDEO_CALL_MAX_DURATION", tunnel.DefaultVideoCallMaxDuration), "Предельная длительность активного режима video-call")
		hsMaxAttempts         = flag.Int("handshake-max-attempts", envIntOr("ARGON_HANDSHAKE_MAX_ATTEMPTS", 0), "Максимум попыток UDP-рукопожатия")
		hsInitialTimeout      = flag.Duration("handshake-initial-timeout", envDurationOr("ARGON_HANDSHAKE_INITIAL_TIMEOUT", 0), "Начальный таймаут ожидания ответа handshake (exponential backoff)")
		hsMaxTimeout          = flag.Duration("handshake-max-timeout", envDurationOr("ARGON_HANDSHAKE_MAX_TIMEOUT", 0), "Потолок таймаута одной попытки handshake")
		hsMaxElapsed          = flag.Duration("handshake-max-elapsed", envDurationOr("ARGON_HANDSHAKE_MAX_ELAPSED", 0), "Общий бюджет времени на повторы handshake")
		keepaliveMinIdle      = flag.Duration("keepalive-min-idle", envDurationOr("ARGON_KEEPALIVE_MIN_IDLE", 0), "Минимальный простой исходящего канала до keepalive")
		keepaliveMaxIdle      = flag.Duration("keepalive-max-idle", envDurationOr("ARGON_KEEPALIVE_MAX_IDLE", 0), "Максимальный простой исходящего канала до keepalive")
		sessionIdleTimeoutCfg = flag.Duration("session-idle-timeout", envDurationOr("ARGON_SESSION_IDLE_TIMEOUT", 0), "Ожидаемый таймаут неактивности серверной сессии")
	)
	flag.Parse()

	timingCfg, err := tunnel.NormalizeTimingConfig(tunnel.TimingConfig{
		HandshakeMaxAttempts:    *hsMaxAttempts,
		HandshakeInitialTimeout: *hsInitialTimeout,
		HandshakeMaxTimeout:     *hsMaxTimeout,
		HandshakeMaxElapsed:     *hsMaxElapsed,
		KeepaliveMinIdle:        *keepaliveMinIdle,
		KeepaliveMaxIdle:        *keepaliveMaxIdle,
		SessionIdleTimeout:      *sessionIdleTimeoutCfg,
	})
	if err != nil {
		log.Fatalf("Некорректные параметры таймингов: %v", err)
	}

	if *masterPass == "" {
		log.Fatal("Не задан мастер-пароль: укажите флаг -password или переменную окружения ARGON_MASTER_PASSWORD")
	}
	effVersion := byte(*protoVersion)
	sessionPad, err := tunnel.NormalizePaddingConfig(tunnel.PaddingConfig{Profile: *paddingProfile, PathMTU: *pathMTU}, true)
	if err != nil {
		log.Fatalf("Некорректные параметры padding: %v", err)
	}
	firstPad := sessionPad
	if *firstPacketProfile != "" {
		firstPad, err = tunnel.NormalizePaddingConfig(tunnel.PaddingConfig{Profile: *firstPacketProfile, PathMTU: *pathMTU}, true)
		if err != nil {
			log.Fatalf("Некорректный профиль первого пакета: %v", err)
		}
	}
	maxOuterUDP := tunnel.MaxOuterBytesForPathMTU(sessionPad.PathMTU)
	profileCfg := tunnel.ProfileConfig{
		Name:         *behaviorProfile,
		MaxDuration:  *videoCallMaxDuration,
		LowPowerMode: *lowPowerMode,
	}
	reqProfileName, reqProfileVer, err := tunnel.ValidateProfileRequest(effVersion, tunnel.ModeTunnel, tunnel.ProtoUDP, profileCfg)
	if err != nil {
		log.Fatalf("Некорректный поведенческий профиль: %v", err)
	}
	if reqProfileName == tunnel.ProfileVideoCall {
		log.Printf("[argon4-client] ВНИМАНИЕ: %s (лимит сеанса=%v)", tunnel.VideoCallWarning, *videoCallMaxDuration)
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
		ClientID:        *clientID,
		ClientAuth:      hex.EncodeToString(clientAuth),
		ClientPub:       hex.EncodeToString(clientPub),
		Apps:            []string{"com.example.contactsync", "kb", "cep"},
		Timestamp:       time.Now().Unix(),
		ProtocolVersion: int(effVersion),
	}
	if effVersion == tunnel.ProtocolVersionV5 {
		hsReq.Capabilities = tunnel.CapabilitiesForVersion(effVersion, true)
		hsReq.PaddingProfile = sessionPad.Profile
		if reqProfileName != "" || reqProfileVer > 0 {
			hsReq.BehaviorProfile = reqProfileName
			hsReq.ProfileVersion = reqProfileVer
		}
	}
	reqBytes, _ := json.Marshal(hsReq)
	wireReq, err := tunnel.SealPacketWithOptions(tunnel.FrameTypeHandshakeReq, salt, psk, hsKey, 1, reqBytes, tunnel.SealOptions{
		Version:       effVersion,
		Padding:       firstPad,
		MaxOuterBytes: maxOuterUDP,
	})
	if err != nil {
		log.Fatalf("SealPacketWithOptions handshake failed: %v", err)
	}

	buf := make([]byte, 65535)
	var hsResp tunnel.HandshakeResponse
	hsCtx, hsCancel := context.WithTimeout(context.Background(), timingCfg.HandshakeMaxElapsed)
	err = tunnel.RunHandshakeLoop(hsCtx, timingCfg, nil, nil, conn, wireReq, func(packet []byte) (bool, error) {
		openedResp, oerr := tunnel.OpenPacketWithOptions(packet, psk, hsKey, tunnel.OpenOptions{
			ExpectedVersion: effVersion,
			MaxOuterBytes:   maxOuterUDP,
		})
		if oerr != nil {
			return false, oerr
		}
		if openedResp.FrameType != tunnel.FrameTypeHandshakeResp || !bytes.Equal(openedResp.Salt, salt) {
			return false, nil
		}
		var parsed tunnel.HandshakeResponse
		if jerr := json.Unmarshal(openedResp.Plaintext, &parsed); jerr != nil {
			return false, jerr
		}
		if parsed.ProtocolVersion != 0 && byte(parsed.ProtocolVersion) != effVersion {
			return false, fmt.Errorf("%w: ответ v%d вместо v%d", tunnel.ErrDowngradeRejected, parsed.ProtocolVersion, effVersion)
		}
		hsResp = parsed
		return true, nil
	})
	hsCancel()
	if err != nil {
		log.Fatalf("Handshake failed: %v", err)
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

	sessionProfile, err := tunnel.VerifyClientProfileResponse(reqProfileName, reqProfileVer, hsResp, sessionPad, timingCfg, profileCfg, nil)
	if err != nil {
		log.Fatalf("Несовместимый поведенческий профиль сервера: %v", err)
	}
	var c2sSeq atomic.Uint64
	var replay tunnel.ReplayWindow

	sealOpts := tunnel.SealOptions{
		Version:       effVersion,
		Padding:       sessionPad,
		SamplePad:     sessionProfile.SamplePaddingLen,
		MaxOuterBytes: maxOuterUDP,
	}
	if !sessionProfile.IsActive() {
		sealOpts.SamplePad = nil
	}
	openOpts := tunnel.OpenOptions{
		ExpectedVersion: effVersion,
		MaxOuterBytes:   maxOuterUDP,
	}

	if *sendPayload != "" {
		pkt, err := tunnel.SealPacketWithOptions(tunnel.FrameTypeKeepalive, salt, psk, c2sKey, c2sSeq.Add(1), []byte(*sendPayload), sealOpts)
		if err != nil {
			log.Fatalf("SealPacketWithOptions material failed: %v", err)
		}
		if _, err := conn.Write(pkt); err != nil {
			log.Fatalf("Write material failed: %v", err)
		}
		_ = conn.SetReadDeadline(time.Now().Add(5 * time.Second))
		n, err := conn.Read(buf)
		if err != nil {
			log.Fatalf("Read keepalive pong failed: %v", err)
		}
		openedPong, err := tunnel.OpenPacketWithOptions(buf[:n], psk, s2cKey, openOpts)
		if err != nil || !replay.Accept(openedPong.Seq) {
			log.Fatalf("OpenPacketWithOptions keepalive pong failed: %v", err)
		}
		log.Printf("[argon4-client] зашифрованный материал доставлен и подтверждён сервером: %q", string(openedPong.Plaintext))
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

	tracker := tunnel.NewActivityTracker(nil)
	loopCtx, loopCancel := context.WithCancel(context.Background())
	kaDone := make(chan struct{})
	defer func() {
		loopCancel()
		<-kaDone
	}()

	var shaper *tunnel.TrafficShaper
	if sessionProfile.IsActive() {
		shaper = tunnel.NewTrafficShaper(tunnel.ShaperOptions{
			Profile:          sessionProfile,
			Salt:             salt,
			PSK:              psk,
			Key:              c2sKey,
			Version:          effVersion,
			MaxOuterBytes:    maxOuterUDP,
			NextSeq:          func() uint64 { return c2sSeq.Add(1) },
			Tracker:          tracker,
			KeepaliveEnabled: true,
			WriteWire: func(wire []byte) error {
				_, werr := conn.Write(wire)
				return werr
			},
			OnFatalError: func(kerr error) {
				if loopCtx.Err() == nil {
					log.Printf("[argon4-client] shaper: %v", kerr)
				}
			},
		})
		shaper.Start()
		defer shaper.Close()
	}

	go func() {
		defer close(kaDone)
		if shaper != nil {
			<-loopCtx.Done()
			return
		}
		tunnel.RunIdleKeepalive(loopCtx, tunnel.KeepaliveOptions{
			Timing:  timingCfg,
			Tracker: tracker,
			Send: func() error {
				pkt, serr := tunnel.SealPacketWithOptions(tunnel.FrameTypeKeepalive, salt, psk, c2sKey, c2sSeq.Add(1), []byte("ping"), sealOpts)
				if serr != nil {
					return serr
				}
				_, werr := conn.Write(pkt)
				return werr
			},
			OnError: func(kerr error) {
				if loopCtx.Err() == nil {
					log.Printf("[argon4-client] keepalive: %v", kerr)
				}
			},
		})
	}()

	// Двунаправленная пересылка между клиентским TUN и UDP-сокетом
	go func() {
		tunBuf := make([]byte, 65535)
		for {
			rn, rerr := tunDev.Read(tunBuf)
			if rerr != nil {
				return
			}
			if shaper != nil {
				_ = shaper.EnqueueReal(tunBuf[:rn])
				continue
			}
			wire, serr := tunnel.SealPacketWithOptions(tunnel.FrameTypeData, salt, psk, c2sKey, c2sSeq.Add(1), tunBuf[:rn], sealOpts)
			if serr == nil {
				if _, werr := conn.Write(wire); werr == nil {
					tracker.Touch()
				}
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
			opened, oerr := tunnel.OpenPacketWithOptions(udpBuf[:rn], psk, s2cKey, openOpts)
			if oerr != nil || !replay.Accept(opened.Seq) {
				continue
			}
			switch opened.FrameType {
			case tunnel.FrameTypeData:
				if shaper != nil {
					shaper.RecordInboundActivity()
				}
				if len(opened.Plaintext) > 0 {
					_, _ = tunDev.Write(opened.Plaintext)
				}
			case tunnel.FrameTypeBatch:
				if subs, berr := tunnel.UnpackBatchPlaintext(opened.Plaintext, maxOuterUDP-tunnel.OverheadV5); berr == nil {
					if shaper != nil {
						shaper.RecordInboundActivity()
					}
					for _, sub := range subs {
						if len(sub) > 0 {
							_, _ = tunDev.Write(sub)
						}
					}
				}
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

func envIntOr(k string, def int) int {
	if v := os.Getenv(k); v != "" {
		if n, err := strconv.Atoi(v); err == nil {
			return n
		}
	}
	return def
}

func envDurationOr(k string, def time.Duration) time.Duration {
	if v := os.Getenv(k); v != "" {
		if d, err := time.ParseDuration(v); err == nil {
			return d
		}
	}
	return def
}
