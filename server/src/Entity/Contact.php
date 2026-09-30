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

    /** Контакт удалили на устройстве: на сервере он остаётся, но не восстанавливается на телефоны. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

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
    public function apply(?string $name, array $phones, array $emails, ?string $photoSha256 = null): bool
    {
        $name = self::normalizeName($name);
        $phones = self::normalizePhones($phones);
        $emails = self::normalizeEmails($emails);
        if ($this->name === $name && $this->phones === $phones && $this->emails === $emails && $this->photoSha256 === $photoSha256) {
            return false;
        }

        $this->name = $name;
        $this->phones = $phones;
        $this->emails = $emails;
        $this->photoSha256 = $photoSha256;
        $this->touch();

        return true;
    }

    public function getPhotoSha256(): ?string
    {
        return $this->photoSha256;
    }

    public function markDeleted(): void
    {
        $this->deletedAt ??= new \DateTimeImmutable();
    }

    /** Контакт снова пришёл с устройства — значит, он там есть. */
    public function undelete(): bool
    {
        if ($this->deletedAt === null) {
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
