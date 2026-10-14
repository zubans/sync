<?php

namespace App\Service;

/**
 * Технические индикаторы по дневным свечам — то, что модель получает вместе с новостями, а планшет
 * показывает на экране инструмента. Считаются по закрытиям торговых дней (бары, а не календарные дни):
 * неделя — 5 баров, месяц — 21, квартал — 63, полгода — 126, год — 252.
 *
 * Данных мало (бумага недавно на бирже) — индикатор, которому их не хватило, равен null.
 */
final class MarketIndicators
{
    private const PERIODS = ['week' => 5, 'month' => 21, 'quarter' => 63, 'halfYear' => 126, 'year' => 252];

    /**
     * @param list<array{date: string, open: float, high: float, low: float, close: float, value: float}> $candles по порядку дат
     *
     * @return array<string, mixed>
     */
    public static function compute(array $candles): array
    {
        $closes = array_column($candles, 'close');
        $n = \count($closes);
        if ($n === 0) {
            return [];
        }
        $last = $closes[$n - 1];
        $year = \array_slice($candles, -self::PERIODS['year']);

        $returns = [];
        foreach (self::PERIODS as $name => $bars) {
            $returns[$name] = $n > $bars ? self::percent($closes[$n - 1 - $bars], $last) : null;
        }

        $sma20 = self::sma($closes, 20);
        $sma50 = self::sma($closes, 50);
        $sma200 = self::sma($closes, 200);
        $macd = self::macd($closes);
        $bollinger = self::bollinger($closes, 20, 2.0);
        $high52 = max(array_column($year, 'high'));
        $low52 = min(array_column($year, 'low'));
        $recent = \array_slice($candles, -20);

        return [
            'date' => $candles[$n - 1]['date'],
            'close' => $last,
            'bars' => $n,
            'returns' => $returns,
            'sma20' => $sma20,
            'sma50' => $sma50,
            'sma200' => $sma200,
            // Насколько цена выше (+) или ниже (−) средней, %.
            'vsSma20' => $sma20 !== null ? self::percent($sma20, $last) : null,
            'vsSma50' => $sma50 !== null ? self::percent($sma50, $last) : null,
            'vsSma200' => $sma200 !== null ? self::percent($sma200, $last) : null,
            'trend' => self::trend($last, $sma50, $sma200),
            'smaCross' => self::smaCross($closes),
            'rsi14' => self::rsi($closes, 14),
            'macd' => $macd,
            'bollinger' => $bollinger,
            // Годовая волатильность по дневным изменениям за месяц, %.
            'volatility' => self::volatility(\array_slice($closes, -21)),
            'high52' => $high52,
            'low52' => $low52,
            'fromHigh52' => self::percent($high52, $last),
            'fromLow52' => self::percent($low52, $last),
            'maxDrawdownYear' => self::maxDrawdown(array_column($year, 'close')),
            // Ближайшие уровни: минимум и максимум последних 20 торговых дней.
            'support' => min(array_column($recent, 'low')),
            'resistance' => max(array_column($recent, 'high')),
            // Оборот последней недели к среднему за 50 дней: больше 1 — к бумаге повышенный интерес.
            'volumeRatio' => self::volumeRatio(array_column($candles, 'value')),
        ];
    }

    /** @param list<float> $values */
    public static function sma(array $values, int $period): ?float
    {
        if (\count($values) < $period) {
            return null;
        }

        return array_sum(\array_slice($values, -$period)) / $period;
    }

    /**
     * Экспоненциальная средняя по всему ряду: первое значение — простая средняя первых $period значений.
     *
     * @param list<float> $values
     *
     * @return list<float> по одному значению на каждый элемент начиная с ($period − 1)-го
     */
    public static function emaSeries(array $values, int $period): array
    {
        if (\count($values) < $period) {
            return [];
        }
        $k = 2 / ($period + 1);
        $ema = array_sum(\array_slice($values, 0, $period)) / $period;
        $series = [$ema];
        foreach (\array_slice($values, $period) as $value) {
            $ema = $value * $k + $ema * (1 - $k);
            $series[] = $ema;
        }

        return $series;
    }

    /**
     * RSI по Уайлдеру: выше 70 — перекупленность, ниже 30 — перепроданность.
     *
     * @param list<float> $closes
     */
    public static function rsi(array $closes, int $period): ?float
    {
        if (\count($closes) <= $period) {
            return null;
        }
        [$gain, $loss] = [0.0, 0.0];
        for ($i = 1; $i <= $period; ++$i) {
            $d = $closes[$i] - $closes[$i - 1];
            $gain += max($d, 0);
            $loss += max(-$d, 0);
        }
        [$gain, $loss] = [$gain / $period, $loss / $period];
        for ($i = $period + 1, $n = \count($closes); $i < $n; ++$i) {
            $d = $closes[$i] - $closes[$i - 1];
            $gain = ($gain * ($period - 1) + max($d, 0)) / $period;
            $loss = ($loss * ($period - 1) + max(-$d, 0)) / $period;
        }
        if ($loss == 0.0) {
            return $gain == 0.0 ? 50.0 : 100.0;
        }

        return round(100 - 100 / (1 + $gain / $loss), 1);
    }

    /**
     * MACD (12, 26, 9): линия, сигнальная и гистограмма; cross — пересечение линии и сигнальной
     * за последние 5 дней: bullish — вверх, bearish — вниз, null — не было.
     *
     * @param list<float> $closes
     *
     * @return array{line: float, signal: float, histogram: float, cross: ?string}|null
     */
    public static function macd(array $closes): ?array
    {
        $fast = self::emaSeries($closes, 12);
        $slow = self::emaSeries($closes, 26);
        if ($slow === []) {
            return null;
        }
        // Быстрая начинается на 14 значений раньше медленной — выравниваем по концу.
        $fast = \array_slice($fast, \count($fast) - \count($slow));
        $line = array_map(static fn (float $f, float $s) => $f - $s, $fast, $slow);
        $signal = self::emaSeries($line, 9);
        if ($signal === []) {
            return null;
        }
        $line = \array_slice($line, \count($line) - \count($signal));
        $hist = array_map(static fn (float $l, float $s) => $l - $s, $line, $signal);
        $cross = null;
        for ($i = max(1, \count($hist) - 5), $n = \count($hist); $i < $n; ++$i) {
            if ($hist[$i - 1] <= 0 && $hist[$i] > 0) {
                $cross = 'bullish';
            } elseif ($hist[$i - 1] >= 0 && $hist[$i] < 0) {
                $cross = 'bearish';
            }
        }

        return ['line' => end($line), 'signal' => end($signal), 'histogram' => end($hist), 'cross' => $cross];
    }

    /**
     * Полосы Боллинджера; percentB — где цена внутри полос: 0 — на нижней, 1 — на верхней.
     *
     * @param list<float> $closes
     *
     * @return array{upper: float, middle: float, lower: float, percentB: ?float}|null
     */
    public static function bollinger(array $closes, int $period, float $width): ?array
    {
        if (\count($closes) < $period) {
            return null;
        }
        $window = \array_slice($closes, -$period);
        $mean = array_sum($window) / $period;
        $sd = sqrt(array_sum(array_map(static fn (float $v) => ($v - $mean) ** 2, $window)) / $period);
        $upper = $mean + $width * $sd;
        $lower = $mean - $width * $sd;

        return [
            'upper' => $upper,
            'middle' => $mean,
            'lower' => $lower,
            'percentB' => $upper > $lower ? round((end($closes) - $lower) / ($upper - $lower), 2) : null,
        ];
    }

    /** @param list<float> $closes */
    public static function volatility(array $closes): ?float
    {
        if (\count($closes) < 3) {
            return null;
        }
        $changes = [];
        for ($i = 1, $n = \count($closes); $i < $n; ++$i) {
            if ($closes[$i - 1] > 0 && $closes[$i] > 0) {
                $changes[] = log($closes[$i] / $closes[$i - 1]);
            }
        }
        $mean = array_sum($changes) / \count($changes);
        $variance = array_sum(array_map(static fn (float $c) => ($c - $mean) ** 2, $changes)) / max(1, \count($changes) - 1);

        return round(sqrt($variance) * sqrt(252) * 100, 1);
    }

    /**
     * Наибольшее падение от пика за период, % (отрицательное или 0).
     *
     * @param list<float> $closes
     */
    public static function maxDrawdown(array $closes): ?float
    {
        if ($closes === []) {
            return null;
        }
        [$peak, $worst] = [$closes[0], 0.0];
        foreach ($closes as $close) {
            $peak = max($peak, $close);
            $worst = $peak > 0 ? min($worst, ($close / $peak - 1) * 100) : $worst;
        }

        return round($worst, 2);
    }

    /**
     * up — цена выше 50-дневной, а та выше 200-дневной; down — наоборот; sideways — остальное.
     * Без 200-дневной — по 50-дневной.
     */
    public static function trend(float $close, ?float $sma50, ?float $sma200): ?string
    {
        return match (true) {
            $sma50 === null => null,
            $sma200 === null => $close >= $sma50 ? 'up' : 'down',
            $close > $sma50 && $sma50 > $sma200 => 'up',
            $close < $sma50 && $sma50 < $sma200 => 'down',
            default => 'sideways',
        };
    }

    /**
     * «Золотой крест» (50-дневная пересекла 200-дневную снизу вверх) или «крест смерти» за последние 20 дней.
     *
     * @param list<float> $closes
     *
     * @return array{type: string, barsAgo: int}|null
     */
    public static function smaCross(array $closes): ?array
    {
        $n = \count($closes);
        if ($n < 201) {
            return null;
        }
        $diff = static function (int $end) use ($closes): float {
            $window = \array_slice($closes, 0, $end + 1);

            return self::sma($window, 50) - self::sma($window, 200);
        };
        $after = $diff($n - 1);
        for ($ago = 1; $ago <= 20 && $n - 1 - $ago >= 199; ++$ago) {
            $before = $diff($n - 1 - $ago);
            if ($before <= 0 && $after > 0) {
                return ['type' => 'golden', 'barsAgo' => $ago - 1];
            }
            if ($before >= 0 && $after < 0) {
                return ['type' => 'death', 'barsAgo' => $ago - 1];
            }
            $after = $before;
        }

        return null;
    }

    /** @param list<float> $values */
    private static function volumeRatio(array $values): ?float
    {
        if (\count($values) < 50) {
            return null;
        }
        $base = array_sum(\array_slice($values, -50)) / 50;

        return $base > 0 ? round(array_sum(\array_slice($values, -5)) / 5 / $base, 2) : null;
    }

    private static function percent(float $from, float $to): ?float
    {
        return $from == 0.0 ? null : round(($to / $from - 1) * 100, 2);
    }
}
