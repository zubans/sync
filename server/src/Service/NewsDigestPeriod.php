<?php

namespace App\Service;

use App\Entity\NewsDigest;

/**
 * Период сводки. Часовая — за последний полный час, дневная — за 24 часа к 07:00 по Москве
 * (04:00 UTC): к утру готова сводка за прошедшие сутки. Ключ периода — ключ новости-сводки,
 * поэтому за один период сводка не появится дважды.
 */
final readonly class NewsDigestPeriod
{
    /** Конец дневной сводки — 07:00 по Москве. */
    public const DAY_END_HOUR_UTC = 4;

    public function __construct(
        public string $period,
        /** Начало и конец периода, UTC; конец не входит. */
        public \DateTimeImmutable $start,
        public \DateTimeImmutable $end,
    ) {
    }

    /** Сколько прошлых часов дописывать, если их сводка не вышла (сбой модели): дальше она уже не свежая. */
    public const CATCH_UP_HOURS = 3;

    /**
     * Периоды, сводка за которые уже должна быть: последний полный час и сутки.
     *
     * @return list<self>
     */
    public static function due(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $hourEnd = $now->setTime((int) $now->format('H'), 0);
        $dayEnd = $now->setTime(self::DAY_END_HOUR_UTC, 0);
        if ($dayEnd > $now) {
            $dayEnd = $dayEnd->modify('-1 day');
        }

        return [
            new self(NewsDigest::PERIOD_HOUR, $hourEnd->modify('-1 hour'), $hourEnd),
            new self(NewsDigest::PERIOD_DAY, $dayEnd->modify('-1 day'), $dayEnd),
        ];
    }

    /**
     * Часы перед последним полным, за которые сводка ещё нужна, если её нет: модель бывает недоступна
     * («No capacity available», 503) — тогда час дописывается на следующих проходах. Свежие первыми.
     *
     * @return list<self>
     */
    public static function catchUp(\DateTimeImmutable $now): array
    {
        $now = $now->setTimezone(new \DateTimeZone('UTC'));
        $hourEnd = $now->setTime((int) $now->format('H'), 0);
        $periods = [];
        for ($i = 1; $i < self::CATCH_UP_HOURS; ++$i) {
            $end = $hourEnd->modify("-$i hour");
            $periods[] = new self(NewsDigest::PERIOD_HOUR, $end->modify('-1 hour'), $end);
        }

        return $periods;
    }

    /** Ключ новости-сводки: «hour:2026-10-11T14:00Z». */
    public function key(): string
    {
        return $this->period.':'.$this->end->format('Y-m-d\TH:i\Z');
    }
}
