<?php

namespace App\Entity;

use App\Repository\VaultRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Хранилище паролей пользователя. Сервер не может его расшифровать:
 * ключ хранилища зашифрован ключом из мастер-пароля, который знает только клиент.
 */
#[ORM\Entity(repositoryClass: VaultRepository::class)]
class Vault
{
    public const KDF_PBKDF2_SHA256 = 'pbkdf2-sha256';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32)]
    private string $kdfAlgorithm;

    #[ORM\Column]
    private int $kdfIterations;

    /** Соль KDF, base64. */
    #[ORM\Column(length: 64)]
    private string $kdfSalt;

    /** Ключ хранилища, зашифрованный ключом из мастер-пароля (base64 iv+ciphertext). */
    #[ORM\Column(length: 255)]
    private string $protectedKey;

    /** Счётчик изменений: каждая принятая правка записи получает следующий номер. */
    #[ORM\Column]
    private int $revision = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, string $kdfAlgorithm, int $kdfIterations, string $kdfSalt, string $protectedKey)
    {
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
        $this->setKey($kdfAlgorithm, $kdfIterations, $kdfSalt, $protectedKey);
    }

    /** Смена мастер-пароля: ключ хранилища тот же, меняется только его «обёртка». */
    public function setKey(string $kdfAlgorithm, int $kdfIterations, string $kdfSalt, string $protectedKey): void
    {
        $this->kdfAlgorithm = $kdfAlgorithm;
        $this->kdfIterations = $kdfIterations;
        $this->kdfSalt = $kdfSalt;
        $this->protectedKey = $protectedKey;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function nextRevision(): int
    {
        $this->updatedAt = new \DateTimeImmutable();

        return ++$this->revision;
    }

    public function __toString(): string
    {
        return 'Хранилище '.$this->user->getEmail();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getKdfAlgorithm(): string
    {
        return $this->kdfAlgorithm;
    }

    public function getKdfIterations(): int
    {
        return $this->kdfIterations;
    }

    public function getKdfSalt(): string
    {
        return $this->kdfSalt;
    }

    public function getProtectedKey(): string
    {
        return $this->protectedKey;
    }

    public function getRevision(): int
    {
        return $this->revision;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
