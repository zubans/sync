<?php

namespace App\Entity;

use App\Repository\AiProviderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Подключённый провайдер ИИ (консольный CLI-агент по подписке или HTTP API-провайдер).
 * Поддерживает приоритеты, включение/выключение и привязку нескольких аккаунтов с ротацией по квотам.
 */
#[ORM\Entity(repositoryClass: AiProviderRepository::class)]
#[ORM\Table(name: 'ai_provider')]
class AiProvider
{
    public const TYPE_ANTIGRAVITY_CLI = 'antigravity_cli';
    public const TYPE_CLAUDE_CLI = 'claude_cli';
    public const TYPE_CODEX_CLI = 'codex_cli';
    public const TYPE_GEMINI_CLI = 'gemini_cli';
    public const TYPE_KIMI_CLI = 'kimi_cli';

    public const TYPE_OLLAMA = 'ollama';
    public const TYPE_OPENAI = 'openai';
    public const TYPE_ANTHROPIC = 'anthropic';
    public const TYPE_GEMINI = 'gemini';
    public const TYPE_OPENAI_COMPATIBLE = 'openai_compatible';

    /** @var list<string> */
    public const CLI_TYPES = [
        self::TYPE_ANTIGRAVITY_CLI,
        self::TYPE_CLAUDE_CLI,
        self::TYPE_CODEX_CLI,
        self::TYPE_GEMINI_CLI,
        self::TYPE_KIMI_CLI,
    ];

    /** @var list<string> */
    public const API_TYPES = [
        self::TYPE_OLLAMA,
        self::TYPE_OPENAI,
        self::TYPE_ANTHROPIC,
        self::TYPE_GEMINI,
        self::TYPE_OPENAI_COMPATIBLE,
    ];

    /** @var list<string> */
    public const ALL_TYPES = [
        ...self::CLI_TYPES,
        ...self::API_TYPES,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $type = self::TYPE_ANTIGRAVITY_CLI;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(options: ['default' => 100])]
    private int $priority = 100;

    #[ORM\Column(options: ['default' => false])]
    private bool $isLocal = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $baseUrl = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $apiKeyEncrypted = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    private array $headers = [];

    #[ORM\Column(options: ['default' => 300])]
    private int $timeoutSeconds = 300;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $defaultModel = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $availableModels = [];

    #[ORM\Column(options: ['default' => true])]
    private bool $useForSummarization = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $useForEmbeddings = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastCheckedAt = null;

    #[ORM\Column(nullable: true)]
    private ?bool $lastCheckOk = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, AiAgentAccount> */
    #[ORM\OneToMany(mappedBy: 'provider', targetEntity: AiAgentAccount::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['priority' => 'ASC', 'id' => 'ASC'])]
    private Collection $accounts;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->accounts = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = trim($name);
        $this->touch();

        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        if (!\in_array($type, self::ALL_TYPES, true)) {
            throw new \InvalidArgumentException(\sprintf('Неизвестный тип провайдера ИИ «%s».', $type));
        }
        $this->type = $type;
        $this->touch();

        return $this;
    }

    public function isCliAgent(): bool
    {
        return \in_array($this->type, self::CLI_TYPES, true);
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

    public function isLocal(): bool
    {
        return $this->isLocal;
    }

    public function setIsLocal(bool $isLocal): self
    {
        $this->isLocal = $isLocal;
        $this->touch();

        return $this;
    }

    public function getBaseUrl(): ?string
    {
        return $this->baseUrl;
    }

    public function setBaseUrl(?string $baseUrl): self
    {
        $trimmed = $baseUrl !== null ? rtrim(trim($baseUrl), '/') : '';
        $this->baseUrl = $trimmed !== '' ? $trimmed : null;
        $this->touch();

        return $this;
    }

    public function getApiKeyEncrypted(): ?string
    {
        return $this->apiKeyEncrypted;
    }

    public function setApiKeyEncrypted(?string $apiKeyEncrypted): self
    {
        $this->apiKeyEncrypted = $apiKeyEncrypted !== '' ? $apiKeyEncrypted : null;
        $this->touch();

        return $this;
    }

    /** @return array<string, string> */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /** @param array<string, string> $headers */
    public function setHeaders(array $headers): self
    {
        $clean = [];
        foreach ($headers as $key => $value) {
            $k = trim((string) $key);
            if ($k !== '') {
                $clean[$k] = (string) $value;
            }
        }
        $this->headers = $clean;
        $this->touch();

        return $this;
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function setTimeoutSeconds(int $timeoutSeconds): self
    {
        $this->timeoutSeconds = max(10, min(3600, $timeoutSeconds));
        $this->touch();

        return $this;
    }

    public function getDefaultModel(): ?string
    {
        return $this->defaultModel;
    }

    public function setDefaultModel(?string $defaultModel): self
    {
        $trimmed = $defaultModel !== null ? trim($defaultModel) : '';
        $this->defaultModel = $trimmed !== '' ? $trimmed : null;
        $this->touch();

        return $this;
    }

    /** @return list<string> */
    public function getAvailableModels(): array
    {
        return $this->availableModels;
    }

    /** @param list<string> $models */
    public function setAvailableModels(array $models): self
    {
        $clean = [];
        foreach ($models as $model) {
            $item = trim((string) $model);
            if ($item !== '' && !\in_array($item, $clean, true)) {
                $clean[] = $item;
            }
        }
        $this->availableModels = $clean;
        $this->touch();

        return $this;
    }

    public function isUseForSummarization(): bool
    {
        return $this->useForSummarization;
    }

    public function setUseForSummarization(bool $use): self
    {
        $this->useForSummarization = $use;
        $this->touch();

        return $this;
    }

    public function isUseForEmbeddings(): bool
    {
        return $this->useForEmbeddings;
    }

    public function setUseForEmbeddings(bool $use): self
    {
        $this->useForEmbeddings = $use;
        $this->touch();

        return $this;
    }

    public function getLastCheckedAt(): ?\DateTimeImmutable
    {
        return $this->lastCheckedAt;
    }

    public function getLastCheckOk(): ?bool
    {
        return $this->lastCheckOk;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function recordCheck(bool $ok, ?string $error = null): self
    {
        $this->lastCheckedAt = new \DateTimeImmutable();
        $this->lastCheckOk = $ok;
        $this->lastError = $ok ? null : ($error !== null ? mb_substr(trim($error), 0, 1000) : 'Ошибка проверки');
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

    /** @return Collection<int, AiAgentAccount> */
    public function getAccounts(): Collection
    {
        return $this->accounts;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
