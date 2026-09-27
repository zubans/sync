<?php

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/** Токен доступа к API. В БД лежит только SHA-256 от токена. */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastUsedAt;

    private function __construct(User $user, string $tokenHash)
    {
        $this->user = $user;
        $this->tokenHash = $tokenHash;
        $this->createdAt = new \DateTimeImmutable();
        $this->lastUsedAt = $this->createdAt;
    }

    /**
     * @return array{self, string} сущность и сам токен (показывается клиенту один раз)
     */
    public static function issue(User $user): array
    {
        $plain = bin2hex(random_bytes(32));

        return [new self($user, self::hash($plain)), $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLastUsedAt(): \DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function markUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }
}
