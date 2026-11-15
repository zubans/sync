package argon4

import (
	"crypto/rand"
	"encoding/binary"
	"fmt"
	"math/big"
	"strings"
)

const (
	// Допустимые имена профилей распределения длины padding.
	PaddingProfileBalanced = "balanced"
	PaddingProfileCompact  = "compact"
	PaddingProfileWide     = "wide"
	PaddingProfileCustom   = "custom"

	// EnvelopeHeaderSizeV5 — защищённый заголовок внутри AES-256-GCM для v5: PayloadLen(2) + PadLen(2).
	EnvelopeHeaderSizeV5 = 4
	OverheadV4           = HeaderSize + TagSize
	OverheadV5           = HeaderSize + EnvelopeHeaderSizeV5 + TagSize

	// Параметры path MTU по умолчанию (IPv4 20 + UDP 8 = 28 байт внешних заголовков).
	DefaultPathMTU         = 1500
	IPv4UDPOverhead        = 28
	DefaultMaxOuterUDPSize = DefaultPathMTU - IPv4UDPOverhead
	MinPathMTU             = 576
	MaxPathMTU             = 9000
	MaxPayloadLimit        = 65535 - EnvelopeHeaderSizeV5
)

// PaddingConfig задаёт распределение длины случайного padding и ограничение внешнего path MTU.
type PaddingConfig struct {
	Profile        string `json:"profile,omitempty"`
	MinPad         int    `json:"minPad,omitempty"`
	MaxPad         int    `json:"maxPad,omitempty"`
	SmallThreshold int    `json:"smallThreshold,omitempty"`
	SmallMinPad    int    `json:"smallMinPad,omitempty"`
	SmallMaxPad    int    `json:"smallMaxPad,omitempty"`
	PathMTU        int    `json:"pathMtu,omitempty"`
}

// DefaultPaddingConfig возвращает профиль padding по умолчанию ("balanced") для стандартного path MTU 1500.
func DefaultPaddingConfig() PaddingConfig {
	return PaddingConfig{
		Profile:        PaddingProfileBalanced,
		MinPad:         8,
		MaxPad:         96,
		SmallThreshold: 160,
		SmallMinPad:    16,
		SmallMaxPad:    192,
		PathMTU:        DefaultPathMTU,
	}
}

// PresetPaddingConfig возвращает предустановленное распределение по имени ("balanced", "compact", "wide").
func PresetPaddingConfig(name string, pathMTU int) (PaddingConfig, error) {
	mtu := pathMTU
	if mtu == 0 {
		mtu = DefaultPathMTU
	}
	switch strings.ToLower(strings.TrimSpace(name)) {
	case "", PaddingProfileBalanced:
		cfg := DefaultPaddingConfig()
		cfg.PathMTU = mtu
		return cfg, nil
	case PaddingProfileCompact:
		return PaddingConfig{
			Profile:        PaddingProfileCompact,
			MinPad:         4,
			MaxPad:         32,
			SmallThreshold: 120,
			SmallMinPad:    8,
			SmallMaxPad:    64,
			PathMTU:        mtu,
		}, nil
	case PaddingProfileWide:
		return PaddingConfig{
			Profile:        PaddingProfileWide,
			MinPad:         16,
			MaxPad:         192,
			SmallThreshold: 256,
			SmallMinPad:    32,
			SmallMaxPad:    320,
			PathMTU:        mtu,
		}, nil
	default:
		return PaddingConfig{}, fmt.Errorf("неизвестный профиль padding %q", name)
	}
}

// MaxOuterBytesForPathMTU вычисляет максимальный размер UDP-датаграммы без фрагментации IPv4.
func MaxOuterBytesForPathMTU(pathMTU int) int {
	if pathMTU <= 0 {
		return DefaultMaxOuterUDPSize
	}
	if pathMTU <= IPv4UDPOverhead {
		return 0
	}
	return pathMTU - IPv4UDPOverhead
}

// NormalizePaddingConfig проверяет и заполняет значениями по умолчанию конфигурацию padding.
// Запрещает отрицательные значения, вырожденный фиксированный размер (max <= min) и параметры, превышающие path MTU.
func NormalizePaddingConfig(cfg PaddingConfig, isUDP bool) (PaddingConfig, error) {
	mtu := cfg.PathMTU
	if mtu == 0 {
		mtu = DefaultPathMTU
	}
	if mtu < MinPathMTU || mtu > MaxPathMTU {
		return PaddingConfig{}, fmt.Errorf("%w: недопустимый path MTU %d (допустимо %d..%d)", ErrMTUExceeded, mtu, MinPathMTU, MaxPathMTU)
	}

	profile := strings.ToLower(strings.TrimSpace(cfg.Profile))
	hasCustomBounds := cfg.MinPad != 0 || cfg.MaxPad != 0 || cfg.SmallThreshold != 0 || cfg.SmallMinPad != 0 || cfg.SmallMaxPad != 0
	if cfg.MinPad < 0 || cfg.MaxPad < 0 || cfg.SmallThreshold < 0 || cfg.SmallMinPad < 0 || cfg.SmallMaxPad < 0 {
		return PaddingConfig{}, fmt.Errorf("отрицательные параметры padding недопустимы")
	}

	var normalized PaddingConfig
	if profile == "" && !hasCustomBounds {
		normalized = DefaultPaddingConfig()
		normalized.PathMTU = mtu
	} else if profile == PaddingProfileCustom || (profile == "" && hasCustomBounds) {
		normalized = cfg
		normalized.Profile = PaddingProfileCustom
		normalized.PathMTU = mtu
	} else {
		preset, err := PresetPaddingConfig(profile, mtu)
		if err != nil {
			return PaddingConfig{}, err
		}
		normalized = preset
	}

	if normalized.MaxPad <= normalized.MinPad {
		return PaddingConfig{}, fmt.Errorf("padding должен иметь переменную длину (maxPad=%d <= minPad=%d)", normalized.MaxPad, normalized.MinPad)
	}
	if normalized.SmallThreshold > 0 && normalized.SmallMaxPad <= normalized.SmallMinPad {
		return PaddingConfig{}, fmt.Errorf("padding коротких пакетов должен иметь переменную длину (smallMaxPad=%d <= smallMinPad=%d)", normalized.SmallMaxPad, normalized.SmallMinPad)
	}

	if isUDP {
		maxPadBudget := MaxOuterBytesForPathMTU(normalized.PathMTU) - OverheadV5
		if normalized.MinPad > maxPadBudget || normalized.MaxPad > maxPadBudget ||
			normalized.SmallMinPad > maxPadBudget || normalized.SmallMaxPad > maxPadBudget {
			return PaddingConfig{}, fmt.Errorf("%w: параметры padding превышают доступный бюджет path MTU (%d)", ErrMTUExceeded, normalized.PathMTU)
		}
	}
	return normalized, nil
}

// ResolvePaddingConfig нормализует конфигурацию и при ошибке откатывается на профиль по умолчанию.
func ResolvePaddingConfig(cfg PaddingConfig, isUDP bool) PaddingConfig {
	n, err := NormalizePaddingConfig(cfg, isUDP)
	if err == nil {
		return n
	}
	return DefaultPaddingConfig()
}

// EffectiveTunMTU ограничивает MTU виртуального TUN так, чтобы внутренний пакет с минимальными накладными расходами умещался в path MTU.
func EffectiveTunMTU(requestedMTU, pathMTU int, version byte) int {
	if requestedMTU <= 0 {
		requestedMTU = 1380
	}
	outerMax := MaxOuterBytesForPathMTU(pathMTU)
	overhead := OverheadV5
	if version == ProtocolVersionV4 {
		overhead = OverheadV4
	}
	maxInner := outerMax - overhead
	if maxInner >= 576 && requestedMTU > maxInner {
		return maxInner
	}
	return requestedMTU
}

// SamplePaddingLen выбирает случайную длину padding из распределения с жёстким ограничением бюджета внешнего кадра.
func SamplePaddingLen(cfg PaddingConfig, payloadLen, maxOuterBytes int) (int, error) {
	if payloadLen < 0 {
		return 0, fmt.Errorf("%w: отрицательная длина payload %d", ErrInvalidEnvelope, payloadLen)
	}
	if maxOuterBytes <= 0 {
		maxOuterBytes = MaxOuterBytesForPathMTU(cfg.PathMTU)
	}
	budget := maxOuterBytes - OverheadV5 - payloadLen
	if budget < 0 || payloadLen > MaxPayloadLimit {
		return 0, fmt.Errorf("%w: payload (%d байт) превышает лимит кадра (%d байт)", ErrMTUExceeded, payloadLen, maxOuterBytes)
	}

	resolved := ResolvePaddingConfig(cfg, maxOuterBytes <= DefaultMaxOuterUDPSize)
	lo, hi := resolved.MinPad, resolved.MaxPad
	if payloadLen <= resolved.SmallThreshold {
		lo, hi = resolved.SmallMinPad, resolved.SmallMaxPad
	}
	if hi > budget {
		hi = budget
	}
	if lo > hi {
		lo = hi
	}
	if hi <= lo {
		return lo, nil
	}
	span := hi - lo + 1
	n, err := rand.Int(rand.Reader, big.NewInt(int64(span)))
	if err != nil {
		return 0, err
	}
	return lo + int(n.Int64()), nil
}

// PackEnvelopeV5 упаковывает полезную нагрузку и нулевой padding в аутентифицированный внутренний конверт v5.
func PackEnvelopeV5(plaintext []byte, padLen, maxOuterBytes int) ([]byte, error) {
	payloadLen := len(plaintext)
	if padLen < 0 {
		return nil, fmt.Errorf("%w: отрицательная длина padding %d", ErrInvalidPadding, padLen)
	}
	if payloadLen > MaxPayloadLimit || padLen > 65535 {
		return nil, fmt.Errorf("%w: превышен предельный размер конверта (payload=%d, pad=%d)", ErrMTUExceeded, payloadLen, padLen)
	}
	totalPlain := EnvelopeHeaderSizeV5 + payloadLen + padLen
	if totalPlain > 65535 {
		return nil, fmt.Errorf("%w: конверт превышает 65535 байт (%d)", ErrMTUExceeded, totalPlain)
	}
	if maxOuterBytes > 0 && OverheadV5+payloadLen+padLen > maxOuterBytes {
		return nil, fmt.Errorf("%w: внешний кадр %d байт превышает лимит %d байт", ErrMTUExceeded, OverheadV5+payloadLen+padLen, maxOuterBytes)
	}

	out := make([]byte, totalPlain)
	binary.BigEndian.PutUint16(out[0:2], uint16(payloadLen))
	binary.BigEndian.PutUint16(out[2:4], uint16(padLen))
	copy(out[EnvelopeHeaderSizeV5:EnvelopeHeaderSizeV5+payloadLen], plaintext)
	return out, nil
}

// ValidateEnvelopeV5Lengths проверяет длины полей конверта v5 на отрицательность, переполнение, хвост и MTU.
func ValidateEnvelopeV5Lengths(envelopeLen, payloadLen, padLen, maxPayloadLen int) error {
	if envelopeLen < EnvelopeHeaderSizeV5 || payloadLen < 0 || padLen < 0 {
		return fmt.Errorf("%w: некорректные длины конверта (env=%d, payload=%d, pad=%d)", ErrInvalidEnvelope, envelopeLen, payloadLen, padLen)
	}
	if maxPayloadLen > 0 && payloadLen > maxPayloadLen {
		return fmt.Errorf("%w: длина полезной нагрузки %d превышает предел %d", ErrMTUExceeded, payloadLen, maxPayloadLen)
	}
	expectedLen := EnvelopeHeaderSizeV5 + payloadLen + padLen
	if expectedLen > envelopeLen {
		return fmt.Errorf("%w: объявленная длина %d выходит за границы буфера %d", ErrInvalidEnvelope, expectedLen, envelopeLen)
	}
	if expectedLen < envelopeLen {
		return fmt.Errorf("%w: обнаружено %d лишних байт после padding", ErrTrailingData, envelopeLen-expectedLen)
	}
	return nil
}

// UnpackEnvelopeV5 разбирает внутренний конверт v5, проверяет границы, отсутствие лишних байт и нулевое заполнение padding.
func UnpackEnvelopeV5(envelope []byte, maxPayloadLen int) ([]byte, error) {
	if len(envelope) < EnvelopeHeaderSizeV5 {
		return nil, fmt.Errorf("%w: размер расшифрованного конверта %d меньше заголовка", ErrInvalidEnvelope, len(envelope))
	}
	payloadLen := int(binary.BigEndian.Uint16(envelope[0:2]))
	padLen := int(binary.BigEndian.Uint16(envelope[2:4]))
	if err := ValidateEnvelopeV5Lengths(len(envelope), payloadLen, padLen, maxPayloadLen); err != nil {
		return nil, err
	}
	padStart := EnvelopeHeaderSizeV5 + payloadLen
	padEnd := padStart + padLen
	var nonZero byte
	for _, b := range envelope[padStart:padEnd] {
		nonZero |= b
	}
	if nonZero != 0 {
		return nil, ErrInvalidPadding
	}
	return envelope[EnvelopeHeaderSizeV5:padStart], nil
}
