<?php

namespace App\Entity;

use App\Repository\CalendarEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Событие календаря. Время хранится в UTC; событие на весь день — от полуночи UTC первого дня
 * до полуночи UTC дня после последнего (конец не включается), как в Android CalendarContract.
 *
 * Удалённое событие остаётся «надгробием» (deleted), чтобы удаление дошло до всех устройств.
 * Правило повторения (RRULE, RFC 5545) сервер хранит как есть и не раскрывает.
 */
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_calendar_event_uuid', columns: ['uuid'])]
#[ORM\Index(name: 'idx_calendar_event_revision', columns: ['calendar_id', 'revision'])]
#[ORM\Index(name: 'idx_calendar_event_start', columns: ['start_at'])]
class CalendarEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Идентификатор события; его может сгенерировать клиент. */
    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Calendar $calendar;

    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column(length: 500)]
    private string $title = '';

    #[ORM\Column]
    private \DateTimeImmutable $startAt;

    #[ORM\Column]
    private \DateTimeImmutable $endAt;

    #[ORM\Column]
    private bool $allDay = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $location = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** Свой цвет события; null — цвет календаря. */
    #[ORM\Column(length: 7, nullable: true)]
    private ?string $color = null;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $rrule = null;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?User $updatedBy = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Calendar $calendar, string $uuid)
    {
        $this->calendar = $calendar;
        $this->uuid = $uuid;
        $this->startAt = $this->endAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    /** Любая правка получает новую ревизию календаря — так её заберут остальные устройства. */
    public function touch(?User $by): void
    {
        $this->revision = $this->calendar->nextRevision();
        $this->updatedBy = $by;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markDeleted(?User $by): void
    {
        $this->deleted = true;
        $this->touch($by);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getCalendar(): Calendar
    {
        return $this->calendar;
    }

    public function setCalendar(Calendar $calendar): static
    {
        $this->calendar = $calendar;

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStartAt(): \DateTimeImmutable
    {
        return $this->startAt;
    }

    public function setStartAt(\DateTimeImmutable $startAt): static
    {
        $this->startAt = $startAt;

        return $this;
    }

    public function getEndAt(): \DateTimeImmutable
    {
        return $this->endAt;
    }

    public function setEndAt(\DateTimeImmutable $endAt): static
    {
        $this->endAt = $endAt;

        return $this;
    }

    public function isAllDay(): bool
    {
        return $this->allDay;
    }

    public function setAllDay(bool $allDay): static
    {
        $this->allDay = $allDay;

        return $this;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color !== null ? strtoupper($color) : null;

        return $this;
    }

    public function getRrule(): ?string
    {
        return $this->rrule;
    }

    public function setRrule(?string $rrule): static
    {
        $this->rrule = $rrule;

        return $this;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
