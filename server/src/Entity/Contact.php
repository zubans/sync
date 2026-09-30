<?php

namespace App\Entity;

use App\Repository\ContactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Контакт всегда принадлежит пользователю (владельцу). Отметка «семья» — общий контакт семьи:
 * он ставится на новые устройства членов семьи (один раз, дальше это их обычный контакт).
 * Добавление в семью и удаление из неё только меняют отметку — на телефонах ничего не удаляется.
 */
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Index(name: 'idx_contact_name', columns: ['name'])]
#[ORM\Index(name: 'idx_contact_deleted_at', columns: ['deleted_at'])]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Публичный идентификатор, которым оперирует клиент. */
    #[ORM\Column(length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\ManyToOne(inversedBy: 'contacts')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    private ?Family $family = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $name = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $phones = [];

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $emails = [];

    /** SHA-256 фото контакта; сам файл — в хранилище фото (ContactPhotoStorage). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $photoSha256 = null;

    /** День рождения: «ГГГГ-ММ-ДД» или «--ММ-ДД», если год неизвестен (как в Android). */
    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Regex(pattern: '/^(\d{4}|-)-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/', message: 'Дата в формате ГГГГ-ММ-ДД или --ММ-ДД (без года).')]
    private ?string $birthday = null;

    /**
     * Номер правки, сделанной на сервере (админка, объединение). Устройство, которое её ещё
     * не получило (ContactLink::$appliedRevision меньше), получит новую версию при синхронизации.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $serverRevision = 0;

    /**
     * Контакт в корзине: удалён на устройстве или администратором. Через TRASH_DAYS дней удаляется
     * окончательно, до этого его можно восстановить.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    /** В корзину его отправил администратор: с телефонов контакт тоже удаляется. */
    #[ORM\Column(options: ['default' => false])]
    private bool $deletedOnServer = false;

    /** Когда восстановлен из корзины: телефоны владельца, где его нет, получат его при синхронизации. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $restoredAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->uuid = Uuid::v7()->toRfc4122();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
    }

    public static function personal(User $user): self
    {
        $contact = new self();
        $contact->user = $user;

        return $contact;
    }

    public function __toString(): string
    {
        return $this->name ?? $this->phones[0] ?? $this->emails[0] ?? $this->uuid;
    }

    /**
     * @param list<string> $phones
     * @param list<string> $emails
     *
     * @return bool были ли изменения
     */
    public function apply(?string $name, array $phones, array $emails, ?string $photoSha256 = null, ?string $birthday = null): bool
    {
        $name = self::normalizeName($name);
        $phones = self::normalizePhones($phones);
        $emails = self::normalizeEmails($emails);
        if ($this->name === $name && $this->phones === $phones && $this->emails === $emails
            && $this->photoSha256 === $photoSha256 && $this->birthday === $birthday
        ) {
            return false;
        }

        $this->name = $name;
        $this->phones = $phones;
        $this->emails = $emails;
        $this->photoSha256 = $photoSha256;
        $this->birthday = $birthday;
        $this->touch();

        return true;
    }

    public function getPhotoSha256(): ?string
    {
        return $this->photoSha256;
    }

    public function setPhotoSha256(?string $photoSha256): static
    {
        $this->photoSha256 = $photoSha256;

        return $this;
    }

    public function getBirthday(): ?string
    {
        return $this->birthday;
    }

    public function setBirthday(?string $birthday): static
    {
        $birthday = trim((string) $birthday);
        $this->birthday = $birthday === '' ? null : $birthday;

        return $this;
    }

    public function getServerRevision(): int
    {
        return $this->serverRevision;
    }

    /** Контакт изменили на сервере — устройства владельца получат новую версию. */
    public function markEditedOnServer(): void
    {
        ++$this->serverRevision;
        $this->touch();
    }

    public const TRASH_DAYS = 30;

    /** Поля, которые хранит телефон: их правка на сервере отправляется на устройства. */
    public const SYNCED_FIELDS = ['name', 'phones', 'emails', 'photoSha256', 'birthday'];

    /** Удалён на устройстве: в корзину, на других телефонах остаётся. */
    public function markDeleted(): void
    {
        $this->deletedAt ??= new \DateTimeImmutable();
    }

    /** Удалён администратором: в корзину и с телефонов. */
    public function moveToTrash(): void
    {
        $this->markDeleted();
        $this->deletedOnServer = true;
        $this->restoredAt = null;
    }

    public function restoreFromTrash(): void
    {
        $this->deletedAt = null;
        $this->deletedOnServer = false;
        $this->restoredAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function isDeletedOnServer(): bool
    {
        return $this->deletedOnServer;
    }

    public function getRestoredAt(): ?\DateTimeImmutable
    {
        return $this->restoredAt;
    }

    public function getTrashExpiresAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt?->modify('+'.self::TRASH_DAYS.' days');
    }

    /** Контакт снова пришёл с устройства — значит, он там есть (кроме удалённых администратором). */
    public function undelete(): bool
    {
        if ($this->deletedAt === null || $this->deletedOnServer) {
            return false;
        }
        $this->deletedAt = null;

        return true;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    #[Assert\Callback]
    public function validateOwner(ExecutionContextInterface $context): void
    {
        if ($this->user === null) {
            $context->buildViolation('Укажите владельца контакта.')->atPath('user')->addViolation();
        } elseif ($this->family !== null && $this->user->getFamily() !== $this->family) {
            $context->buildViolation('Владелец не состоит в этой семье.')->atPath('family')->addViolation();
        }
    }

    public function isShared(): bool
    {
        return $this->family !== null;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getFamily(): ?Family
    {
        return $this->family;
    }

    public function setFamily(?Family $family): static
    {
        $this->family = $family;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = self::normalizeName($name);

        return $this;
    }

    /** @return list<string> */
    public function getPhones(): array
    {
        return $this->phones;
    }

    /** @param array<string|null>|null $phones */
    public function setPhones(?array $phones): static
    {
        $this->phones = self::normalizePhones($phones ?? []);

        return $this;
    }

    /** @return list<string> */
    public function getEmails(): array
    {
        return $this->emails;
    }

    /** @param array<string|null>|null $emails */
    public function setEmails(?array $emails): static
    {
        $this->emails = self::normalizeEmails($emails ?? []);

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private static function normalizeName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : $name;
    }

    /**
     * @param array<string|null> $values
     *
     * @return list<string>
     */
    private static function normalizePhones(array $values): array
    {
        return self::uniqueNonEmpty(array_map(static fn (?string $v) => trim((string) $v), $values));
    }

    /**
     * @param array<string|null> $values
     *
     * @return list<string>
     */
    private static function normalizeEmails(array $values): array
    {
        return self::uniqueNonEmpty(array_map(static fn (?string $v) => mb_strtolower(trim((string) $v)), $values));
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private static function uniqueNonEmpty(array $values): array
    {
        return array_values(array_unique(array_filter($values, static fn (string $v) => $v !== '')));
    }
}
