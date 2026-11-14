package argon4

import (
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"math/big"
	"strings"
	"time"
)

const (
	// Поддерживаемые возможности протокола Argon4 v5 и версионированных профилей трафика.
	CapabilityPadV5        = "pad-v5"
	CapabilityProfileV1    = "profile-v1"
	BehaviorProfileVersion = 1

	// Допустимые имена поведенческих профилей трафика Argon4.
	ProfileNone      = ""
	ProfileBaseline  = "baseline"
	ProfileVideoCall = "video-call"

	// Ограничения очередей, задержек, агрегации и длительности для предотвращения деградации и фонового перерасхода.
	DefaultMaxQueuePackets      = 128
	DefaultMaxQueueBytes        = 256 * 1024
	DefaultVideoCallMaxDuration = 2 * time.Hour
	HardMaxAddedDelay           = 30 * time.Millisecond
	MaxBatchCount               = 16
	BatchHeaderSize             = 1
	BatchItemHeaderSize         = 2

	// Служебные полезные нагрузки внутри FrameTypeKeepalive для profile-v1.
	KeepalivePingPayload     = "ping"
	KeepalivePongPayload     = "pong"
	KeepaliveFeedbackPayload = "fb"

	VideoCallWarning = "Профиль video-call увеличивает служебный трафик, добавленную задержку (до ~18 мс) и расход батареи; не предназначен для постоянного фонового режима без необходимости и не скрывает IP сервера."
)

var (
	ErrProfileUnsupported = errors.New("неподдерживаемый поведенческий профиль или несовместимая версия")
	ErrQueueFull          = errors.New("очередь шейпера переполнена (backpressure)")
)

// ProfileConfig задаёт явную пользовательскую конфигурацию поведенческого профиля трафика.
type ProfileConfig struct {
	Name            string        `json:"name,omitempty"`
	Version         int           `json:"version,omitempty"`
	Archetype       string        `json:"archetype,omitempty"`
	MaxAddedDelay   time.Duration `json:"maxAddedDelay,omitempty"`
	CoalesceWindow  time.Duration `json:"coalesceWindow,omitempty"`
	MaxQueuePackets int           `json:"maxQueuePackets,omitempty"`
	MaxQueueBytes   int           `json:"maxQueueBytes,omitempty"`
	MaxDuration     time.Duration `json:"maxDuration,omitempty"`
	AllowVideoCall  *bool         `json:"allowVideoCall,omitempty"`
	LowPowerMode    bool          `json:"lowPowerMode,omitempty"`
}

// ServerProfilePolicy определяет допустимые профили на стороне сервера.
type ServerProfilePolicy struct {
	DefaultProfile string
	AllowVideoCall bool
	MaxDuration    time.Duration
	PathMTU        int
	DefaultPadding PaddingConfig
	DefaultTiming  TimingConfig
}

// PadBin описывает взвешенную корзину желаемого внешнего размера UDP-кадра (в байтах полезной нагрузки UDP).
type PadBin struct {
	Weight   int
	MinOuter int
	MaxOuter int
}

// SessionProfile хранит параметры распределений, очередей и таймингов, выбранные для конкретной сессии.
type SessionProfile struct {
	Name                 string
	Version              int
	Archetype            string
	Padding              PaddingConfig
	TargetMaxOuter       int
	RecommendedTunMTU    int
	CoalesceEnabled      bool
	CoalesceThreshold    int
	CoalesceMaxPackets   int
	CoalesceMaxBytes     int
	CoalesceMinWindow    time.Duration
	CoalesceMaxWindow    time.Duration
	MaxAddedDelay        time.Duration
	MaxQueuePackets      int
	MaxQueueBytes        int
	BurstPackets         int
	SlotMinInterval      time.Duration
	SlotMaxInterval      time.Duration
	KeyframeBurstPackets int
	KeyframeMinInterval  time.Duration
	KeyframeMaxInterval  time.Duration
	FeedbackMinInterval  time.Duration
	FeedbackMaxInterval  time.Duration
	FeedbackIdleMin      time.Duration
	FeedbackIdleMax      time.Duration
	KeepaliveMinIdle     time.Duration
	KeepaliveMaxIdle     time.Duration
	DeepIdleMinKeepalive time.Duration
	DeepIdleMaxKeepalive time.Duration
	MaxDuration          time.Duration
	LowPowerMode         bool
	SmallBins            []PadBin
	MediumBins           []PadBin
	randSrc              io.Reader
}

// IsActive сообщает, включён ли поведенческий профиль поверх базового шифрования v5.
func (p SessionProfile) IsActive() bool {
	return p.Name != "" && p.Version >= BehaviorProfileVersion
}

// HighWatermark возвращает порог очереди, выше которого шейпер сбрасывает задержку pacing для снятия нагрузки.
func (p SessionProfile) HighWatermark() int {
	limit := p.MaxQueuePackets
	if limit <= 0 {
		limit = DefaultMaxQueuePackets
	}
	hw := limit / 2
	if hw < 4 {
		hw = 4
	}
	return hw
}

// CapabilitiesForVersion возвращает список возможностей Argon4 для handshake.
func CapabilitiesForVersion(version byte, profileCapable bool) []string {
	if version != ProtocolVersionV5 {
		return nil
	}
	if profileCapable {
		return []string{CapabilityPadV5, CapabilityProfileV1}
	}
	return []string{CapabilityPadV5}
}

// HasCapability проверяет наличие объявленной возможности в списке capabilities рукопожатия.
func HasCapability(caps []string, name string) bool {
	for _, c := range caps {
		if strings.EqualFold(strings.TrimSpace(c), name) {
			return true
		}
	}
	return false
}

// NormalizeBehaviorProfileName приводит имя профиля к каноническому виду и отвергает неизвестные профили.
func NormalizeBehaviorProfileName(name string) (string, error) {
	switch strings.ToLower(strings.TrimSpace(name)) {
	case "", "none", "off":
		return ProfileNone, nil
	case ProfileBaseline:
		return ProfileBaseline, nil
	case ProfileVideoCall, "videocall", "video_call":
		return ProfileVideoCall, nil
	default:
		return "", fmt.Errorf("%w: неизвестный профиль %q", ErrProfileUnsupported, name)
	}
}

// ValidateProfileRequest проверяет совместимость запрошенного профиля с версией протокола, режимом и L4-транспортом.
func ValidateProfileRequest(version byte, mode, proto string, cfg ProfileConfig) (name string, reqVersion int, err error) {
	name, err = NormalizeBehaviorProfileName(cfg.Name)
	if err != nil {
		return "", 0, err
	}
	if cfg.Version < 0 || cfg.Version > BehaviorProfileVersion {
		return "", 0, fmt.Errorf("%w: версия профиля %d (поддерживается 1)", ErrProfileUnsupported, cfg.Version)
	}
	if cfg.MaxAddedDelay < 0 || cfg.MaxAddedDelay > 200*time.Millisecond {
		return "", 0, fmt.Errorf("%w: некорректный предел добавленной задержки %v", ErrProfileUnsupported, cfg.MaxAddedDelay)
	}
	if cfg.CoalesceWindow < 0 || cfg.CoalesceWindow > 50*time.Millisecond {
		return "", 0, fmt.Errorf("%w: некорректное окно агрегации %v", ErrProfileUnsupported, cfg.CoalesceWindow)
	}
	if cfg.MaxQueuePackets < 0 || cfg.MaxQueueBytes < 0 || cfg.MaxDuration < 0 {
		return "", 0, fmt.Errorf("%w: отрицательные ограничения профиля недопустимы", ErrProfileUnsupported)
	}
	if version == ProtocolVersionV4 && (name != "" || cfg.Version > 0) {
		return "", 0, fmt.Errorf("%w: поведенческие профили требуют Argon4 v5 (недоступны в v4)", ErrProfileUnsupported)
	}
	if cfg.AllowVideoCall != nil && !*cfg.AllowVideoCall && name == ProfileVideoCall {
		return "", 0, fmt.Errorf("%w: профиль %q запрещён локальной политикой", ErrProfileUnsupported, ProfileVideoCall)
	}
	if name == ProfileVideoCall && strings.EqualFold(strings.TrimSpace(proto), ProtoTCP) {
		return "", 0, fmt.Errorf("%w: профиль %q поддерживается только поверх UDP-транспорта и недоступен в TCP-транзите", ErrProfileUnsupported, ProfileVideoCall)
	}
	if name != "" || cfg.Version == BehaviorProfileVersion {
		if name == "" {
			name = ProfileBaseline
		}
		return name, BehaviorProfileVersion, nil
	}
	return ProfileNone, 0, nil
}

// NegotiateServerProfile проверяет запрос клиента в handshake и выбирает совместимый SessionProfile сервера.
func NegotiateServerProfile(headerVersion byte, mode, proto string, req HandshakeRequest, policy ServerProfilePolicy, rng io.Reader) (SessionProfile, error) {
	if headerVersion != ProtocolVersionV5 {
		if req.BehaviorProfile != "" || req.ProfileVersion > 0 || HasCapability(req.Capabilities, CapabilityProfileV1) {
			return SessionProfile{}, fmt.Errorf("%w: профили не поддерживаются в v4", ErrProfileUnsupported)
		}
		return SessionProfile{}, nil
	}
	reqName, err := NormalizeBehaviorProfileName(req.BehaviorProfile)
	if err != nil {
		return SessionProfile{}, err
	}
	if req.ProfileVersion < 0 || req.ProfileVersion > BehaviorProfileVersion {
		return SessionProfile{}, fmt.Errorf("%w: неподдерживаемая версия профиля клиента v%d", ErrProfileUnsupported, req.ProfileVersion)
	}
	if req.ProfileVersion == BehaviorProfileVersion && reqName == "" {
		reqName = ProfileBaseline
	}
	if reqName == "" {
		return SessionProfile{}, nil
	}
	if reqName == ProfileVideoCall {
		if !policy.AllowVideoCall {
			return SessionProfile{}, fmt.Errorf("%w: профиль %q отключён политикой сервера", ErrProfileUnsupported, ProfileVideoCall)
		}
		if mode == ModeTransit && strings.EqualFold(strings.TrimSpace(proto), ProtoTCP) {
			return SessionProfile{}, fmt.Errorf("%w: профиль %q запрещён для TCP-транзита", ErrProfileUnsupported, ProfileVideoCall)
		}
	}
	cfg := ProfileConfig{
		Name:        reqName,
		Version:     BehaviorProfileVersion,
		MaxDuration: policy.MaxDuration,
	}
	return BuildSessionProfile(cfg, policy.DefaultPadding, policy.DefaultTiming, rng)
}

// VerifyClientProfileResponse проверяет ответ сервера и запрещает молчаливый откат запрошенного профиля.
func VerifyClientProfileResponse(reqName string, reqVer int, resp HandshakeResponse, pad PaddingConfig, timing TimingConfig, cfg ProfileConfig, rng io.Reader) (SessionProfile, error) {
	respName, err := NormalizeBehaviorProfileName(resp.BehaviorProfile)
	if err != nil {
		return SessionProfile{}, err
	}
	if reqVer == 0 && reqName == "" {
		if respName != "" {
			return SessionProfile{}, fmt.Errorf("%w: сервер неожиданно навязал профиль %q", ErrProfileUnsupported, respName)
		}
		return SessionProfile{}, nil
	}
	if resp.ProtocolVersion != int(ProtocolVersionV5) || !HasCapability(resp.Capabilities, CapabilityProfileV1) {
		return SessionProfile{}, fmt.Errorf("%w: сервер не поддерживает профили profile-v1", ErrProfileUnsupported)
	}
	if resp.ProfileVersion != reqVer || respName != reqName {
		return SessionProfile{}, fmt.Errorf("%w: несогласованный профиль сервера (%q v%d вместо %q v%d)",
			ErrProfileUnsupported, respName, resp.ProfileVersion, reqName, reqVer)
	}
	buildCfg := cfg
	buildCfg.Name = respName
	buildCfg.Version = resp.ProfileVersion
	return BuildSessionProfile(buildCfg, pad, timing, rng)
}

// BuildSessionProfile формирует профиль сессии со случайным выбором одного из эталонных распределений.
func BuildSessionProfile(cfg ProfileConfig, pad PaddingConfig, timing TimingConfig, rng io.Reader) (SessionProfile, error) {
	name, ver, err := ValidateProfileRequest(ProtocolVersionV5, "", "", ProfileConfig{
		Name:            cfg.Name,
		Version:         cfg.Version,
		MaxAddedDelay:   cfg.MaxAddedDelay,
		CoalesceWindow:  cfg.CoalesceWindow,
		MaxQueuePackets: cfg.MaxQueuePackets,
		MaxQueueBytes:   cfg.MaxQueueBytes,
		MaxDuration:     cfg.MaxDuration,
		AllowVideoCall:  cfg.AllowVideoCall,
	})
	if err != nil {
		return SessionProfile{}, err
	}
	if name == "" && ver == 0 {
		return SessionProfile{}, nil
	}
	if rng == nil {
		rng = rand.Reader
	}
	resolvedPad := ResolvePaddingConfig(pad, true)
	resolvedTiming, err := NormalizeTimingConfig(timing)
	if err != nil {
		resolvedTiming = DefaultTimingConfig()
	}

	var sp SessionProfile
	switch name {
	case ProfileBaseline:
		sp = sampleBaselineArchetype(cfg.Archetype, resolvedPad, resolvedTiming, rng)
	case ProfileVideoCall:
		sp = sampleVideoCallArchetype(cfg.Archetype, resolvedPad, rng)
	default:
		return SessionProfile{}, fmt.Errorf("%w: %q", ErrProfileUnsupported, name)
	}

	if cfg.MaxAddedDelay > 0 {
		sp.MaxAddedDelay = cfg.MaxAddedDelay
	}
	if cfg.CoalesceWindow > 0 {
		sp.CoalesceMinWindow = cfg.CoalesceWindow / 2
		if sp.CoalesceMinWindow < time.Millisecond {
			sp.CoalesceMinWindow = time.Millisecond
		}
		sp.CoalesceMaxWindow = cfg.CoalesceWindow
		if sp.CoalesceMaxWindow > sp.MaxAddedDelay {
			sp.CoalesceMaxWindow = sp.MaxAddedDelay
		}
	}
	if cfg.MaxQueuePackets > 0 {
		sp.MaxQueuePackets = cfg.MaxQueuePackets
	}
	if sp.MaxQueuePackets <= 0 {
		sp.MaxQueuePackets = DefaultMaxQueuePackets
	}
	if cfg.MaxQueueBytes > 0 {
		sp.MaxQueueBytes = cfg.MaxQueueBytes
	}
	if sp.MaxQueueBytes <= 0 {
		sp.MaxQueueBytes = DefaultMaxQueueBytes
	}
	if cfg.MaxDuration > 0 {
		sp.MaxDuration = cfg.MaxDuration
	}
	sp.LowPowerMode = cfg.LowPowerMode
	sp.randSrc = rng
	return sp, nil
}

func sampleBaselineArchetype(requested string, basePad PaddingConfig, timing TimingConfig, rng io.Reader) SessionProfile {
	variants := []struct {
		archetype string
		minPad    int
		maxPad    int
		smallThr  int
		smallMin  int
		smallMax  int
		coalMaxMs int
		delayMs   int
	}{
		{"baseline-balanced-mix", 10, 108, 176, 18, 208, 3, 6},
		{"baseline-compact-mix", 6, 48, 136, 10, 96, 2, 5},
		{"baseline-wide-mix", 16, 184, 240, 28, 288, 4, 8},
	}
	idx := chooseVariantIndex(variants[0].archetype, []string{variants[0].archetype, variants[1].archetype, variants[2].archetype}, requested, rng)
	v := variants[idx]
	pad := PaddingConfig{
		Profile:        basePad.Profile,
		MinPad:         jitterInt(rng, v.minPad, 2),
		MaxPad:         jitterInt(rng, v.maxPad, 8),
		SmallThreshold: jitterInt(rng, v.smallThr, 12),
		SmallMinPad:    jitterInt(rng, v.smallMin, 4),
		SmallMaxPad:    jitterInt(rng, v.smallMax, 12),
		PathMTU:        basePad.PathMTU,
	}
	if pad.Profile == "" {
		pad.Profile = PaddingProfileBalanced
	}
	if pad.MaxPad <= pad.MinPad {
		pad.MaxPad = pad.MinPad + 8
	}
	if pad.SmallMaxPad <= pad.SmallMinPad {
		pad.SmallMaxPad = pad.SmallMinPad + 8
	}
	maxOuter := MaxOuterBytesForPathMTU(pad.PathMTU)
	return SessionProfile{
		Name:               ProfileBaseline,
		Version:            BehaviorProfileVersion,
		Archetype:          v.archetype,
		Padding:            pad,
		TargetMaxOuter:     maxOuter,
		CoalesceEnabled:    true,
		CoalesceThreshold:  pad.SmallThreshold,
		CoalesceMaxPackets: 6,
		CoalesceMaxBytes:   900,
		CoalesceMinWindow:  time.Duration(jitterInt(rng, 1, 0)) * time.Millisecond,
		CoalesceMaxWindow:  time.Duration(v.coalMaxMs) * time.Millisecond,
		MaxAddedDelay:      time.Duration(v.delayMs) * time.Millisecond,
		MaxQueuePackets:    DefaultMaxQueuePackets,
		MaxQueueBytes:      DefaultMaxQueueBytes,
		KeepaliveMinIdle:   timing.KeepaliveMinIdle,
		KeepaliveMaxIdle:   timing.KeepaliveMaxIdle,
		SmallBins: []PadBin{
			{Weight: 70, MinOuter: OverheadV5 + 48 + pad.SmallMinPad, MaxOuter: OverheadV5 + 80 + pad.SmallMaxPad},
			{Weight: 30, MinOuter: OverheadV5 + 160, MaxOuter: OverheadV5 + 320},
		},
	}
}

// sampleVideoCallArchetype выбирает распределение сеанса из эмпирических моделей Яндекс Телемоста (pcap video-call).
func sampleVideoCallArchetype(requested string, basePad PaddingConfig, rng io.Reader) SessionProfile {
	type vcVariant struct {
		archetype         string
		targetMaxOuter    int
		recommendedTunMTU int
		coalesceMaxMs     int
		maxAddedDelayMs   int
		burstPackets      int
		slotMinMs         int
		slotMaxMs         int
		kfBurstPackets    int
		kfMinSec          int
		kfMaxSec          int
		fbMinMs           int
		fbMaxMs           int
		fbIdleMinMs       int
		fbIdleMaxMs       int
		kaMinMs           int
		kaMaxMs           int
	}
	variants := []vcVariant{
		// Модель активного SFU видеозвонка (видео PT=96 max=1210B UDP, аудио PT=111 20ms, RTX/probe 285B, STUN ~2.5s).
		{"telemost-av-active", 1210, 1140, 4, 15, 7, 32, 48, 16, 3, 5, 95, 195, 480, 1050, 1900, 3000},
		// Динамичная сцена HD (частые ключевые кадры и более короткий цикл кадров).
		{"telemost-hd-dynamic", 1228, 1150, 5, 16, 9, 26, 42, 18, 2, 4, 80, 175, 420, 920, 1800, 2800},
		// Статичная камера / сниженный битрейт (меньшие всплески и больше компактных/сервисных кадров).
		{"telemost-low-cam", 1184, 1120, 5, 18, 5, 38, 58, 12, 4, 6, 120, 250, 560, 1180, 2100, 3300},
	}
	idx := chooseVariantIndex(variants[0].archetype, []string{variants[0].archetype, variants[1].archetype, variants[2].archetype}, requested, rng)
	v := variants[idx]

	physMaxOuter := MaxOuterBytesForPathMTU(basePad.PathMTU)
	targetMaxOuter := jitterInt(rng, v.targetMaxOuter, 12)
	if physMaxOuter > 0 && targetMaxOuter > physMaxOuter {
		targetMaxOuter = physMaxOuter
	}
	tunMTU := EffectiveTunMTU(v.recommendedTunMTU, minPositive(basePad.PathMTU, targetMaxOuter+IPv4UDPOverhead), ProtocolVersionV5)

	pad := PaddingConfig{
		Profile:        basePad.Profile,
		MinPad:         8,
		MaxPad:         68,
		SmallThreshold: 160,
		SmallMinPad:    18,
		SmallMaxPad:    140,
		PathMTU:        basePad.PathMTU,
	}
	if pad.Profile == "" {
		pad.Profile = PaddingProfileBalanced
	}

	return SessionProfile{
		Name:                 ProfileVideoCall,
		Version:              BehaviorProfileVersion,
		Archetype:            v.archetype,
		Padding:              pad,
		TargetMaxOuter:       targetMaxOuter,
		RecommendedTunMTU:    tunMTU,
		CoalesceEnabled:      true,
		CoalesceThreshold:    180,
		CoalesceMaxPackets:   8,
		CoalesceMaxBytes:     minPositive(1060, targetMaxOuter-OverheadV5-16),
		CoalesceMinWindow:    2 * time.Millisecond,
		CoalesceMaxWindow:    time.Duration(v.coalesceMaxMs) * time.Millisecond,
		MaxAddedDelay:        time.Duration(v.maxAddedDelayMs) * time.Millisecond,
		MaxQueuePackets:      DefaultMaxQueuePackets,
		MaxQueueBytes:        DefaultMaxQueueBytes,
		BurstPackets:         jitterInt(rng, v.burstPackets, 1),
		SlotMinInterval:      time.Duration(v.slotMinMs) * time.Millisecond,
		SlotMaxInterval:      time.Duration(v.slotMaxMs) * time.Millisecond,
		KeyframeBurstPackets: jitterInt(rng, v.kfBurstPackets, 2),
		KeyframeMinInterval:  time.Duration(v.kfMinSec) * time.Second,
		KeyframeMaxInterval:  time.Duration(v.kfMaxSec) * time.Second,
		FeedbackMinInterval:  time.Duration(v.fbMinMs) * time.Millisecond,
		FeedbackMaxInterval:  time.Duration(v.fbMaxMs) * time.Millisecond,
		FeedbackIdleMin:      time.Duration(v.fbIdleMinMs) * time.Millisecond,
		FeedbackIdleMax:      time.Duration(v.fbIdleMaxMs) * time.Millisecond,
		KeepaliveMinIdle:     time.Duration(v.kaMinMs) * time.Millisecond,
		KeepaliveMaxIdle:     time.Duration(v.kaMaxMs) * time.Millisecond,
		DeepIdleMinKeepalive: 4 * time.Second,
		DeepIdleMaxKeepalive: 10 * time.Second,
		MaxDuration:          DefaultVideoCallMaxDuration,
		SmallBins: []PadBin{
			// Кластер аудио Opus 20ms и RTCP-ответов (63..197 байт UDP payload в дампе Телемоста).
			{Weight: 56, MinOuter: 92, MaxOuter: 196},
			// Характерная мода 285..340 байт видеопроб/RTX/входящего audio high в дампе Телемоста.
			{Weight: 28, MinOuter: 278, MaxOuter: 342},
			// Средний видеосрез.
			{Weight: 16, MinOuter: 420, MaxOuter: 740},
		},
		MediumBins: []PadBin{
			{Weight: 32, MinOuter: 620, MaxOuter: 900},
			{Weight: 68, MinOuter: 900, MaxOuter: minPositive(1180, targetMaxOuter)},
		},
	}
}

// EffectiveProfileTunMTU возвращает безопасный MTU TUN для профиля, не снижая его ниже минимального IPv4 MTU.
func EffectiveProfileTunMTU(requestedMTU, pathMTU int, version byte, prof SessionProfile) int {
	base := EffectiveTunMTU(requestedMTU, pathMTU, version)
	if prof.RecommendedTunMTU > 0 && prof.RecommendedTunMTU < base {
		return prof.RecommendedTunMTU
	}
	return base
}

// DowngradeToBaseline формирует эффективный профиль baseline при истечении срока действия video-call.
func (p SessionProfile) DowngradeToBaseline(rng io.Reader) SessionProfile {
	if rng == nil {
		rng = p.randSrc
	}
	if rng == nil {
		rng = rand.Reader
	}
	basePad := PaddingConfig{
		Profile: p.Padding.Profile,
		PathMTU: p.Padding.PathMTU,
	}
	if basePad.Profile == "" || basePad.Profile == PaddingProfileCustom {
		basePad.Profile = PaddingProfileBalanced
	}
	basePad = ResolvePaddingConfig(basePad, true)

	timing := DefaultTimingConfig()
	if p.KeepaliveMinIdle >= 10*time.Second && p.KeepaliveMaxIdle > p.KeepaliveMinIdle {
		timing.KeepaliveMinIdle = p.KeepaliveMinIdle
		timing.KeepaliveMaxIdle = p.KeepaliveMaxIdle
	}

	down := sampleBaselineArchetype("", basePad, timing, rng)
	down.Version = BehaviorProfileVersion
	if p.Version > down.Version {
		down.Version = p.Version
	}
	if p.MaxAddedDelay > 0 && p.MaxAddedDelay < down.MaxAddedDelay {
		down.MaxAddedDelay = p.MaxAddedDelay
		if down.CoalesceMaxWindow > down.MaxAddedDelay {
			down.CoalesceMaxWindow = down.MaxAddedDelay
		}
		if down.CoalesceMinWindow > down.CoalesceMaxWindow {
			down.CoalesceMinWindow = down.CoalesceMaxWindow
		}
	}
	if p.MaxQueuePackets > 0 {
		down.MaxQueuePackets = p.MaxQueuePackets
	}
	if p.MaxQueueBytes > 0 {
		down.MaxQueueBytes = p.MaxQueueBytes
	}
	down.LowPowerMode = p.LowPowerMode
	down.MaxDuration = 0
	down.randSrc = rng
	return down
}

// SamplePaddingLen выбирает переменную длину padding из мультимодальных корзин профиля в рамках path MTU.
func (p SessionProfile) SamplePaddingLen(payloadLen, maxOuterBytes int) (int, error) {
	if payloadLen < 0 {
		return 0, fmt.Errorf("%w: отрицательная длина payload: %d", ErrInvalidEnvelope, payloadLen)
	}
	effectiveOuter := maxOuterBytes
	if effectiveOuter <= 0 {
		effectiveOuter = MaxOuterBytesForPathMTU(p.Padding.PathMTU)
	}
	budget := effectiveOuter - OverheadV5 - payloadLen
	if budget < 0 || payloadLen > MaxPayloadLimit {
		return 0, fmt.Errorf("%w: payload (%d байт) превышает лимит кадра (%d байт)", ErrMTUExceeded, payloadLen, effectiveOuter)
	}
	if !p.IsActive() {
		return SamplePaddingLen(p.Padding, payloadLen, effectiveOuter)
	}

	rng := p.randSrc
	if rng == nil {
		rng = rand.Reader
	}

	targetOuter := effectiveOuter
	if p.TargetMaxOuter > 0 && p.TargetMaxOuter < targetOuter && OverheadV5+payloadLen+8 <= p.TargetMaxOuter {
		targetOuter = p.TargetMaxOuter
	}
	targetBudget := targetOuter - OverheadV5 - payloadLen
	if targetBudget > budget {
		targetBudget = budget
	}
	if targetBudget <= 0 {
		return 0, nil
	}

	var lo, hi int
	switch {
	case payloadLen <= 140 && len(p.SmallBins) > 0:
		bin := pickWeightedBin(rng, p.SmallBins)
		minFrame := OverheadV5 + payloadLen + 6
		binLo := bin.MinOuter
		binHi := bin.MaxOuter
		if binLo < minFrame {
			binLo = minFrame
		}
		if binHi < binLo {
			binHi = binLo + 16
		}
		lo = binLo - (OverheadV5 + payloadLen)
		hi = binHi - (OverheadV5 + payloadLen)
	case payloadLen <= 750 && len(p.MediumBins) > 0:
		bin := pickWeightedBin(rng, p.MediumBins)
		lo = bin.MinOuter - (OverheadV5 + payloadLen)
		hi = bin.MaxOuter - (OverheadV5 + payloadLen)
		if lo < 8 {
			lo = 8
		}
		if hi <= lo {
			hi = lo + 48
		}
	default:
		lo = p.Padding.MinPad
		hi = p.Padding.MaxPad
		if lo > targetBudget {
			lo = 1
		}
	}

	if lo < 0 {
		lo = 0
	}
	if hi > targetBudget {
		hi = targetBudget
	}
	if lo > hi {
		lo = hi
	}
	if hi <= lo {
		return lo, nil
	}
	span := hi - lo + 1
	n, err := rand.Int(rng, big.NewInt(int64(span)))
	if err != nil {
		return 0, err
	}
	return lo + int(n.Int64()), nil
}

// PackBatchPlaintext упаковывает от 2 до MaxBatchCount мелких пакетов в один контейнер FrameTypeBatch.
func PackBatchPlaintext(packets [][]byte) ([]byte, error) {
	if len(packets) < 2 || len(packets) > MaxBatchCount {
		return nil, fmt.Errorf("%w: размер пакета агрегации %d (допустимо 2..%d)", ErrInvalidEnvelope, len(packets), MaxBatchCount)
	}
	total := BatchHeaderSize
	for _, pkt := range packets {
		if len(pkt) == 0 || len(pkt) > MaxPayloadLimit {
			return nil, fmt.Errorf("%w: некорректная длина вложенного пакета %d", ErrInvalidEnvelope, len(pkt))
		}
		total += BatchItemHeaderSize + len(pkt)
	}
	if total > MaxPayloadLimit {
		return nil, fmt.Errorf("%w: суммарная длина агрегированного кадра %d превышает лимит", ErrMTUExceeded, total)
	}
	out := make([]byte, total)
	out[0] = byte(len(packets))
	off := BatchHeaderSize
	for _, pkt := range packets {
		binary.BigEndian.PutUint16(out[off:off+2], uint16(len(pkt)))
		copy(out[off+2:], pkt)
		off += BatchItemHeaderSize + len(pkt)
	}
	return out, nil
}

// UnpackBatchPlaintext разбирает и строго проверяет контейнер FrameTypeBatch.
func UnpackBatchPlaintext(plaintext []byte, maxPayloadLen int) ([][]byte, error) {
	if maxPayloadLen > 0 && len(plaintext) > maxPayloadLen {
		return nil, fmt.Errorf("%w: агрегированный кадр %d байт превышает предел %d", ErrMTUExceeded, len(plaintext), maxPayloadLen)
	}
	if len(plaintext) < BatchHeaderSize+BatchItemHeaderSize+1 {
		return nil, fmt.Errorf("%w: агрегированный кадр слишком короткий (%d)", ErrInvalidEnvelope, len(plaintext))
	}
	count := int(plaintext[0])
	if count < 2 || count > MaxBatchCount {
		return nil, fmt.Errorf("%w: недопустимое число элементов batch %d", ErrInvalidEnvelope, count)
	}
	out := make([][]byte, 0, count)
	off := BatchHeaderSize
	for i := 0; i < count; i++ {
		if off+BatchItemHeaderSize > len(plaintext) {
			return nil, fmt.Errorf("%w: обрезан заголовок элемента batch #%d", ErrInvalidEnvelope, i)
		}
		itemLen := int(binary.BigEndian.Uint16(plaintext[off : off+2]))
		off += BatchItemHeaderSize
		if itemLen <= 0 || off+itemLen > len(plaintext) {
			return nil, fmt.Errorf("%w: некорректная длина элемента batch #%d (%d)", ErrInvalidEnvelope, i, itemLen)
		}
		pkt := make([]byte, itemLen)
		copy(pkt, plaintext[off:off+itemLen])
		out = append(out, pkt)
		off += itemLen
	}
	if off != len(plaintext) {
		return nil, fmt.Errorf("%w: лишние %d байт в batch-контейнере", ErrTrailingData, len(plaintext)-off)
	}
	return out, nil
}

func chooseVariantIndex(defaultName string, names []string, requested string, rng io.Reader) int {
	req := strings.ToLower(strings.TrimSpace(requested))
	if req != "" {
		for i, name := range names {
			if strings.EqualFold(name, req) {
				return i
			}
		}
	}
	if rng == nil {
		rng = rand.Reader
	}
	n, err := rand.Int(rng, big.NewInt(int64(len(names))))
	if err != nil {
		return 0
	}
	return int(n.Int64())
}

func pickWeightedBin(rng io.Reader, bins []PadBin) PadBin {
	total := 0
	for _, b := range bins {
		if b.Weight > 0 {
			total += b.Weight
		}
	}
	if total <= 0 {
		return bins[0]
	}
	n, err := rand.Int(rng, big.NewInt(int64(total)))
	if err != nil {
		return bins[0]
	}
	pick := int(n.Int64())
	acc := 0
	for _, b := range bins {
		if b.Weight <= 0 {
			continue
		}
		acc += b.Weight
		if pick < acc {
			return b
		}
	}
	return bins[len(bins)-1]
}

func jitterInt(rng io.Reader, base, spread int) int {
	if spread <= 0 || rng == nil {
		return base
	}
	n, err := rand.Int(rng, big.NewInt(int64(spread*2+1)))
	if err != nil {
		return base
	}
	val := base - spread + int(n.Int64())
	if val < 1 {
		return 1
	}
	return val
}

func minPositive(a, b int) int {
	if a <= 0 {
		return b
	}
	if b <= 0 || a < b {
		return a
	}
	return b
}
