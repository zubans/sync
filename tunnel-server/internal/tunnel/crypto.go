package tunnel

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/ecdh"
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/binary"
	"errors"
	"fmt"
)

const (
	// Версии протокола Argon4: v4 (базовая без padding) и v5 (аутентифицированный padding переменной длины).
	ProtocolVersionV4 byte = 0x04
	ProtocolVersionV5 byte = 0x05
	ProtocolVersion   byte = ProtocolVersionV5

	// Типы кадров протокола Argon4
	FrameTypeHandshakeReq  byte = 0x01
	FrameTypeHandshakeResp byte = 0x02
	FrameTypeData          byte = 0x03
	FrameTypeKeepalive     byte = 0x04
	FrameTypeBatch         byte = 0x05

	// Параметры Argon2id по умолчанию (по рекомендации OWASP: 19 МиБ, 2 прохода, 1 поток)
	DefaultArgonTime    uint32 = 2
	DefaultArgonMemory  uint32 = 19456
	DefaultArgonThreads uint8  = 1
	KeySize                    = 32
	SaltSize                   = 16
	NonceSize                  = 12
	TagSize                    = 16
	X25519KeySize              = 32

	// Заголовок кадра: Salt(16) + MaskedMeta(Version[1] + FrameType[1] + AuthCheck[4] + Seq[8] = 14) = 30 байт
	MetaSize   = 14
	HeaderSize = SaltSize + MetaSize
)

var (
	// Фиксированная доменная соль для быстрого ключа заголовка (PSK), защищающего от DoS и сигнатур DPI.
	pskDomainSalt = []byte("argon4-psk-v4-01")

	ErrPacketTooShort    = errors.New("пакет туннеля слишком короткий")
	ErrBadVersion        = errors.New("неподдерживаемая версия протокола")
	ErrHeaderAuthFailed  = errors.New("ошибка проверки заголовка пакета по мастер-паролю")
	ErrAuthFailed        = errors.New("ошибка проверки подлинности / расшифровки по мастер-паролю")
	ErrReplayPacket      = errors.New("обнаружен повтор пакета (replay)")
	ErrInvalidPadding    = errors.New("повреждённый или некорректный padding кадра")
	ErrInvalidEnvelope   = errors.New("некорректная длина или структура конверта кадра")
	ErrTrailingData      = errors.New("обнаружены лишние байты после полезной нагрузки и padding")
	ErrMTUExceeded       = errors.New("длина пакета или padding превышает допустимый path MTU")
	ErrDowngradeRejected = errors.New("обнаружена попытка понижения версии протокола (downgrade)")
)

// ArgonParams задаёт параметры вывода ключа Argon2id (время, память в КиБ и параллелизм).
type ArgonParams struct {
	Time    uint32
	Memory  uint32
	Threads uint8
}

var DefaultParams = ArgonParams{
	Time:    DefaultArgonTime,
	Memory:  DefaultArgonMemory,
	Threads: DefaultArgonThreads,
}

// DeriveKey выводит 32-байтный ключ AES-256 из мастер-пароля и 16-байтной соли алгоритмом Argon2id (v1.3 / RFC 9106).
func DeriveKey(masterPassword string, salt []byte, params ArgonParams) []byte {
	return argon2IDKey([]byte(masterPassword), salt, params.Time, params.Memory, params.Threads, KeySize)
}

// DerivePSK выводит 32-байтный ключ обфускации и быстрой проверки заголовков из мастер-пароля.
// Вычисляется один раз при старте сервера или клиента, отсекая мусорные пакеты за доли микросекунды до запуска Argon2id.
func DerivePSK(masterPassword string, params ArgonParams) []byte {
	return DeriveKey(masterPassword, pskDomainSalt, params)
}

// GenerateX25519Keypair создаёт эфемерную пару ключей X25519 для прямой секретности (PFS) сессии.
func GenerateX25519Keypair() (privBytes, pubBytes []byte, err error) {
	curve := ecdh.X25519()
	priv, err := curve.GenerateKey(rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	return priv.Bytes(), priv.PublicKey().Bytes(), nil
}

// ComputeX25519Shared вычисляет общий эфемерный секрет X25519 (32 байта).
func ComputeX25519Shared(privBytes, peerPubBytes []byte) ([]byte, error) {
	curve := ecdh.X25519()
	priv, err := curve.NewPrivateKey(privBytes)
	if err != nil {
		return nil, err
	}
	pub, err := curve.NewPublicKey(peerPubBytes)
	if err != nil {
		return nil, err
	}
	return priv.ECDH(pub)
}

// DeriveSessionKeys выводит раздельные ключи AES-256-GCM для направлений клиент->сервер (c2s) и сервер->клиент (s2c)
// из ключа Argon2id, эфемерного секрета X25519 и соли сессии через HKDF-SHA256.
func DeriveSessionKeys(handshakeKey, sharedSecret, salt []byte) (c2sKey, s2cKey []byte) {
	prkMac := hmac.New(sha256.New, handshakeKey)
	prkMac.Write(sharedSecret)
	prkMac.Write(salt)
	prk := prkMac.Sum(nil)

	c2sMac := hmac.New(sha256.New, prk)
	c2sMac.Write([]byte("argon4-c2s-key\x01"))
	c2sKey = c2sMac.Sum(nil)

	s2cMac := hmac.New(sha256.New, prk)
	s2cMac.Write([]byte("argon4-s2c-key\x01"))
	s2cKey = s2cMac.Sum(nil)
	return c2sKey, s2cKey
}

// ClientAuthToken вычисляет токен привязки идентификатора клиента к его секрету.
func ClientAuthToken(psk []byte, clientID, clientSecret string) []byte {
	mac := hmac.New(sha256.New, psk)
	mac.Write([]byte("argon4-cid:"))
	mac.Write([]byte(clientID))
	mac.Write([]byte(":"))
	mac.Write([]byte(clientSecret))
	return mac.Sum(nil)[:16]
}

func computeHeaderMask(psk, salt []byte) [32]byte {
	mac := hmac.New(sha256.New, psk)
	mac.Write([]byte("argon4-hdr:"))
	mac.Write(salt)
	var out [32]byte
	mac.Sum(out[:0])
	return out
}

func computeHeaderCheck(psk, salt []byte, version, frameType byte, seqBytes []byte) [4]byte {
	mac := hmac.New(sha256.New, psk)
	mac.Write([]byte("argon4-chk:"))
	mac.Write(salt)
	mac.Write([]byte{version, frameType})
	mac.Write(seqBytes)
	var sum [32]byte
	mac.Sum(sum[:0])
	return [4]byte{sum[0], sum[1], sum[2], sum[3]}
}

// BuildAAD формирует дополнительные аутентифицируемые данные (AAD), связывающие версию, тип кадра, соль и счётчик пакета.
func BuildAAD(version, frameType byte, salt []byte, seq uint64) []byte {
	aad := make([]byte, 0, 11+2+len(salt)+8)
	aad = append(aad, []byte("argon4-tun:")...)
	aad = append(aad, version, frameType)
	aad = append(aad, salt...)
	var seqBuf [8]byte
	binary.BigEndian.PutUint64(seqBuf[:], seq)
	aad = append(aad, seqBuf[:]...)
	return aad
}

// SealOptions задаёт версию протокола, профиль padding, генератор длины и предельный размер внешнего кадра.
type SealOptions struct {
	Version       byte
	Padding       PaddingConfig
	SamplePad     func(payloadLen, maxOuter int) (int, error)
	UseFixedPad   bool
	FixedPadding  int
	MaxOuterBytes int
}

// OpenOptions задаёт политику проверки версии, совместимости и границ MTU при разборе кадра.
type OpenOptions struct {
	ExpectedVersion byte
	AllowV4         bool
	MaxOuterBytes   int
	MaxPayloadBytes int
}

// OpenedPacket представляет расшифрованный и проверенный кадр Argon4.
type OpenedPacket struct {
	Version   byte
	FrameType byte
	Salt      []byte
	Seq       uint64
	Plaintext []byte
}

// SealPacketWithSeq шифрует полезную нагрузку в кадр протокола Argon4 (по умолчанию v5 с переменным padding).
func SealPacketWithSeq(frameType byte, salt, psk, key []byte, seq uint64, plaintext []byte) ([]byte, error) {
	return SealPacketWithOptions(frameType, salt, psk, key, seq, plaintext, SealOptions{
		Version:       ProtocolVersionV5,
		Padding:       DefaultPaddingConfig(),
		MaxOuterBytes: MaxTCPFrameSize,
	})
}

// SealPacketWithOptions шифрует кадр Argon4 v4 или v5 с явной версией, padding и проверкой внешнего MTU.
// На проводе заголовок полностью неотличим от случайного шума (без открытых байт версии или типа кадра):
//
//	[0..16]   Соль сессии (16 случайных байт)
//	[16..30]  Замаскированные метаданные: (Version[1] || FrameType[1] || AuthCheck[4] || Seq[8]) XOR HMAC(psk, salt)
//	[30..]    Шифротекст AES-256-GCM + тег аутентификации (16 байт)
func SealPacketWithOptions(frameType byte, salt, psk, key []byte, seq uint64, plaintext []byte, opts SealOptions) ([]byte, error) {
	if len(salt) != SaltSize {
		return nil, fmt.Errorf("некорректная длина соли: %d", len(salt))
	}
	if len(psk) != KeySize {
		return nil, fmt.Errorf("некорректная длина psk: %d", len(psk))
	}
	if seq == 0 {
		return nil, errors.New("счётчик пакета seq должен быть >= 1")
	}
	version := opts.Version
	if version == 0 {
		version = ProtocolVersionV5
	}
	if version != ProtocolVersionV4 && version != ProtocolVersionV5 {
		return nil, fmt.Errorf("%w: 0x%02x", ErrBadVersion, version)
	}

	maxOuter := opts.MaxOuterBytes
	if maxOuter <= 0 {
		maxOuter = MaxOuterBytesForPathMTU(opts.Padding.PathMTU)
	}

	var envelope []byte
	if version == ProtocolVersionV5 {
		var padLen int
		var err error
		if opts.UseFixedPad {
			padLen = opts.FixedPadding
		} else if opts.SamplePad != nil {
			padLen, err = opts.SamplePad(len(plaintext), maxOuter)
			if err != nil {
				return nil, err
			}
		} else {
			padLen, err = SamplePaddingLen(opts.Padding, len(plaintext), maxOuter)
			if err != nil {
				return nil, err
			}
		}
		envelope, err = PackEnvelopeV5(plaintext, padLen, maxOuter)
		if err != nil {
			return nil, err
		}
	} else {
		if maxOuter > 0 && OverheadV4+len(plaintext) > maxOuter {
			return nil, fmt.Errorf("%w: кадр v4 (%d байт) превышает лимит %d байт", ErrMTUExceeded, OverheadV4+len(plaintext), maxOuter)
		}
		envelope = plaintext
	}

	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}

	var meta [MetaSize]byte
	meta[0] = version
	meta[1] = frameType
	binary.BigEndian.PutUint64(meta[6:14], seq)
	chk := computeHeaderCheck(psk, salt, version, frameType, meta[6:14])
	copy(meta[2:6], chk[:])

	// 12-байтный nonce для AES-256-GCM: AuthCheck[4] || Seq[8]
	var nonce [NonceSize]byte
	copy(nonce[:], meta[2:14])

	mask := computeHeaderMask(psk, salt)
	out := make([]byte, HeaderSize, HeaderSize+len(envelope)+TagSize)
	copy(out[0:SaltSize], salt)
	for i := 0; i < MetaSize; i++ {
		out[SaltSize+i] = meta[i] ^ mask[i]
	}

	aad := BuildAAD(version, frameType, salt, seq)
	out = gcm.Seal(out, nonce[:], envelope, aad)
	return out, nil
}

// ParseAndVerifyHeader снимает маску с заголовка кадра Argon4 (v4/v5) и проверяет 4-байтный код подлинности заголовка по PSK.
// Выполняется за <1 мкс и позволяет отбросить любой чужой/мусорный UDP-пакет без запуска Argon2id.
func ParseAndVerifyHeader(packet, psk []byte) (version, frameType byte, salt []byte, nonce [NonceSize]byte, seq uint64, ciphertext []byte, err error) {
	if len(packet) < OverheadV4 {
		return 0, 0, nil, nonce, 0, nil, ErrPacketTooShort
	}
	if len(psk) != KeySize {
		return 0, 0, nil, nonce, 0, nil, ErrHeaderAuthFailed
	}
	salt = packet[0:SaltSize]
	mask := computeHeaderMask(psk, salt)
	var meta [MetaSize]byte
	for i := 0; i < MetaSize; i++ {
		meta[i] = packet[SaltSize+i] ^ mask[i]
	}
	version = meta[0]
	if version != ProtocolVersionV4 && version != ProtocolVersionV5 {
		return 0, 0, nil, nonce, 0, nil, ErrBadVersion
	}
	frameType = meta[1]
	if frameType < FrameTypeHandshakeReq || frameType > FrameTypeBatch {
		return 0, 0, nil, nonce, 0, nil, ErrHeaderAuthFailed
	}
	seq = binary.BigEndian.Uint64(meta[6:14])
	if seq == 0 {
		return 0, 0, nil, nonce, 0, nil, ErrHeaderAuthFailed
	}
	expectedChk := computeHeaderCheck(psk, salt, version, frameType, meta[6:14])
	if subtle.ConstantTimeCompare(meta[2:6], expectedChk[:]) != 1 {
		return 0, 0, nil, nonce, 0, nil, ErrHeaderAuthFailed
	}
	if version == ProtocolVersionV5 && len(packet) < OverheadV5 {
		return 0, 0, nil, nonce, 0, nil, ErrPacketTooShort
	}
	copy(nonce[:], meta[2:14])
	ciphertext = packet[HeaderSize:]
	return version, frameType, salt, nonce, seq, ciphertext, nil
}

// OpenPacketWithKey проверяет заголовок и расшифровывает кадр Argon4 v4 или v5 указанным ключом.
func OpenPacketWithKey(packet, psk, key []byte) (frameType byte, salt []byte, seq uint64, plaintext []byte, err error) {
	opened, err := OpenPacketWithOptions(packet, psk, key, OpenOptions{
		AllowV4:       true,
		MaxOuterBytes: MaxTCPFrameSize,
	})
	if err != nil {
		return 0, nil, 0, nil, err
	}
	return opened.FrameType, opened.Salt, opened.Seq, opened.Plaintext, nil
}

// OpenPacketWithOptions проверяет ограничения размера/версии, заголовок PSK, тег GCM и конверт padding.
func OpenPacketWithOptions(packet, psk, key []byte, opts OpenOptions) (OpenedPacket, error) {
	if opts.MaxOuterBytes > 0 && len(packet) > opts.MaxOuterBytes {
		return OpenedPacket{}, fmt.Errorf("%w: входящий кадр %d байт превышает предел %d байт", ErrMTUExceeded, len(packet), opts.MaxOuterBytes)
	}
	version, fType, s, nonce, pktSeq, ciphertext, err := ParseAndVerifyHeader(packet, psk)
	if err != nil {
		return OpenedPacket{}, err
	}
	if opts.ExpectedVersion != 0 {
		if version != opts.ExpectedVersion {
			if opts.ExpectedVersion == ProtocolVersionV5 && version == ProtocolVersionV4 {
				return OpenedPacket{}, ErrDowngradeRejected
			}
			return OpenedPacket{}, ErrBadVersion
		}
	} else if version == ProtocolVersionV4 && !opts.AllowV4 {
		return OpenedPacket{}, ErrBadVersion
	}

	block, err := aes.NewCipher(key)
	if err != nil {
		return OpenedPacket{}, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return OpenedPacket{}, err
	}
	aad := BuildAAD(version, fType, s, pktSeq)
	pt, err := gcm.Open(nil, nonce[:], ciphertext, aad)
	if err != nil {
		return OpenedPacket{}, ErrAuthFailed
	}

	var payload []byte
	if version == ProtocolVersionV5 {
		payload, err = UnpackEnvelopeV5(pt, opts.MaxPayloadBytes)
		if err != nil {
			return OpenedPacket{}, err
		}
	} else {
		if opts.MaxPayloadBytes > 0 && len(pt) > opts.MaxPayloadBytes {
			return OpenedPacket{}, fmt.Errorf("%w: полезная нагрузка %d байт превышает предел %d", ErrMTUExceeded, len(pt), opts.MaxPayloadBytes)
		}
		payload = pt
	}

	return OpenedPacket{
		Version:   version,
		FrameType: fType,
		Salt:      s,
		Seq:       pktSeq,
		Plaintext: payload,
	}, nil
}
