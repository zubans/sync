<?php

namespace App\Entity;

use App\Repository\AiAgentAccountRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Отдельный аккаунт / слот консольного агента ИИ (Antigravity agy, Claude CLI, Codex CLI, Gemini CLI, Kimi CLI).
 * У каждого аккаунта свой изолированный HOME-каталог, флаг включения, приоритет и состояние остывания.
 */
#[ORM\Entity(repositoryClass: AiAgentAccountRepository::class)]
#[ORM\Table(name: 'ai_agent_account')]
#[ORM\UniqueConstraint(name: 'UNIQ_AI_AGENT_TYPE_SLOT', columns: ['agent_type', 'slot'])]
class AiAgentAccount
{
    public const STATUS_NO_LOGIN = 'no_login';
    public const STATUS_READY = 'ready';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COOLING = 'cooling';
    public const STATUS_AUTH_FAILED = 'auth_failed';
    public const STATUS_INELIGIBLE = 'ineligible';
    public const STATUS_ERROR = 'error';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'accounts')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private AiProvider $provider;

    #[ORM\Column(length: 32)]
    private string $agentType;

    #[ORM\Column(length: 64)]
    private string $slot;

    #[ORM\Column(length: 120)]
    private string $label;

    #[ORM\Column(length: 255)]
    private string $homeDir;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => 100])]
    private int $priority = 100;

    #[ORM\Column(length: 32, options: ['default' => self::STATUS_NO_LOGIN])]
    private string $status = self::STATUS_NO_LOGIN;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $accountEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $authTokenEncrypted = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $coolingUntil = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $coolingReason = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct(AiProvider $provider, string $slot, string $label, string $homeDir)
    {
        $now = new \DateTimeImmutable();
        $this->provider = $provider;
        $this->agentType = $provider->getType();
        $this->slot = trim($slot);
        $this->label = trim($label) !== '' ? trim($label) : $this->slot;
        $this->homeDir = rtrim($homeDir, '/');
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProvider(): AiProvider
    {
        return $this->provider;
    }

    public function setProvider(AiProvider $provider): self
    {
        $this->provider = $provider;
        $this->agentType = $provider->getType();
        $this->touch();

        return $this;
    }

    public function getAgentType(): string
    {
        return $this->agentType;
    }

    public function getSlot(): string
    {
        return $this->slot;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = trim($label) !== '' ? trim($label) : $this->slot;
        $this->touch();

        return $this;
    }

    public function getHomeDir(): string
    {
        return $this->homeDir;
    }

    public function setHomeDir(string $homeDir): self
    {
        $this->homeDir = rtrim($homeDir, '/');
        $this->touch();

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        $this->touch();

        return $this;
    }

    public function getPriority(): int
    {
        return $this->priority;
    }

    public function setPriority(int $priority): self
    {
        $this->priority = max(1, min(9999, $priority));
        $this->touch();

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        $this->touch();

        return $this;
    }

    public function getAccountEmail(): ?string
    {
        return $this->accountEmail;
    }

    public function setAccountEmail(?string $email): self
    {
        $trimmed = $email !== null ? trim($email) : '';
        $this->accountEmail = $trimmed !== '' ? $trimmed : null;
        $this->touch();

        return $this;
    }

    public function getAuthTokenEncrypted(): ?string
    {
        return $this->authTokenEncrypted;
    }

    public function setAuthTokenEncrypted(?string $encrypted): self
    {
        $this->authTokenEncrypted = $encrypted !== '' ? $encrypted : null;
        $this->touch();

        return $this;
    }

    public function getCoolingUntil(): ?\DateTimeImmutable
    {
        return $this->coolingUntil;
    }

    public function getCoolingReason(): ?string
    {
        return $this->coolingReason;
    }

    public function markCooling(int $seconds, string $reason, string $status = self::STATUS_COOLING): self
    {
        $this->status = $status;
        $this->coolingUntil = new \DateTimeImmutable(\sprintf('+%d seconds', max(1, $seconds)));
        $this->coolingReason = mb_substr(trim($reason), 0, 1000);
        $this->lastError = $this->coolingReason;
        $this->touch();

        return $this;
    }

    public function releaseCooldown(string $newStatus = self::STATUS_READY): self
    {
        $this->coolingUntil = null;
        $this->coolingReason = null;
        $this->lastError = null;
        $this->status = $newStatus;
        $this->touch();

        return $this;
    }

    public function isCoolingNow(?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable();

        return $this->coolingUntil !== null && $this->coolingUntil > $now;
    }

    public function markUsed(): self
    {
        $this->lastUsedAt = new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordCheck(string $status, ?string $error = null): self
    {
        $this->lastCheckedAt = new \DateTimeImmutable();
        $this->status = $status;
        $this->lastError = $error !== null && trim($error) !== '' ? mb_substr(trim($error), 0, 1000) : null;
        if ($status === self::STATUS_READY || $status === self::STATUS_ACTIVE) {
            $this->coolingUntil = null;
            $this->coolingReason = null;
        }
        $this->touch();

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

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
