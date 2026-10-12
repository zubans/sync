<?php

namespace App\Entity;

use App\Repository\CalendarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Календарь пользователя. Кому он виден:
 * - только владельцу — личный;
 * - с отметкой «семья» — всем членам семьи владельца;
 * - «Поделиться с» — выбранным членам семьи владельца (календарь Сержика — маме, но не всей семье).
 * Те, кому календарь виден, видят и правят его события; переименовать, перекрасить и удалить
 * календарь может только владелец. Вышел владелец или член семьи из семьи — доступ пропадает.
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

    /** @var Collection<int, User> кому из семьи владельца открыт календарь (кроме «семья» — для всех) */
    #[ORM\ManyToMany(targetEntity: User::class)]
    #[ORM\JoinTable(name: 'calendar_share')]
    #[ORM\JoinColumn(name: 'calendar_id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'user_id', onDelete: 'CASCADE')]
    private Collection $sharedWith;

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
        $this->sharedWith = new ArrayCollection();
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

    /** Владельца меняют только при создании календаря в админке. */
    public function setOwner(User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /** @return Collection<int, User> */
    public function getSharedWith(): Collection
    {
        return $this->sharedWith;
    }

    public function addSharedWith(User $user): static
    {
        if (!$this->sharedWith->contains($user)) {
            $this->sharedWith->add($user);
        }

        return $this;
    }

    public function removeSharedWith(User $user): static
    {
        $this->sharedWith->removeElement($user);

        return $this;
    }

    /**
     * Видит ли календарь пользователь: владелец — всегда, остальные — только из семьи владельца,
     * если календарь семейный или им поделились с этим пользователем.
     */
    public function isVisibleTo(User $user): bool
    {
        if ($this->owner === $user) {
            return true;
        }
        $family = $this->owner->getFamily();

        return $family !== null && $family === $user->getFamily()
            && ($this->familyShared || $this->sharedWith->contains($user));
    }

    /** Делиться можно только с членами семьи владельца: чужим людям календарь не открывается. */
    #[Assert\Callback]
    public function validateSharing(ExecutionContextInterface $context): void
    {
        $family = $this->owner->getFamily();
        foreach ($this->sharedWith as $user) {
            if ($user === $this->owner) {
                $context->buildViolation('Владельцу календарь виден и так.')->atPath('sharedWith')->addViolation();
            } elseif ($family === null || $user->getFamily() !== $family) {
                $context->buildViolation(sprintf('%s не состоит в семье владельца — делиться можно только внутри семьи.', $user->getEmail()))
                    ->atPath('sharedWith')->addViolation();
            }
        }
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
