package tunnel

import (
	"context"
	"crypto/rand"
	"encoding/binary"
	"errors"
	"fmt"
	"io"
	"math"
	"net"
	"strings"
	"sync/atomic"
	"syscall"
	"time"
)

// ErrInvalidTimingConfig сигнализирует о некорректных или небезопасных границах таймеров.
var ErrInvalidTimingConfig = errors.New("некорректная конфигурация таймингов Argon4")

// Timer абстрагирует одноразовый таймер для рабочего цикла и тестов с управляемыми часами.
type Timer interface {
	C() <-chan time.Time
	Stop() bool
	Reset(d time.Duration) bool
}

// Clock предоставляет текущее время и таймеры (по умолчанию RealClock).
type Clock interface {
	Now() time.Time
	NewTimer(d time.Duration) Timer
}

// RealClock использует стандартные системные монотонныe часы Go.
type RealClock struct{}

type realTimer struct {
	t *time.Timer
}

func (RealClock) Now() time.Time { return time.Now() }

func (RealClock) NewTimer(d time.Duration) Timer {
	return &realTimer{t: time.NewTimer(d)}
}

func (rt *realTimer) C() <-chan time.Time        { return rt.t.C }
func (rt *realTimer) Stop() bool                 { return rt.t.Stop() }
func (rt *realTimer) Reset(d time.Duration) bool { return rt.t.Reset(d) }

// TimingConfig задаёт проверяемые границы рандомизированного backoff для handshake и idle keepalive.
// Конфигурация не содержит секретов и исключает фиксированные расписания вроде 3x3s и 30s.
type TimingConfig struct {
	HandshakeMaxAttempts    int
	HandshakeInitialTimeout time.Duration
	HandshakeMaxTimeout     time.Duration
	HandshakeMaxElapsed     time.Duration
	HandshakeBackoffFactor  float64
	HandshakeRetryMinPause  time.Duration
	HandshakeRetryMaxPause  time.Duration
	HandshakeJitterRatio    float64

	KeepaliveMinIdle   time.Duration
	KeepaliveMaxIdle   time.Duration
	SessionIdleTimeout time.Duration
}

// DefaultTimingConfig возвращает безопасные границы по умолчанию:
// keepalive в пределах [16s..28s] (с запасом под NAT 30s+ и серверный SessionIdleTimeout 10m),
// повторы handshake — до 5 попыток с экспоненциальным backoff и ±30% jitter.
func DefaultTimingConfig() TimingConfig {
	return TimingConfig{
		HandshakeMaxAttempts:    5,
		HandshakeInitialTimeout: 1200 * time.Millisecond,
		HandshakeMaxTimeout:     4500 * time.Millisecond,
		HandshakeMaxElapsed:     15 * time.Second,
		HandshakeBackoffFactor:  1.7,
		HandshakeRetryMinPause:  120 * time.Millisecond,
		HandshakeRetryMaxPause:  800 * time.Millisecond,
		HandshakeJitterRatio:    0.30,
		KeepaliveMinIdle:        16 * time.Second,
		KeepaliveMaxIdle:        28 * time.Second,
		SessionIdleTimeout:      SessionIdleTimeout,
	}
}

// NormalizeTimingConfig заполняет нулевые поля значениями по умолчанию и проверяет инварианты границ.
func NormalizeTimingConfig(cfg TimingConfig) (TimingConfig, error) {
	if cfg.HandshakeMaxAttempts < 0 ||
		cfg.HandshakeInitialTimeout < 0 ||
		cfg.HandshakeMaxTimeout < 0 ||
		cfg.HandshakeMaxElapsed < 0 ||
		cfg.HandshakeBackoffFactor < 0 ||
		cfg.HandshakeRetryMinPause < 0 ||
		cfg.HandshakeRetryMaxPause < 0 ||
		cfg.HandshakeJitterRatio < 0 ||
		cfg.KeepaliveMinIdle < 0 ||
		cfg.KeepaliveMaxIdle < 0 ||
		cfg.SessionIdleTimeout < 0 {
		return TimingConfig{}, fmt.Errorf("%w: отрицательные значения недопустимы", ErrInvalidTimingConfig)
	}

	def := DefaultTimingConfig()
	if cfg.HandshakeMaxAttempts == 0 {
		cfg.HandshakeMaxAttempts = def.HandshakeMaxAttempts
	}
	if cfg.HandshakeInitialTimeout == 0 {
		cfg.HandshakeInitialTimeout = def.HandshakeInitialTimeout
	}
	if cfg.HandshakeMaxTimeout == 0 {
		cfg.HandshakeMaxTimeout = def.HandshakeMaxTimeout
	}
	if cfg.HandshakeMaxElapsed == 0 {
		cfg.HandshakeMaxElapsed = def.HandshakeMaxElapsed
	}
	if cfg.HandshakeBackoffFactor == 0 {
		cfg.HandshakeBackoffFactor = def.HandshakeBackoffFactor
	}
	if cfg.HandshakeRetryMinPause == 0 {
		cfg.HandshakeRetryMinPause = def.HandshakeRetryMinPause
	}
	if cfg.HandshakeRetryMaxPause == 0 {
		cfg.HandshakeRetryMaxPause = def.HandshakeRetryMaxPause
	}
	if cfg.HandshakeJitterRatio == 0 {
		cfg.HandshakeJitterRatio = def.HandshakeJitterRatio
	}
	if cfg.KeepaliveMinIdle == 0 {
		cfg.KeepaliveMinIdle = def.KeepaliveMinIdle
	}
	if cfg.KeepaliveMaxIdle == 0 {
		cfg.KeepaliveMaxIdle = def.KeepaliveMaxIdle
	}
	if cfg.SessionIdleTimeout == 0 {
		cfg.SessionIdleTimeout = def.SessionIdleTimeout
	}

	if cfg.HandshakeMaxAttempts < 1 || cfg.HandshakeMaxAttempts > 20 {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeMaxAttempts=%d вне [1..20]", ErrInvalidTimingConfig, cfg.HandshakeMaxAttempts)
	}
	if cfg.HandshakeInitialTimeout < 20*time.Millisecond || cfg.HandshakeInitialTimeout > 30*time.Second {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeInitialTimeout=%s вне [20ms..30s]", ErrInvalidTimingConfig, cfg.HandshakeInitialTimeout)
	}
	if cfg.HandshakeMaxTimeout < cfg.HandshakeInitialTimeout || cfg.HandshakeMaxTimeout > 60*time.Second {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeMaxTimeout=%s должен быть >= InitialTimeout и <= 60s", ErrInvalidTimingConfig, cfg.HandshakeMaxTimeout)
	}
	if cfg.HandshakeMaxElapsed < cfg.HandshakeInitialTimeout || cfg.HandshakeMaxElapsed > 180*time.Second {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeMaxElapsed=%s должен быть >= InitialTimeout и <= 180s", ErrInvalidTimingConfig, cfg.HandshakeMaxElapsed)
	}
	if math.IsNaN(cfg.HandshakeBackoffFactor) || math.IsInf(cfg.HandshakeBackoffFactor, 0) ||
		cfg.HandshakeBackoffFactor < 1.1 || cfg.HandshakeBackoffFactor > 4.0 {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeBackoffFactor=%.2f вне [1.1..4.0]", ErrInvalidTimingConfig, cfg.HandshakeBackoffFactor)
	}
	if cfg.HandshakeRetryMinPause < time.Millisecond || cfg.HandshakeRetryMaxPause < cfg.HandshakeRetryMinPause || cfg.HandshakeRetryMaxPause > 15*time.Second {
		return TimingConfig{}, fmt.Errorf("%w: некорректный диапазон HandshakeRetryPause [%s..%s]", ErrInvalidTimingConfig, cfg.HandshakeRetryMinPause, cfg.HandshakeRetryMaxPause)
	}
	if math.IsNaN(cfg.HandshakeJitterRatio) || math.IsInf(cfg.HandshakeJitterRatio, 0) ||
		cfg.HandshakeJitterRatio < 0.05 || cfg.HandshakeJitterRatio > 0.75 {
		return TimingConfig{}, fmt.Errorf("%w: HandshakeJitterRatio=%.2f вне [0.05..0.75]", ErrInvalidTimingConfig, cfg.HandshakeJitterRatio)
	}
	if cfg.KeepaliveMinIdle < 20*time.Millisecond {
		return TimingConfig{}, fmt.Errorf("%w: KeepaliveMinIdle=%s слишком мал", ErrInvalidTimingConfig, cfg.KeepaliveMinIdle)
	}
	if cfg.KeepaliveMaxIdle <= cfg.KeepaliveMinIdle {
		return TimingConfig{}, fmt.Errorf("%w: KeepaliveMaxIdle (%s) должен быть строго больше KeepaliveMinIdle (%s) во избежание фиксированного цикла",
			ErrInvalidTimingConfig, cfg.KeepaliveMaxIdle, cfg.KeepaliveMinIdle)
	}
	if cfg.SessionIdleTimeout < 100*time.Millisecond {
		return TimingConfig{}, fmt.Errorf("%w: SessionIdleTimeout=%s слишком мал", ErrInvalidTimingConfig, cfg.SessionIdleTimeout)
	}
	if cfg.KeepaliveMaxIdle*2 > cfg.SessionIdleTimeout {
		return TimingConfig{}, fmt.Errorf("%w: KeepaliveMaxIdle (%s) не должен превышать половину SessionIdleTimeout (%s)",
			ErrInvalidTimingConfig, cfg.KeepaliveMaxIdle, cfg.SessionIdleTimeout)
	}
	return cfg, nil
}

// Summary возвращает компактное описание границ таймингов без секретных данных.
func (c TimingConfig) Summary() string {
	return fmt.Sprintf(
		"handshake=[%s..%s cap=%s attempts=%d jitter=%.0f%%] keepalive=[%s..%s session_idle=%s]",
		c.HandshakeInitialTimeout, c.HandshakeMaxTimeout, c.HandshakeMaxElapsed,
		c.HandshakeMaxAttempts, c.HandshakeJitterRatio*100,
		c.KeepaliveMinIdle, c.KeepaliveMaxIdle, c.SessionIdleTimeout,
	)
}

func cryptoRandUnit() float64 {
	var raw [8]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return 0.5
	}
	// 53 бита равномерной мантиссы в [0, 1).
	v := binary.BigEndian.Uint64(raw[:]) >> 11
	return float64(v) / float64(uint64(1)<<53)
}

func normalizeRandUnit(randUnit func() float64) float64 {
	if randUnit == nil {
		return cryptoRandUnit()
	}
	u := randUnit()
	if math.IsNaN(u) || u < 0 {
		return 0
	}
	if u >= 1 {
		return math.Nextafter(1.0, 0)
	}
	return u
}

// JitteredDuration применяет симметричный разброс ±jitterRatio вокруг base.
func JitteredDuration(base time.Duration, jitterRatio float64, randUnit func() float64) time.Duration {
	if base <= 0 {
		return 0
	}
	u := normalizeRandUnit(randUnit)
	scale := 1.0 + (2.0*u-1.0)*jitterRatio
	if scale < 0.1 {
		scale = 0.1
	}
	d := time.Duration(float64(base) * scale)
	if d < time.Millisecond {
		return time.Millisecond
	}
	return d
}

// RandomDurationBetween выбирает случайную длительность в [minD, maxD].
func RandomDurationBetween(minD, maxD time.Duration, randUnit func() float64) time.Duration {
	if maxD <= minD {
		return minD
	}
	u := normalizeRandUnit(randUnit)
	span := float64(maxD - minD)
	d := minD + time.Duration(u*span)
	if d < minD {
		return minD
	}
	if d > maxD {
		return maxD
	}
	return d
}

// ActivityTracker потокобезопасно фиксирует время последней реальной исходящей активности канала.
// На горячем пути данных не выделяет память и не создаёт системных таймеров.
type ActivityTracker struct {
	origin time.Time
	clock  Clock
	lastNs atomic.Int64
}

// NewActivityTracker создаёт трекер активности для заданных часов (nil -> RealClock).
func NewActivityTracker(clock Clock) *ActivityTracker {
	if clock == nil {
		clock = RealClock{}
	}
	return &ActivityTracker{
		origin: clock.Now(),
		clock:  clock,
	}
}

// Touch обновляет отметку последней исходящей активности. Безопасен для nil-указателя.
func (t *ActivityTracker) Touch() {
	if t == nil {
		return
	}
	offset := t.clock.Now().Sub(t.origin).Nanoseconds()
	if offset < 0 {
		offset = 0
	}
	t.lastNs.Store(offset)
}

// LastOffset возвращает смещение последней активности от origin.
func (t *ActivityTracker) LastOffset() time.Duration {
	if t == nil {
		return 0
	}
	return time.Duration(t.lastNs.Load())
}

// Origin возвращает базовую отметку времени трекера.
func (t *ActivityTracker) Origin() time.Time {
	if t == nil {
		return time.Time{}
	}
	return t.origin
}

// KeepaliveOptions настраивает цикл idle keepalive со сбросом при передаче реальных данных.
type KeepaliveOptions struct {
	Clock        Clock
	RandUnit     func() float64
	Timing       TimingConfig
	Tracker      *ActivityTracker
	Send         func() error
	IsFatalError func(error) bool
	OnError      func(error)
}

// IsFatalKeepaliveError определяет постоянные ошибки сокета или закрытия канала,
// при которых цикл keepalive обязан немедленно остановиться.
func IsFatalKeepaliveError(err error) bool {
	if err == nil {
		return false
	}
	if errors.Is(err, context.Canceled) ||
		errors.Is(err, context.DeadlineExceeded) ||
		errors.Is(err, net.ErrClosed) ||
		errors.Is(err, io.EOF) ||
		errors.Is(err, io.ErrClosedPipe) {
		return true
	}
	if errors.Is(err, syscall.EBADF) ||
		errors.Is(err, syscall.EPIPE) ||
		errors.Is(err, syscall.ENOTCONN) ||
		errors.Is(err, syscall.ENETUNREACH) ||
		errors.Is(err, syscall.ENETDOWN) ||
		errors.Is(err, syscall.EHOSTDOWN) ||
		errors.Is(err, syscall.EHOSTUNREACH) ||
		errors.Is(err, syscall.EINVAL) ||
		errors.Is(err, syscall.ECONNRESET) ||
		errors.Is(err, syscall.ECONNREFUSED) {
		return true
	}
	if errors.Is(err, syscall.EAGAIN) ||
		errors.Is(err, syscall.EWOULDBLOCK) ||
		errors.Is(err, syscall.ENOBUFS) {
		return false
	}
	var ne net.Error
	if errors.As(err, &ne) && ne.Timeout() {
		return false
	}
	msg := strings.ToLower(err.Error())
	if strings.Contains(msg, "use of closed network connection") ||
		strings.Contains(msg, "socket") ||
		strings.Contains(msg, "network is down") ||
		strings.Contains(msg, "network is unreachable") {
		return true
	}
	return true
}

// RunIdleKeepalive запускает цикл отправки keepalive только после фактического простоя исходящего канала.
// Любая реальная передача через Tracker.Touch() сдвигает дедлайн на новый полный интервал с jitter.
// Цикл корректно освобождает таймер и горутину при отмене ctx или ошибке сокета.
func RunIdleKeepalive(ctx context.Context, opts KeepaliveOptions) {
	if opts.Send == nil {
		return
	}
	cfg, err := NormalizeTimingConfig(opts.Timing)
	if err != nil {
		cfg = DefaultTimingConfig()
	}
	clock := opts.Clock
	if clock == nil {
		clock = RealClock{}
	}
	tracker := opts.Tracker
	if tracker == nil {
		tracker = NewActivityTracker(clock)
	}

	targetIdle := RandomDurationBetween(cfg.KeepaliveMinIdle, cfg.KeepaliveMaxIdle, opts.RandUnit)
	lastKeepaliveOffset := time.Duration(0)
	timer := clock.NewTimer(targetIdle)
	defer timer.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-timer.C():
		}
		if ctx.Err() != nil {
			return
		}

		nowOffset := clock.Now().Sub(tracker.Origin())
		anchorOffset := tracker.LastOffset()
		if lastKeepaliveOffset > anchorOffset {
			anchorOffset = lastKeepaliveOffset
		}
		idleFor := nowOffset - anchorOffset
		if idleFor < 0 {
			idleFor = 0
		}

		if idleFor < targetIdle {
			remaining := targetIdle - idleFor
			if remaining < time.Millisecond {
				remaining = time.Millisecond
			}
			timer.Reset(remaining)
			continue
		}

		if err := opts.Send(); err != nil {
			if opts.OnError != nil && ctx.Err() == nil {
				opts.OnError(err)
			}
			fatal := false
			if opts.IsFatalError != nil {
				fatal = opts.IsFatalError(err)
			} else {
				fatal = IsFatalKeepaliveError(err)
			}
			if ctx.Err() != nil || fatal {
				return
			}
		}

		lastKeepaliveOffset = clock.Now().Sub(tracker.Origin())
		targetIdle = RandomDurationBetween(cfg.KeepaliveMinIdle, cfg.KeepaliveMaxIdle, opts.RandUnit)
		timer.Reset(targetIdle)
	}
}

// HandshakeTransport абстрагирует сокет для цикла рукопожатия с поддержкой детерминированных тестов.
type HandshakeTransport interface {
	Write(p []byte) (int, error)
	Read(p []byte) (int, error)
	SetReadDeadline(t time.Time) error
}

type nonRetryableHandshakeError struct {
	err error
}

func (e nonRetryableHandshakeError) Error() string { return e.err.Error() }
func (e nonRetryableHandshakeError) Unwrap() error { return e.err }

// WrapNonRetryableHandshake помечает ошибку ответа handshake как окончательную без повторных попыток.
func WrapNonRetryableHandshake(err error) error {
	if err == nil {
		return nil
	}
	return nonRetryableHandshakeError{err: err}
}

// IsNonRetryableHandshakeError определяет фатальные ошибки безопасности/закрытия при handshake.
func IsNonRetryableHandshakeError(err error) bool {
	if err == nil {
		return false
	}
	var nonRetry nonRetryableHandshakeError
	if errors.As(err, &nonRetry) {
		return true
	}
	return errors.Is(err, ErrDowngradeRejected) ||
		errors.Is(err, ErrBadVersion) ||
		errors.Is(err, ErrInvalidPadding) ||
		errors.Is(err, ErrTrailingData) ||
		errors.Is(err, ErrInvalidEnvelope) ||
		errors.Is(err, context.Canceled) ||
		errors.Is(err, net.ErrClosed) ||
		errors.Is(err, io.ErrClosedPipe)
}

func sleepWithClock(ctx context.Context, clock Clock, d time.Duration) error {
	if d <= 0 {
		return ctx.Err()
	}
	if ctx.Err() != nil {
		return ctx.Err()
	}
	timer := clock.NewTimer(d)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C():
		return nil
	}
}

func isTimeoutNetError(err error) bool {
	var ne net.Error
	return errors.As(err, &ne) && ne.Timeout()
}

// RunHandshakeLoop выполняет ограниченную по попыткам и общему времени серию отправок
// байт-идентичного кадра wireRequest с экспоненциальным backoff и jitter.
func RunHandshakeLoop(
	ctx context.Context,
	cfg TimingConfig,
	clock Clock,
	randUnit func() float64,
	conn HandshakeTransport,
	wireRequest []byte,
	handlePacket func(packet []byte) (done bool, err error),
) error {
	norm, err := NormalizeTimingConfig(cfg)
	if err != nil {
		return err
	}
	if clock == nil {
		clock = RealClock{}
	}

	start := clock.Now()
	hardDeadline := start.Add(norm.HandshakeMaxElapsed)
	if ctxDeadline, ok := ctx.Deadline(); ok && ctxDeadline.Before(hardDeadline) {
		hardDeadline = ctxDeadline
	}

	baseWait := norm.HandshakeInitialTimeout
	pauseBase := norm.HandshakeRetryMinPause
	var lastErr error
	buffer := make([]byte, 65535)
	attemptsMade := 0

	for attempt := 0; attempt < norm.HandshakeMaxAttempts; attempt++ {
		if ctx.Err() != nil {
			return ctx.Err()
		}
		now := clock.Now()
		remaining := hardDeadline.Sub(now)
		if remaining <= 0 {
			break
		}
		attemptsMade++

		attemptWait := JitteredDuration(baseWait, norm.HandshakeJitterRatio, randUnit)
		if attemptWait > norm.HandshakeMaxTimeout {
			attemptWait = norm.HandshakeMaxTimeout
		}
		if attemptWait > remaining {
			attemptWait = remaining
		}

		if _, err := conn.Write(wireRequest); err != nil {
			lastErr = err
			if IsNonRetryableHandshakeError(err) || ctx.Err() != nil {
				return err
			}
		} else {
			attemptDeadline := clock.Now().Add(attemptWait)
			if attemptDeadline.After(hardDeadline) {
				attemptDeadline = hardDeadline
			}
			_ = conn.SetReadDeadline(attemptDeadline)

			for {
				if ctx.Err() != nil {
					_ = conn.SetReadDeadline(time.Time{})
					return ctx.Err()
				}
				n, rerr := conn.Read(buffer)
				if rerr != nil {
					if isTimeoutNetError(rerr) {
						lastErr = rerr
						break
					}
					lastErr = rerr
					if IsNonRetryableHandshakeError(rerr) || ctx.Err() != nil {
						_ = conn.SetReadDeadline(time.Time{})
						return rerr
					}
					break
				}
				done, perr := handlePacket(buffer[:n])
				if perr != nil {
					lastErr = perr
					if IsNonRetryableHandshakeError(perr) {
						_ = conn.SetReadDeadline(time.Time{})
						return perr
					}
					continue
				}
				if done {
					_ = conn.SetReadDeadline(time.Time{})
					return nil
				}
			}
		}

		_ = conn.SetReadDeadline(time.Time{})
		if attempt == norm.HandshakeMaxAttempts-1 {
			break
		}

		remainingAfter := hardDeadline.Sub(clock.Now())
		if remainingAfter <= 0 {
			break
		}
		pause := JitteredDuration(pauseBase, norm.HandshakeJitterRatio, randUnit)
		if pause < norm.HandshakeRetryMinPause {
			pause = norm.HandshakeRetryMinPause
		}
		if pause > norm.HandshakeRetryMaxPause {
			pause = norm.HandshakeRetryMaxPause
		}
		if pause > remainingAfter {
			pause = remainingAfter
		}
		if err := sleepWithClock(ctx, clock, pause); err != nil {
			return err
		}

		nextWait := time.Duration(float64(baseWait) * norm.HandshakeBackoffFactor)
		if nextWait > norm.HandshakeMaxTimeout {
			nextWait = norm.HandshakeMaxTimeout
		}
		baseWait = nextWait

		nextPause := time.Duration(float64(pauseBase) * norm.HandshakeBackoffFactor)
		if nextPause > norm.HandshakeRetryMaxPause {
			nextPause = norm.HandshakeRetryMaxPause
		}
		pauseBase = nextPause
	}

	if ctx.Err() != nil {
		return ctx.Err()
	}
	if lastErr == nil {
		lastErr = context.DeadlineExceeded
	}
	return fmt.Errorf("Argon4: сервер не ответил на handshake после %d попыток: %w", attemptsMade, lastErr)
}
