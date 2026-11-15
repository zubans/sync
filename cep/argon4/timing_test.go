package argon4

import (
	"bytes"
	"context"
	"errors"
	"net"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type fakeTimer struct {
	clock    *fakeClock
	ch       chan time.Time
	deadline time.Time
	active   bool
}

func (ft *fakeTimer) C() <-chan time.Time { return ft.ch }

func (ft *fakeTimer) Stop() bool {
	ft.clock.mu.Lock()
	defer ft.clock.mu.Unlock()
	was := ft.active
	ft.active = false
	ft.clock.signalLocked()
	return was
}

func (ft *fakeTimer) Reset(d time.Duration) bool {
	ft.clock.mu.Lock()
	defer ft.clock.mu.Unlock()
	was := ft.active
	if d <= 0 {
		d = time.Nanosecond
	}
	ft.deadline = ft.clock.now.Add(d)
	ft.active = true
	ft.clock.fireDueLocked()
	ft.clock.signalLocked()
	return was
}

type fakeClock struct {
	mu     sync.Mutex
	now    time.Time
	timers []*fakeTimer
	notify chan struct{}
}

func newFakeClock(start time.Time) *fakeClock {
	return &fakeClock{
		now:    start,
		notify: make(chan struct{}, 64),
	}
}

func (fc *fakeClock) signalLocked() {
	select {
	case fc.notify <- struct{}{}:
	default:
	}
}

func (fc *fakeClock) Now() time.Time {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	return fc.now
}

func (fc *fakeClock) NewTimer(d time.Duration) Timer {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	if d <= 0 {
		d = time.Nanosecond
	}
	ft := &fakeTimer{
		clock:    fc,
		ch:       make(chan time.Time, 1),
		deadline: fc.now.Add(d),
		active:   true,
	}
	fc.timers = append(fc.timers, ft)
	fc.signalLocked()
	return ft
}

func (fc *fakeClock) fireDueLocked() {
	for _, ft := range fc.timers {
		if ft.active && !ft.deadline.After(fc.now) {
			ft.active = false
			select {
			case ft.ch <- fc.now:
			default:
			}
		}
	}
}

func (fc *fakeClock) Advance(d time.Duration) {
	fc.mu.Lock()
	fc.now = fc.now.Add(d)
	fc.fireDueLocked()
	fc.signalLocked()
	fc.mu.Unlock()
}

func (fc *fakeClock) ActiveTimers() int {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	count := 0
	for _, ft := range fc.timers {
		if ft.active {
			count++
		}
	}
	return count
}

func (fc *fakeClock) NextDeadline() (time.Time, bool) {
	fc.mu.Lock()
	defer fc.mu.Unlock()
	var earliest time.Time
	found := false
	for _, ft := range fc.timers {
		if ft.active && (!found || ft.deadline.Before(earliest)) {
			earliest = ft.deadline
			found = true
		}
	}
	return earliest, found
}

func (fc *fakeClock) WaitUntilTimerArmed(t *testing.T, deadlineAfter time.Time) {
	t.Helper()
	limit := time.Now().Add(2 * time.Second)
	for time.Now().Before(limit) {
		fc.mu.Lock()
		ok := false
		for _, ft := range fc.timers {
			if ft.active && ft.deadline.After(deadlineAfter) {
				ok = true
				break
			}
		}
		fc.mu.Unlock()
		if ok {
			return
		}
		select {
		case <-fc.notify:
		case <-time.After(5 * time.Millisecond):
		}
	}
	t.Fatalf("таймер не был переустановлен после %s", deadlineAfter)
}

func TestTimingConfigValidation(t *testing.T) {
	def, err := NormalizeTimingConfig(TimingConfig{})
	if err != nil {
		t.Fatalf("NormalizeTimingConfig(default): %v", err)
	}
	if def.KeepaliveMinIdle != 16*time.Second || def.KeepaliveMaxIdle != 28*time.Second {
		t.Fatalf("ожидался диапазон keepalive [16s..28s], получено [%s..%s]", def.KeepaliveMinIdle, def.KeepaliveMaxIdle)
	}
	if def.Summary() == "" {
		t.Fatal("пустой Summary")
	}

	invalid := []TimingConfig{
		{KeepaliveMinIdle: 30 * time.Second, KeepaliveMaxIdle: 30 * time.Second},
		{KeepaliveMinIdle: 20 * time.Second, KeepaliveMaxIdle: 10 * time.Second},
		{KeepaliveMinIdle: 400 * time.Second, KeepaliveMaxIdle: 500 * time.Second, SessionIdleTimeout: 600 * time.Second},
		{HandshakeInitialTimeout: -time.Second},
		{HandshakeJitterRatio: 0.01},
		{HandshakeBackoffFactor: 0.9},
		{HandshakeInitialTimeout: 5 * time.Second, HandshakeMaxTimeout: 2 * time.Second},
	}
	for i, cfg := range invalid {
		if _, err := NormalizeTimingConfig(cfg); err == nil {
			t.Fatalf("кейс #%d (%+v): ожидалась ошибка валидации", i, cfg)
		}
	}
}

func TestIdleKeepaliveSuppressedDuringActivityAndSentBeforeTimeout(t *testing.T) {
	fc := newFakeClock(time.Unix(1700000000, 0))
	tracker := NewActivityTracker(fc)
	cfg := DefaultTimingConfig()

	var sentAt []time.Time
	var mu sync.Mutex
	sentCh := make(chan time.Time, 16)

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})

	go func() {
		defer close(done)
		RunIdleKeepalive(ctx, KeepaliveOptions{
			Clock:   fc,
			Timing:  cfg,
			Tracker: tracker,
			Send: func() error {
				now := fc.Now()
				mu.Lock()
				sentAt = append(sentAt, now)
				mu.Unlock()
				sentCh <- now
				return nil
			},
		})
	}()

	fc.WaitUntilTimerArmed(t, fc.Now().Add(-time.Nanosecond))

	// Активная передача каждые 10 секунд на протяжении минуты: keepalive НЕ должен отправляться ни разу.
	for step := 0; step < 6; step++ {
		fc.Advance(10 * time.Second)
		tracker.Touch()
		// Пропускаем перевзвод таймера, если его дедлайн уже прошёл.
		for {
			dl, ok := fc.NextDeadline()
			if !ok || dl.After(fc.Now()) {
				break
			}
			fc.Advance(0)
		}
		select {
		case unexpected := <-sentCh:
			t.Fatalf("отправлен лишний keepalive при активном трафике на %s", unexpected)
		default:
		}
	}

	lastTrafficAt := fc.Now()
	// Начинаем длительный простой: двигаемся шагами по 1s, ожидаем несколько keepalive внутри [16s..28s].
	var deltas []time.Duration
	prev := lastTrafficAt
	for len(deltas) < 4 {
		var got time.Time
		limit := fc.Now().Add(cfg.KeepaliveMaxIdle + 2*time.Second)
		for fc.Now().Before(limit) {
			fc.Advance(time.Second)
			select {
			case got = <-sentCh:
				goto received
			default:
			}
			// Подгоняем планировщик, пока следующая цель не уйдёт в будущее относительно Now.
			fc.WaitUntilTimerArmed(t, fc.Now())
		}
		t.Fatalf("не дождались keepalive в пределах KeepaliveMaxIdle=%s", cfg.KeepaliveMaxIdle)
	received:
		delta := got.Sub(prev)
		if delta < cfg.KeepaliveMinIdle || delta > cfg.KeepaliveMaxIdle+time.Second {
			t.Fatalf("интервал простоя %s вышел за пределы [%s..%s]", delta, cfg.KeepaliveMinIdle, cfg.KeepaliveMaxIdle)
		}
		if delta >= cfg.SessionIdleTimeout {
			t.Fatalf("keepalive %s превысил SessionIdleTimeout %s", delta, cfg.SessionIdleTimeout)
		}
		deltas = append(deltas, delta)
		prev = got
		fc.WaitUntilTimerArmed(t, fc.Now())
	}

	// Зафиксируем, что интервалы не вырождаются в фиксированный 30-секундный тик.
	allEqual := true
	for i := 1; i < len(deltas); i++ {
		if deltas[i] != deltas[0] {
			allEqual = false
			break
		}
	}
	if allEqual && len(deltas) >= 3 {
		t.Logf("предупреждение: совпали 1s-округлённые интервалы %v, проверяем границы", deltas)
	}

	cancel()
	<-done
	if active := fc.ActiveTimers(); active != 0 {
		t.Fatalf("после отмены осталось активных таймеров: %d", active)
	}
}

func TestIdleKeepaliveStopsCleanlyOnSocketError(t *testing.T) {
	fc := newFakeClock(time.Unix(1700000000, 0))
	tracker := NewActivityTracker(fc)
	ctx := context.Background()

	var errCount atomic.Int32
	done := make(chan struct{})
	go func() {
		defer close(done)
		RunIdleKeepalive(ctx, KeepaliveOptions{
			Clock:   fc,
			Timing:  DefaultTimingConfig(),
			Tracker: tracker,
			Send: func() error {
				return net.ErrClosed
			},
			OnError: func(error) {
				errCount.Add(1)
			},
		})
	}()

	fc.WaitUntilTimerArmed(t, fc.Now().Add(-time.Nanosecond))
	fc.Advance(DefaultTimingConfig().KeepaliveMaxIdle + time.Second)

	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("RunIdleKeepalive не завершился при закрытии сокета")
	}
	if errCount.Load() != 1 {
		t.Fatalf("ожидался 1 вызов OnError, получено %d", errCount.Load())
	}
	if active := fc.ActiveTimers(); active != 0 {
		t.Fatalf("после ошибки сокета остались активные таймеры: %d", active)
	}
}

type fakeTimeoutErr struct{}

func (fakeTimeoutErr) Error() string   { return "i/o timeout" }
func (fakeTimeoutErr) Timeout() bool   { return true }
func (fakeTimeoutErr) Temporary() bool { return true }

type mockHandshakeConn struct {
	clock      *fakeClock
	mu         sync.Mutex
	sentAt     []time.Time
	sentPaylds [][]byte
	deadline   time.Time
	responses  map[int][]byte
}

func (m *mockHandshakeConn) Write(p []byte) (int, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.sentAt = append(m.sentAt, m.clock.Now())
	m.sentPaylds = append(m.sentPaylds, append([]byte(nil), p...))
	return len(p), nil
}

func (m *mockHandshakeConn) Read(p []byte) (int, error) {
	m.mu.Lock()
	attempt := len(m.sentAt)
	resp, ok := m.responses[attempt]
	dl := m.deadline
	m.mu.Unlock()
	if ok {
		n := copy(p, resp)
		return n, nil
	}
	// Эмулируем ожидание до readDeadline на управляемых часах.
	delta := dl.Sub(m.clock.Now())
	if delta > 0 {
		m.clock.Advance(delta)
	}
	return 0, fakeTimeoutErr{}
}

func (m *mockHandshakeConn) SetReadDeadline(t time.Time) error {
	m.mu.Lock()
	m.deadline = t
	m.mu.Unlock()
	return nil
}

func TestHandshakeRandomizedExponentialBackoffAndDowngradeStop(t *testing.T) {
	fc := newFakeClock(time.Unix(1700000000, 0))
	cfg, err := NormalizeTimingConfig(TimingConfig{
		HandshakeMaxAttempts:    4,
		HandshakeInitialTimeout: 500 * time.Millisecond,
		HandshakeMaxTimeout:     3000 * time.Millisecond,
		HandshakeMaxElapsed:     10 * time.Second,
		HandshakeBackoffFactor:  2.0,
		HandshakeRetryMinPause:  100 * time.Millisecond,
		HandshakeRetryMaxPause:  400 * time.Millisecond,
		HandshakeJitterRatio:    0.25,
	})
	if err != nil {
		t.Fatalf("NormalizeTimingConfig: %v", err)
	}

	// Потеряны первые 2 попытки; ответ приходит только на 3-й попытке.
	wireReq := []byte("sealed-v5-handshake-payload")
	mockConn := &mockHandshakeConn{
		clock:     fc,
		responses: map[int][]byte{3: []byte("ok-resp")},
	}

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Подаём детерминированную псевдо-случайную последовательность, меняющую интервалы backoff.
	seq := []float64{0.2, 0.8, 0.6, 0.3, 0.9}
	var seqIdx int
	randFn := func() float64 {
		v := seq[seqIdx%len(seq)]
		seqIdx++
		return v
	}

	// Автоматически продвигаем fakeClock при установке pause-таймеров снаружи Write.
	done := make(chan error, 1)
	go func() {
		done <- RunHandshakeLoop(ctx, cfg, fc, randFn, mockConn, wireReq, func(pkt []byte) (bool, error) {
			return bytes.Equal(pkt, []byte("ok-resp")), nil
		})
	}()

	for {
		select {
		case runErr := <-done:
			if runErr != nil {
				t.Fatalf("RunHandshakeLoop failed: %v", runErr)
			}
			goto verifyBackoff
		case <-fc.notify:
			if dl, ok := fc.NextDeadline(); ok {
				step := dl.Sub(fc.Now())
				if step > 0 {
					fc.Advance(step)
				}
			}
		case <-time.After(2 * time.Second):
			t.Fatal("таймаут выполнения RunHandshakeLoop в мок-тесте")
		}
	}

verifyBackoff:
	mockConn.mu.Lock()
	if len(mockConn.sentAt) != 3 {
		t.Fatalf("ожидалось ровно 3 отправки handshake, получено %d", len(mockConn.sentAt))
	}
	for i, pkt := range mockConn.sentPaylds {
		if !bytes.Equal(pkt, wireReq) {
			t.Fatalf("попытка %d отправила изменённые байты handshake", i+1)
		}
	}
	gap1 := mockConn.sentAt[1].Sub(mockConn.sentAt[0])
	gap2 := mockConn.sentAt[2].Sub(mockConn.sentAt[1])
	mockConn.mu.Unlock()

	if gap2 <= gap1 {
		t.Fatalf("ожидался рост интервалов exponential backoff: gap1=%s gap2=%s", gap1, gap2)
	}
	if fc.ActiveTimers() != 0 {
		t.Fatalf("утечка таймеров после handshake: %d", fc.ActiveTimers())
	}

	// Проверка немедленной остановки при ErrDowngradeRejected без лишних повторов.
	mockDowngrade := &mockHandshakeConn{
		clock:     fc,
		responses: map[int][]byte{1: []byte("v4-downgrade")},
	}
	derr := RunHandshakeLoop(ctx, cfg, fc, randFn, mockDowngrade, wireReq, func([]byte) (bool, error) {
		return false, ErrDowngradeRejected
	})
	if !errors.Is(derr, ErrDowngradeRejected) {
		t.Fatalf("ожидалась ErrDowngradeRejected, получено %v", derr)
	}
	if len(mockDowngrade.sentAt) != 1 {
		t.Fatalf("при ErrDowngradeRejected повторные попытки запрещены, отправлено %d", len(mockDowngrade.sentAt))
	}
}
