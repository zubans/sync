package tunnel

import (
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
)

const (
	// Версия протокола на проводе ("Argon 4" tunnel envelope v4)
	ProtocolVersion byte = 0x04

	// Типы кадров протокола Argon4
	FrameTypeHandshakeReq  byte = 0x01
	FrameTypeHandshakeResp byte = 0x02
	FrameTypeData          byte = 0x03
	FrameTypeKeepalive     byte = 0x04

	// Параметры Argon2id по умолчанию (совпадают с VaultCrypto по рекомендации OWASP: 19 МиБ, 2 прохода, 1 поток)
	DefaultArgonTime    uint32 = 2
	DefaultArgonMemory  uint32 = 19456
	DefaultArgonThreads uint8  = 1
	KeySize                    = 32
	SaltSize                   = 16
	NonceSize                  = 12
	TagSize                    = 16

	// Заголовок кадра: Version(1) + Type(1) + Salt(16) + Nonce(12) = 30 байт
	HeaderSize = 1 + 1 + SaltSize + NonceSize
)

var (
	ErrPacketTooShort = errors.New("пакет туннеля слишком короткий")
	ErrBadVersion     = errors.New("неподдерживаемая версия протокола")
	ErrAuthFailed     = errors.New("ошибка проверки подлинности / расшифровки по мастер-паролю")
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

// BuildAAD формирует дополнительные аутентифицируемые данные (AAD), связывающие версию протокола, тип кадра и соль,
// чтобы заголовок пакета нельзя было подменить без провала проверки тега GCM.
func BuildAAD(version, frameType byte, salt []byte) []byte {
	aad := make([]byte, 0, 12+2+len(salt))
	aad = append(aad, []byte("argon4-tun:")...)
	aad = append(aad, version, frameType)
	aad = append(aad, salt...)
	return aad
}

// SealPacket шифрует полезную нагрузку (IPv4-пакет или JSON рукопожатия) в кадр протокола Argon4.
// Формат кадра:
//   [0]      Версия протокола (0x04)
//   [1]      Тип кадра (0x01..0x04)
//   [2..18]  Соль Argon2id (16 байт)
//   [18..30] Nonce / IV (12 байт)
//   [30..]   Шифротекст AES-256-GCM + тег аутентификации (16 байт)
func SealPacket(frameType byte, salt []byte, key []byte, plaintext []byte) ([]byte, error) {
	if len(salt) != SaltSize {
		return nil, fmt.Errorf("некорректная длина соли: %d", len(salt))
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}

	nonce := make([]byte, NonceSize)
	if _, err := rand.Read(nonce); err != nil {
		return nil, err
	}

	out := make([]byte, HeaderSize, HeaderSize+len(plaintext)+TagSize)
	out[0] = ProtocolVersion
	out[1] = frameType
	copy(out[2:2+SaltSize], salt)
	copy(out[2+SaltSize:HeaderSize], nonce)

	aad := BuildAAD(ProtocolVersion, frameType, salt)
	out = gcm.Seal(out, nonce, plaintext, aad)
	return out, nil
}

// SealPacketWithSeq шифрует пакет с использованием детерминированного nonce (4 случайных байта префикса + 8-байтный счётчик),
// исключая коллизии nonce при интенсивном потоке пакетов в рамках одной сессии.
func SealPacketWithSeq(frameType byte, salt []byte, key []byte, noncePrefix []byte, seq uint64, plaintext []byte) ([]byte, error) {
	if len(salt) != SaltSize {
		return nil, fmt.Errorf("некорректная длина соли: %d", len(salt))
	}
	if len(noncePrefix) != 4 {
		return nil, fmt.Errorf("некорректная длина префикса nonce: %d", len(noncePrefix))
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return nil, err
	}

	nonce := make([]byte, NonceSize)
	copy(nonce[0:4], noncePrefix)
	binary.BigEndian.PutUint64(nonce[4:12], seq)

	out := make([]byte, HeaderSize, HeaderSize+len(plaintext)+TagSize)
	out[0] = ProtocolVersion
	out[1] = frameType
	copy(out[2:2+SaltSize], salt)
	copy(out[2+SaltSize:HeaderSize], nonce)

	aad := BuildAAD(ProtocolVersion, frameType, salt)
	out = gcm.Seal(out, nonce, plaintext, aad)
	return out, nil
}

// ParseHeader разбирает заголовок сырого кадра Argon4 на версию, тип кадра, соль, nonce и срез шифротекста.
func ParseHeader(packet []byte) (version byte, frameType byte, salt []byte, nonce []byte, ciphertext []byte, err error) {
	if len(packet) < HeaderSize+TagSize {
		return 0, 0, nil, nil, nil, ErrPacketTooShort
	}
	version = packet[0]
	if version != ProtocolVersion {
		return 0, 0, nil, nil, nil, ErrBadVersion
	}
	frameType = packet[1]
	salt = packet[2 : 2+SaltSize]
	nonce = packet[2+SaltSize : HeaderSize]
	ciphertext = packet[HeaderSize:]
	return version, frameType, salt, nonce, ciphertext, nil
}

// OpenPacket расшифровывает и проверяет подлинность кадра Argon4 с помощью выведенного мастер-ключа.
func OpenPacket(packet []byte, key []byte) (frameType byte, salt []byte, plaintext []byte, err error) {
	version, fType, s, nonce, ciphertext, err := ParseHeader(packet)
	if err != nil {
		return 0, nil, nil, err
	}
	block, err := aes.NewCipher(key)
	if err != nil {
		return 0, nil, nil, err
	}
	gcm, err := cipher.NewGCM(block)
	if err != nil {
		return 0, nil, nil, err
	}

	aad := BuildAAD(version, fType, s)
	pt, err := gcm.Open(nil, nonce, ciphertext, aad)
	if err != nil {
		return 0, nil, nil, ErrAuthFailed
	}
	return fType, s, pt, nil
}
