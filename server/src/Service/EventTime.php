<?php

namespace App\Service;

/**
 * Время событий: в базе — UTC без пояса. События на весь день — полночь UTC,
 * в API — даты; так же их хранит Android CalendarContract.
 */
final class EventTime
{
    public static function parse(?string $value, bool $allDay): ?\DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        $utc = new \DateTimeZone('UTC');
        if ($allDay) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $utc);

            return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
        }
        // Время обязательно со смещением или Z: без него непонятно, чьё это время.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/', $value)) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone($utc);
        } catch (\Exception) {
            return null;
        }
    }

    /** Значение из базы — как UTC, независимо от пояса PHP по умолчанию. */
    public static function fromDb(\DateTimeImmutable $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value->format('Y-m-d H:i:s'), new \DateTimeZone('UTC'));
    }

    public static function format(\DateTimeImmutable $value, bool $allDay): string
    {
        $utc = self::fromDb($value);

        return $allDay ? $utc->format('Y-m-d') : $utc->format('Y-m-d\TH:i:s\Z');
    }
}
