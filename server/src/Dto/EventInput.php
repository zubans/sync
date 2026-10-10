<?php

namespace App\Dto;

use App\Service\EventTime;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Событие в запросе. Время — ISO 8601 со смещением (`2026-10-10T15:00:00+03:00`),
 * у события на весь день — даты (`2026-10-11`), конец не включается.
 * Для правки — ревизия, от которой её делали; не совпала — конфликт и текущая версия.
 */
final class EventInput
{
    public function __construct(
        /** Идентификатор события; при создании можно не передавать — сервер выдаст сам. */
        #[Assert\Uuid]
        public readonly ?string $id = null,

        #[Assert\PositiveOrZero]
        public readonly ?int $baseRevision = null,

        public readonly bool $deleted = false,

        #[Assert\Length(max: 500)]
        public readonly string $title = '',

        public readonly ?string $start = null,

        public readonly ?string $end = null,

        public readonly bool $allDay = false,

        #[Assert\Length(max: 500)]
        public readonly ?string $location = null,

        #[Assert\Length(max: 10000)]
        public readonly ?string $description = null,

        #[Assert\Regex('/^#[0-9A-Fa-f]{6}$/', message: 'Цвет — в формате #RRGGBB.')]
        public readonly ?string $color = null,

        /** Правило повторения RFC 5545 без префикса «RRULE:», например FREQ=WEEKLY;BYDAY=MO. */
        #[Assert\Length(max: 500)]
        #[Assert\Regex('/^[A-Za-z0-9=;,:+\-]+$/', message: 'Неверное правило повторения.')]
        public readonly ?string $rrule = null,
    ) {
    }

    #[Assert\Callback]
    public function validateTimes(ExecutionContextInterface $context): void
    {
        if ($this->deleted) {
            return;
        }
        if (trim($this->title) === '') {
            $context->buildViolation('Нужно название события.')->atPath('title')->addViolation();
        }
        $start = EventTime::parse($this->start, $this->allDay);
        $end = EventTime::parse($this->end, $this->allDay);
        if ($start === null) {
            $context->buildViolation($this->allDay ? 'Начало — дата YYYY-MM-DD.' : 'Начало — дата и время ISO 8601.')->atPath('start')->addViolation();
        }
        if ($end === null) {
            $context->buildViolation($this->allDay ? 'Конец — дата YYYY-MM-DD.' : 'Конец — дата и время ISO 8601.')->atPath('end')->addViolation();
        }
        if ($start !== null && $end !== null && ($this->allDay ? $end <= $start : $end < $start)) {
            $context->buildViolation('Конец раньше начала.')->atPath('end')->addViolation();
        }
    }
}
