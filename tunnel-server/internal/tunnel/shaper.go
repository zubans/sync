package tunnel

import (
	"errors"
	"io"
	"log"
	"net"
	"sync"
	"sync/atomic"
	"time"
)

type queuedPacket struct {
	payload    []byte
	enqueuedAt time.Time
	coalesceAt time.Time
	deadline   time.Time
}

type sendItem struct {
	frameType byte
	payload   []byte
	batched   int
	isPing    bool
}

// ShaperStats содержит агрегированные счётчики работы поведенческого шейпера (без per-packet логов).
type ShaperStats struct {
	ActiveProfile       string
	ActiveArchetype     string
	SentDataFrames      uint64
	SentBatchFrames     uint64
	CoalescedPackets    uint64
	SentControlFrames   uint64
	SentKeepalives      uint64
	DroppedBackpressure uint64
	QueueDepth          int
	QueuedBytes         int
	ProfileDowngraded   bool
}

// ShaperOptions настраивает планировщик пакетов с ограниченной очередью, приоритетом данных и таймером простоя.
type ShaperOptions struct {
	Profile          SessionProfile
	Salt             []byte
	PSK              []byte
	Key              []byte
	Version          byte
	MaxOuterBytes    int
	NextSeq          func() uint64
	WriteWire        func(wire []byte) error
	Clock            Clock
	Tracker          *ActivityTracker
	RandUnit         func() float64
	KeepaliveEnabled bool
	IsServer         bool
	OnFatalError     func(error)
}

// TrafficShaper управляет очередью отправки Argon4 v5 для заданного поведенческого профиля.
type TrafficShaper struct {
	opts            ShaperOptions
	profile         SessionProfile
	downgradeTarget SessionProfile
	clock           Clock
	tracker         *ActivityTracker
	randUnit        func() float64
	startedAt       time.Time
	salt            []byte
	psk             []byte
	key             []byte
	maxOuter        int

	mu          sync.Mutex
	closed      bool
	started     bool
	expired     bool
	realQueue   []queuedPacket
	queuedBytes int
	ctrlQueue   []string
	lastRealIn  time.Time
	lastRealOut time.Time
	lastAnyOut  time.Time
	tokens      int
	nextSlotAt  time.Time
	nextKfAt    time.Time
	nextFbAt    time.Time
	nextKaAt    time.Time

	notifyCh chan struct{}
	stopCh   chan struct{}
	doneCh   chan struct{}

	sentDataFrames      atomic.Uint64
	sentBatchFrames     atomic.Uint64
	coalescedPackets    atomic.Uint64
	sentControlFrames   atomic.Uint64
	sentKeepalives      atomic.Uint64
	droppedBackpressure atomic.Uint64
	profileDowngraded   atomic.Bool
}

type unitRandReader struct {
	fn func() float64
}

func (r unitRandReader) Read(p []byte) (int, error) {
	if len(p) == 0 {
		return 0, nil
	}
	v := r.fn()
	if v < 0 {
		v = 0
	} else if v >= 1 {
		v = 0.999999
	}
	b := byte(v * 256.0)
	for i := range p {
		p[i] = b
	}
	return len(p), nil
}

// NewTrafficShaper создаёт поведенческий шейпер; вызов Start запускает фоновую горутину отправки.
func NewTrafficShaper(opts ShaperOptions) *TrafficShaper {
	clk := opts.Clock
	if clk == nil {
		clk = RealClock{}
	}
	randFn := opts.RandUnit
	if randFn == nil {
		randFn = cryptoRandUnit
	}
	tracker := opts.Tracker
	if tracker == nil {
		tracker = NewActivityTracker(clk)
	}
	prof := opts.Profile
	if prof.MaxQueuePackets <= 0 {
		prof.MaxQueuePackets = DefaultMaxQueuePackets
	}
	if prof.MaxQueueBytes <= 0 {
		prof.MaxQueueBytes = DefaultMaxQueueBytes
	}
	if prof.CoalesceMaxPackets <= 0 {
		prof.CoalesceMaxPackets = 6
	}
	if prof.CoalesceMaxBytes <= 0 {
		prof.CoalesceMaxBytes = 900
	}
	maxOuter := opts.MaxOuterBytes
	if maxOuter <= 0 {
		maxOuter = MaxOuterBytesForPathMTU(prof.Padding.PathMTU)
	}
	var downgradeRng io.Reader = prof.randSrc
	if downgradeRng == nil && opts.RandUnit != nil {
		downgradeRng = unitRandReader{fn: randFn}
	}
	downgradeTarget := prof.DowngradeToBaseline(downgradeRng)

	now := clk.Now()
	initTokens := prof.BurstPackets
	var nextKf time.Time
	if prof.KeyframeMinInterval > 0 && prof.KeyframeMaxInterval >= prof.KeyframeMinInterval {
		nextKf = now.Add(RandomDurationBetween(prof.KeyframeMinInterval, prof.KeyframeMaxInterval, randFn))
	}

	return &TrafficShaper{
		opts:            opts,
		profile:         prof,
		downgradeTarget: downgradeTarget,
		clock:           clk,
		tracker:         tracker,
		randUnit:        randFn,
		startedAt:       now,
		salt:            append([]byte(nil), opts.Salt...),
		psk:             append([]byte(nil), opts.PSK...),
		key:             append([]byte(nil), opts.Key...),
		maxOuter:        maxOuter,
		tokens:          initTokens,
		nextSlotAt:      now,
		nextKfAt:        nextKf,
		lastAnyOut:      now,
		notifyCh:        make(chan struct{}, 1),
		stopCh:          make(chan struct{}),
		doneCh:          make(chan struct{}),
	}
}

// Start запускает рабочий цикл планировщика. Повторный вызов безопасен.
func (s *TrafficShaper) Start() {
	if s == nil {
		return
	}
	s.mu.Lock()
	if s.started || s.closed {
		s.mu.Unlock()
		return
	}
	s.started = true
	s.mu.Unlock()
	go s.runLoop()
}

// Close останавливает планировщик и дожидается завершения фоновой горутины без утечек.
func (s *TrafficShaper) Close() {
	if s == nil {
		return
	}
	s.mu.Lock()
	alreadyClosed := s.closed
	wasStarted := s.started
	s.closed = true
	s.realQueue = nil
	s.queuedBytes = 0
	s.ctrlQueue = nil
	s.mu.Unlock()

	if !alreadyClosed {
		close(s.stopCh)
	}
	if wasStarted {
		<-s.doneCh
	} else if !alreadyClosed {
		close(s.doneCh)
	}
}

// Done возвращает канал завершения рабочего цикла шейпера.
func (s *TrafficShaper) Done() <-chan struct{} {
	if s == nil {
		ch := make(chan struct{})
		close(ch)
		return ch
	}
	return s.doneCh
}

// EnqueueReal ставит реальный IP/L4 пакет в ограниченную приоритетную очередь.
// При переполнении очереди немедленно применяет backpressure (ErrQueueFull) без безграничного роста памяти.
func (s *TrafficShaper) EnqueueReal(pkt []byte) error {
	if s == nil {
		return net.ErrClosed
	}
	now := s.clock.Now()
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return net.ErrClosed
	}
	s.maybeExpireLocked(now)
	if len(s.realQueue) >= s.profile.MaxQueuePackets || (len(s.realQueue) > 0 && s.queuedBytes+len(pkt) > s.profile.MaxQueueBytes) {
		s.mu.Unlock()
		s.droppedBackpressure.Add(1)
		s.signalWake()
		return ErrQueueFull
	}

	copied := make([]byte, len(pkt))
	copy(copied, pkt)

	maxDelay := s.profile.MaxAddedDelay
	deadline := now.Add(maxDelay)
	coalesceAt := now
	if s.profile.CoalesceEnabled && len(copied) <= s.profile.CoalesceThreshold && s.profile.CoalesceMaxWindow > 0 {
		cw := RandomDurationBetween(s.profile.CoalesceMinWindow, s.profile.CoalesceMaxWindow, s.randUnit)
		coalesceAt = now.Add(cw)
		if coalesceAt.After(deadline) {
			coalesceAt = deadline
		}
	}

	s.realQueue = append(s.realQueue, queuedPacket{
		payload:    copied,
		enqueuedAt: now,
		coalesceAt: coalesceAt,
		deadline:   deadline,
	})
	s.queuedBytes += len(copied)
	s.lastRealOut = now
	s.lastAnyOut = now
	s.nextKaAt = time.Time{}
	s.mu.Unlock()

	s.tracker.Touch()
	s.signalWake()
	return nil
}

// RequestControl ставит низкоприоритетный служебный кадр ("ping" или "fb"), отправляемый только при отсутствии реальных пакетов.
func (s *TrafficShaper) RequestControl(payload string) error {
	if s == nil {
		return net.ErrClosed
	}
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return net.ErrClosed
	}
	if len(s.ctrlQueue) >= 8 {
		s.mu.Unlock()
		return nil
	}
	s.ctrlQueue = append(s.ctrlQueue, payload)
	s.mu.Unlock()
	s.signalWake()
	return nil
}

// RecordInboundActivity отмечает приём подлинного входящего пакета от удалённой стороны (для модели обратной связи).
func (s *TrafficShaper) RecordInboundActivity() {
	if s == nil {
		return
	}
	now := s.clock.Now()
	s.mu.Lock()
	if !s.closed {
		s.lastRealIn = now
	}
	s.mu.Unlock()
	s.signalWake()
}

// EffectiveProfile возвращает текущий активный поведенческий профиль (с учётом снижения до baseline).
func (s *TrafficShaper) EffectiveProfile() SessionProfile {
	if s == nil {
		return SessionProfile{}
	}
	s.mu.Lock()
	s.maybeExpireLocked(s.clock.Now())
	prof := s.profile
	s.mu.Unlock()
	return prof
}

// Stats возвращает текущую агрегированную статистику шейпера.
func (s *TrafficShaper) Stats() ShaperStats {
	if s == nil {
		return ShaperStats{}
	}
	s.mu.Lock()
	s.maybeExpireLocked(s.clock.Now())
	activeProf := s.profile.Name
	activeArch := s.profile.Archetype
	depth := len(s.realQueue)
	qBytes := s.queuedBytes
	s.mu.Unlock()
	return ShaperStats{
		ActiveProfile:       activeProf,
		ActiveArchetype:     activeArch,
		SentDataFrames:      s.sentDataFrames.Load(),
		SentBatchFrames:     s.sentBatchFrames.Load(),
		CoalescedPackets:    s.coalescedPackets.Load(),
		SentControlFrames:   s.sentControlFrames.Load(),
		SentKeepalives:      s.sentKeepalives.Load(),
		DroppedBackpressure: s.droppedBackpressure.Load(),
		QueueDepth:          depth,
		QueuedBytes:         qBytes,
		ProfileDowngraded:   s.profileDowngraded.Load(),
	}
}

func (s *TrafficShaper) signalWake() {
	select {
	case s.notifyCh <- struct{}{}:
	default:
	}
}

func (s *TrafficShaper) runLoop() {
	defer close(s.doneCh)

	timer := s.clock.NewTimer(time.Hour)
	stopAndDrainTimer(timer)
	defer timer.Stop()

	for {
		item, prof, wait, stop := s.nextStep(s.clock.Now())
		if stop {
			return
		}
		if item != nil {
			if err := s.emitItem(item, prof); err != nil {
				if IsFatalKeepaliveError(err) {
					if s.opts.OnFatalError != nil {
						s.opts.OnFatalError(err)
					}
					return
				}
			}
			continue
		}
		if wait <= 0 {
			wait = 5 * time.Millisecond
		}
		resetClockTimer(timer, wait)
		select {
		case <-s.stopCh:
			return
		case <-s.notifyCh:
			stopAndDrainTimer(timer)
		case <-timer.C():
		}
	}
}

func (s *TrafficShaper) nextStep(now time.Time) (*sendItem, SessionProfile, time.Duration, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.closed {
		return nil, SessionProfile{}, 0, true
	}

	s.maybeExpireLocked(now)
	prof := s.profile

	if len(s.realQueue) > 0 {
		item, wait := s.stepRealLocked(now)
		return item, prof, wait, false
	}

	if len(s.ctrlQueue) > 0 {
		payload := s.ctrlQueue[0]
		s.ctrlQueue = s.ctrlQueue[1:]
		s.lastAnyOut = now
		isPing := payload == KeepalivePingPayload
		return &sendItem{
			frameType: FrameTypeKeepalive,
			payload:   []byte(payload),
			isPing:    isPing,
		}, prof, 0, false
	}

	return s.stepIdleLocked(now), prof, s.computeIdleWaitLocked(now), false
}

func (s *TrafficShaper) maybeExpireLocked(now time.Time) {
	if s.profile.MaxDuration <= 0 || s.expired || now.Before(s.startedAt.Add(s.profile.MaxDuration)) {
		return
	}
	prevName := s.profile.Name
	maxDur := s.profile.MaxDuration
	s.expired = true
	s.profile = s.downgradeTarget
	s.ctrlQueue = nil
	s.nextFbAt = time.Time{}
	s.nextKaAt = time.Time{}
	s.tokens = 0
	s.nextSlotAt = time.Time{}
	s.nextKfAt = time.Time{}
	s.profileDowngraded.Store(true)
	log.Printf("[argon4-profile] время действия профиля %q истекло (%v); профиль снижен до %q (%s)",
		prevName, maxDur, s.profile.Name, s.profile.Archetype)
}

func (s *TrafficShaper) stepRealLocked(now time.Time) (*sendItem, time.Duration) {
	head := s.realQueue[0]
	effectiveVC := s.profile.Name == ProfileVideoCall && !s.expired

	// Восполняем бюджет всплесков/ключевых кадров по текущему расписанию слотов.
	if effectiveVC && s.profile.BurstPackets > 0 && !now.Before(s.nextSlotAt) {
		if !s.nextKfAt.IsZero() && !now.Before(s.nextKfAt) {
			s.tokens = s.profile.KeyframeBurstPackets
			s.nextKfAt = now.Add(RandomDurationBetween(s.profile.KeyframeMinInterval, s.profile.KeyframeMaxInterval, s.randUnit))
		} else {
			s.tokens = s.profile.BurstPackets
		}
		s.nextSlotAt = now.Add(RandomDurationBetween(s.profile.SlotMinInterval, s.profile.SlotMaxInterval, s.randUnit))
	}

	highWatermark := s.profile.HighWatermark()
	mustFlushNow := !now.Before(head.deadline) ||
		len(s.realQueue) >= highWatermark ||
		len(s.realQueue) >= s.profile.CoalesceMaxPackets ||
		len(head.payload) > s.profile.CoalesceThreshold

	if !mustFlushNow && s.profile.CoalesceEnabled && s.allCoalesceableLocked() && now.Before(head.coalesceAt) {
		return nil, head.coalesceAt.Sub(now)
	}

	if effectiveVC && !mustFlushNow && s.profile.BurstPackets > 0 && s.tokens <= 0 && now.Before(s.nextSlotAt) {
		wait := s.nextSlotAt.Sub(now)
		if !head.deadline.IsZero() && head.deadline.After(now) {
			rem := head.deadline.Sub(now)
			if rem < wait {
				wait = rem
			}
		} else {
			wait = 0
		}
		if wait > 0 {
			return nil, wait
		}
	}

	item := s.popRealLocked(now)
	if effectiveVC && s.tokens > 0 {
		s.tokens--
	}
	s.lastAnyOut = now
	s.nextKaAt = time.Time{}
	return item, 0
}

func (s *TrafficShaper) allCoalesceableLocked() bool {
	if len(s.realQueue) == 0 || len(s.realQueue) >= s.profile.CoalesceMaxPackets {
		return false
	}
	total := 0
	for _, item := range s.realQueue {
		if len(item.payload) > s.profile.CoalesceThreshold {
			return false
		}
		total += len(item.payload)
	}
	return total < s.profile.CoalesceMaxBytes
}

func (s *TrafficShaper) popRealLocked(now time.Time) *sendItem {
	head := s.realQueue[0]
	if s.profile.CoalesceEnabled && len(s.realQueue) >= 2 && len(head.payload) <= s.profile.CoalesceThreshold {
		maxOuter := s.profile.TargetMaxOuter
		if s.maxOuter > 0 && (maxOuter == 0 || s.maxOuter < maxOuter) {
			maxOuter = s.maxOuter
		}
		maxBatchRaw := s.profile.CoalesceMaxBytes
		if avail := maxOuter - OverheadV5 - 12; avail > 0 && avail < maxBatchRaw {
			maxBatchRaw = avail
		}

		batch := make([][]byte, 0, s.profile.CoalesceMaxPackets)
		batchBytes := BatchHeaderSize
		consumed := 0
		for i := 0; i < len(s.realQueue) && len(batch) < s.profile.CoalesceMaxPackets; i++ {
			cand := s.realQueue[i]
			if len(cand.payload) > s.profile.CoalesceThreshold {
				break
			}
			needed := BatchItemHeaderSize + len(cand.payload)
			if len(batch) > 0 && batchBytes+needed > maxBatchRaw {
				break
			}
			batch = append(batch, cand.payload)
			batchBytes += needed
			consumed += len(cand.payload)
		}
		if len(batch) >= 2 {
			if encoded, err := PackBatchPlaintext(batch); err == nil {
				s.realQueue = s.realQueue[len(batch):]
				s.queuedBytes -= consumed
				s.lastRealOut = now
				return &sendItem{
					frameType: FrameTypeBatch,
					payload:   encoded,
					batched:   len(batch),
				}
			}
		}
	}

	s.realQueue = s.realQueue[1:]
	s.queuedBytes -= len(head.payload)
	s.lastRealOut = now
	return &sendItem{
		frameType: FrameTypeData,
		payload:   head.payload,
	}
}

func (s *TrafficShaper) stepIdleLocked(now time.Time) *sendItem {
	// Приоритет 3а: обратная связь (RTCP/DTX-модель) при активном или недавнем вызове video-call.
	if s.shouldEmitVideoFeedback(now) {
		if s.nextFbAt.IsZero() {
			s.scheduleFeedbackLocked(now)
			return nil
		}
		if !now.Before(s.nextFbAt) {
			s.lastAnyOut = now
			s.scheduleFeedbackLocked(now)
			return &sendItem{
				frameType: FrameTypeKeepalive,
				payload:   []byte(KeepaliveFeedbackPayload),
				isPing:    false,
			}
		}
	}

	// Приоритет 3б: нормализованный keepalive простоя.
	if s.opts.KeepaliveEnabled {
		if s.nextKaAt.IsZero() {
			s.scheduleKeepaliveLocked(now)
			return nil
		}
		if !now.Before(s.nextKaAt) {
			s.lastAnyOut = now
			s.scheduleKeepaliveLocked(now)
			return &sendItem{
				frameType: FrameTypeKeepalive,
				payload:   []byte(KeepalivePingPayload),
				isPing:    true,
			}
		}
	}
	return nil
}

func (s *TrafficShaper) computeIdleWaitLocked(now time.Time) time.Duration {
	var best time.Duration
	consider := func(t time.Time) {
		if t.IsZero() {
			return
		}
		d := t.Sub(now)
		if d < 0 {
			d = 0
		}
		if best == 0 || d < best {
			best = d
		}
	}

	if s.shouldEmitVideoFeedback(now) {
		if s.nextFbAt.IsZero() {
			s.scheduleFeedbackLocked(now)
		}
		consider(s.nextFbAt)
	} else {
		s.nextFbAt = time.Time{}
	}

	if s.profile.MaxDuration > 0 && !s.expired {
		consider(s.startedAt.Add(s.profile.MaxDuration))
	}

	if s.opts.KeepaliveEnabled {
		if s.nextKaAt.IsZero() {
			s.scheduleKeepaliveLocked(now)
		}
		consider(s.nextKaAt)
	}
	if best == 0 {
		return 30 * time.Second
	}
	return best
}

func (s *TrafficShaper) shouldEmitVideoFeedback(now time.Time) bool {
	if s.profile.Name != ProfileVideoCall || s.expired || s.profile.LowPowerMode {
		return false
	}
	if s.opts.IsServer {
		// Сервер отправляет обратную связь (RTCP-аналог) только когда клиент активно шлёт данные, а с сервера тишина.
		return !s.lastRealIn.IsZero() && now.Sub(s.lastRealIn) < 3*time.Second
	}
	// Клиент отправляет обратную связь при приёме видео/данных или в коротком окне удержания речи (<15 с).
	if !s.lastRealIn.IsZero() && now.Sub(s.lastRealIn) < 3*time.Second {
		return true
	}
	if !s.lastRealOut.IsZero() && now.Sub(s.lastRealOut) < 15*time.Second {
		return true
	}
	return false
}

func (s *TrafficShaper) scheduleFeedbackLocked(now time.Time) {
	inRecent := !s.lastRealIn.IsZero() && now.Sub(s.lastRealIn) < 3*time.Second
	outRecent := !s.lastRealOut.IsZero() && now.Sub(s.lastRealOut) < 3*time.Second
	minI, maxI := s.profile.FeedbackIdleMin, s.profile.FeedbackIdleMax
	if inRecent || outRecent || s.opts.IsServer {
		minI, maxI = s.profile.FeedbackMinInterval, s.profile.FeedbackMaxInterval
	}
	if minI <= 0 {
		minI = 120 * time.Millisecond
	}
	if maxI < minI {
		maxI = minI
	}
	s.nextFbAt = now.Add(RandomDurationBetween(minI, maxI, s.randUnit))
}

func (s *TrafficShaper) scheduleKeepaliveLocked(now time.Time) {
	minI := s.profile.KeepaliveMinIdle
	maxI := s.profile.KeepaliveMaxIdle
	if s.expired || s.profile.Name != ProfileVideoCall {
		if minI <= 0 || minI < 10*time.Second {
			minI = 16 * time.Second
			maxI = 28 * time.Second
		}
	} else if s.profile.LowPowerMode {
		minI = s.profile.DeepIdleMinKeepalive
		maxI = s.profile.DeepIdleMaxKeepalive
	} else {
		lastAct := s.lastRealOut
		if s.lastRealIn.After(lastAct) {
			lastAct = s.lastRealIn
		}
		if !lastAct.IsZero() && now.Sub(lastAct) >= 45*time.Second && s.profile.DeepIdleMinKeepalive > 0 {
			minI = s.profile.DeepIdleMinKeepalive
			maxI = s.profile.DeepIdleMaxKeepalive
		}
	}
	if minI <= 0 {
		minI = 16 * time.Second
	}
	if maxI < minI {
		maxI = minI
	}
	s.nextKaAt = now.Add(RandomDurationBetween(minI, maxI, s.randUnit))
}

func (s *TrafficShaper) emitItem(item *sendItem, prof SessionProfile) error {
	if item == nil {
		return nil
	}
	ver := s.opts.Version
	if ver == 0 {
		ver = ProtocolVersionV5
	}
	seq := s.opts.NextSeq()
	wire, err := SealPacketWithOptions(item.frameType, s.salt, s.psk, s.key, seq, item.payload, SealOptions{
		Version:       ver,
		Padding:       prof.Padding,
		SamplePad:     prof.SamplePaddingLen,
		MaxOuterBytes: s.maxOuter,
	})
	if err != nil {
		return err
	}
	if err := s.opts.WriteWire(wire); err != nil {
		return err
	}
	switch item.frameType {
	case FrameTypeData:
		s.sentDataFrames.Add(1)
		s.tracker.Touch()
	case FrameTypeBatch:
		s.sentBatchFrames.Add(1)
		s.coalescedPackets.Add(uint64(item.batched))
		s.tracker.Touch()
	case FrameTypeKeepalive:
		if item.isPing {
			s.sentKeepalives.Add(1)
		} else {
			s.sentControlFrames.Add(1)
		}
	}
	return nil
}

func stopAndDrainTimer(t Timer) {
	if t == nil {
		return
	}
	if !t.Stop() {
		select {
		case <-t.C():
		default:
		}
	}
}

func resetClockTimer(t Timer, d time.Duration) {
	if t == nil {
		return
	}
	if d <= 0 {
		d = time.Millisecond
	}
	stopAndDrainTimer(t)
	t.Reset(d)
}

func isFatalQueueError(err error) bool {
	return err != nil && !errors.Is(err, ErrQueueFull)
}
