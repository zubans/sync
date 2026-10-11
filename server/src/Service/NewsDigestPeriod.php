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

    /**
     * Периоды, сводка за которые уже должна быть. Пропущенные раньше часы не догоняются:
     * часовая сводка нужна, пока она свежая.
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

    /** Ключ новости-сводки: «hour:2026-10-11T14:00Z». */
    public function key(): string
    {
        return $this->period.':'.$this->end->format('Y-m-d\TH:i\Z');
    }
}
