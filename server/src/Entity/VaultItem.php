<?php

namespace App\Entity;

use App\Repository\VaultItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Зашифрованная запись хранилища. Удалённая запись остаётся «надгробием» (deleted, без данных),
 * чтобы удаление дошло до остальных устройств.
 */
#[ORM\Entity(repositoryClass: VaultItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_vault_item_uuid', columns: ['vault_id', 'uuid'])]
#[ORM\Index(name: 'idx_vault_item_revision', columns: ['vault_id', 'revision'])]
class VaultItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Vault $vault;

    /** Идентификатор записи, его генерирует клиент. */
    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column]
    private int $revision;

    /** Шифротекст записи (base64 iv+ciphertext), null у удалённой. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $data = null;

    #[ORM\Column]
    private bool $deleted = false;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(Vault $vault, string $uuid)
    {
        $this->vault = $vault;
        $this->uuid = $uuid;
        $this->revision = 0;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function write(?string $data, bool $deleted): void
    {
        $this->data = $deleted ? null : $data;
        $this->deleted = $deleted;
        $this->revision = $this->vault->nextRevision();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVault(): Vault
    {
        return $this->vault;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getData(): ?string
    {
        return $this->data;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
