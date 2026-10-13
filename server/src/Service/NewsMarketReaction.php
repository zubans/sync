<?php

namespace App\Service;

/**
 * Как цена инструмента пошла после новости — по десятиминутным свечам ISS.
 *
 * - цена до новости — закрытие последней свечи, целиком закончившейся до публикации: новость в выходные
 *   сравнивается с закрытием пятницы;
 * - через час — закрытие шестой свечи после публикации (свеча, внутри которой вышла новость, — первая):
 *   час именно торгов, новость в 23:45 досчитается утром;
 * - на закрытие — последняя свеча того московского дня, в который начались торги после новости.
 *
 * Пока свечей не хватает, цены промежуточные (последняя известная); окончательные — когда набрался час
 * и закончился день.
 */
final class NewsMarketReaction
{
    public const HOUR_CANDLES = 60 / MoexIss::CANDLE_MINUTES;

    /**
     * @param list<array{begin: \DateTimeImmutable, close: float}> $candles по порядку времени, UTC
     *
     * @return array{base: ?float, hour: ?float, hourFinal: bool, day: ?float, dayFinal: bool}
     */
    public static function measure(array $candles, \DateTimeImmutable $newsAt, \DateTimeImmutable $now): array
    {
        $moscow = new \DateTimeZone('Europe/Moscow');
        $length = new \DateInterval('PT'.MoexIss::CANDLE_MINUTES.'M');
        $base = null;
        $after = [];
        foreach ($candles as $candle) {
            if ($candle['begin']->add($length) <= $newsAt) {
                $base = $candle['close'];
            } else {
                $after[] = $candle;
            }
        }
        if ($after === []) {
            return ['base' => $base, 'hour' => null, 'hourFinal' => false, 'day' => null, 'dayFinal' => false];
        }

        $hour = \array_slice($after, 0, self::HOUR_CANDLES);
        $day = $after[0]['begin']->setTimezone($moscow)->format('Y-m-d');
        $dayClose = null;
        foreach ($after as $candle) {
            if ($candle['begin']->setTimezone($moscow)->format('Y-m-d') !== $day) {
                break;
            }
            $dayClose = $candle['close'];
        }

        return [
            'base' => $base,
            'hour' => end($hour)['close'],
            'hourFinal' => \count($hour) === self::HOUR_CANDLES,
            'day' => $dayClose,
            // Торги дня закончились, когда в Москве наступил следующий день.
            'dayFinal' => $now->setTimezone($moscow)->format('Y-m-d') > $day,
        ];
    }
}
