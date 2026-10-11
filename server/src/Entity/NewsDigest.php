<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Подробности сводки новостей. Сама сводка — обычная новость издания «Сводка» ([NewsItem]):
 * так она попадает в ленту, API и виджет без отдельного пути. Здесь — период, сюжеты
 * со ссылками на исходные новости и чем её писали.
 */
#[ORM\Entity]
#[ORM\Index(name: 'idx_news_digest_period', columns: ['period', 'period_end'])]
class NewsDigest
{
    public const PERIOD_HOUR = 'hour';
    public const PERIOD_DAY = 'day';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private NewsItem $item;

    #[ORM\Column(length: 8)]
    private string $period;

    #[ORM\Column]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column]
    private \DateTimeImmutable $periodEnd;

    /**
     * Сюжеты: заголовок, суть и id новостей, на которых сюжет основан.
     *
     * @var list<array{title: string, text: string, items: list<int>}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $stories = [];

    /** Сколько новостей ушло модели. */
    #[ORM\Column]
    private int $newsCount = 0;

    /** Модель, как её назвал agy; null — модель по умолчанию. */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(NewsItem $item, string $period, \DateTimeImmutable $periodStart, \DateTimeImmutable $periodEnd)
    {
        $this->item = $item;
        $this->period = $period;
        $this->periodStart = $periodStart;
        $this->periodEnd = $periodEnd;
        $this->createdAt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    /** @param list<array{title: string, text: string, items: list<int>}> $stories */
    public function update(array $stories, int $newsCount, ?string $model): void
    {
        $this->stories = $stories;
        $this->newsCount = $newsCount;
        $this->model = $model;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): NewsItem
    {
        return $this->item;
    }

    public function getPeriod(): string
    {
        return $this->period;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function getPeriodEnd(): \DateTimeImmutable
    {
        return $this->periodEnd;
    }

    /** @return list<array{title: string, text: string, items: list<int>}> */
    public function getStories(): array
    {
        return $this->stories;
    }

    public function getNewsCount(): int
    {
        return $this->newsCount;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
