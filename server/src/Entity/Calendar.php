<?php

namespace App\Entity;

use App\Repository\CalendarRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * Календарь пользователя. С отметкой «семья» его видят и правят все члены семьи владельца;
 * вышел владелец из семьи — календарь снова только его.
 *
 * Счётчик ревизий общий для событий календаря: клиент забирает изменения «с ревизии N»
 * и отправляет правки с ревизией, от которой их делал (как в хранилище паролей).
 */
#[ORM\Entity(repositoryClass: CalendarRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_calendar_uuid', columns: ['uuid'])]
class Calendar
{
    public const DEFAULT_COLOR = '#4285F4';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $owner;

    #[ORM\Column(length: 100)]
    private string $name;

    /** Цвет в формате #RRGGBB. */
    #[ORM\Column(length: 7)]
    private string $color = self::DEFAULT_COLOR;

    #[ORM\Column]
    private bool $familyShared = false;

    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $owner, string $name)
    {
        $this->uuid = Uuid::v7()->toRfc4122();
        $this->owner = $owner;
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name.' ('.$this->owner->getEmail().')';
    }

    public function nextRevision(): int
    {
        return ++$this->revision;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getColor(): string
    {
        return $this->color;
    }

    public function setColor(string $color): static
    {
        $this->color = strtoupper($color);

        return $this;
    }

    public function isFamilyShared(): bool
    {
        return $this->familyShared;
    }

    public function setFamilyShared(bool $familyShared): static
    {
        $this->familyShared = $familyShared;

        return $this;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
