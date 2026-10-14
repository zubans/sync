<?php

namespace App\Entity;

use App\Repository\MarketAnalysisRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Последний анализ инструмента для пользователя: что модель (agy) советует сделать — докупить, держать,
 * продать или пока не входить, — куда, по её мнению, пойдёт цена и почему. Модель видит новостные
 * триггеры, технические индикаторы по дневным свечам, сведения о бумаге и покупки пользователя, поэтому
 * анализ у каждого пользователя свой. Запись одна на инструмент и переписывается новым анализом.
 */
#[ORM\Entity(repositoryClass: MarketAnalysisRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_market_analysis_user_secid', columns: ['user_id', 'secid'])]
class MarketAnalysis
{
    /** Докупить (или купить, если позиции нет). */
    public const BUY = 'buy';
    public const HOLD = 'hold';
    /** Продать или сократить позицию. */
    public const SELL = 'sell';
    /** Позиции нет, входить пока рано — наблюдать. */
    public const WAIT = 'wait';
    public const RECOMMENDATIONS = [self::BUY, self::HOLD, self::SELL, self::WAIT];

    public const OUTLOOKS = ['up', 'down', 'sideways'];

    /** Предупреждение: none — спокойно, watch — присмотреться, act — пора действовать. */
    public const URGENCIES = ['none', 'watch', 'act'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 36)]
    private string $secid;

    /** Пользователь попросил пересчитать (или поменялись покупки): следующий проход возьмёт инструмент первым. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $analyzedAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $recommendation = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $outlook = null;

    /** Уверенность модели, 0–100. */
    #[ORM\Column(nullable: true)]
    private ?int $confidence = null;

    #[ORM\Column(length: 10, nullable: true)]
    private ?string $urgency = null;

    /** Горизонт прогноза словами: «1–3 месяца». */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $horizon = null;

    /** Вывод в двух-трёх предложениях. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** Что говорят новости: триггеры и их вероятное влияние. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $news = null;

    /** Что говорят графики и индикаторы. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $technical = null;

    /** Совет по позиции пользователя; null — покупок нет. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $position = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $risks = [];

    /** @var list<string> что может изменить вывод — за чем следить */
    #[ORM\Column(type: Types::JSON)]
    private array $triggers = [];

    /** Цена на момент анализа — видно, насколько она ушла с тех пор. */
    #[ORM\Column(nullable: true)]
    private ?float $price = null;

    /** Сколько новостей ушло модели. */
    #[ORM\Column(options: ['default' => 0])]
    private int $newsCount = 0;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $model = null;

    /** Последний анализ не удался: модель или ISS не ответили. Прежний вывод остаётся. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    public function __construct(User $user, string $secid)
    {
        $this->user = $user;
        $this->secid = $secid;
    }

    public function request(\DateTimeImmutable $now): void
    {
        $this->requestedAt = $now;
    }

    /**
     * @param array{recommendation: string, outlook: string, confidence: int, urgency: string, horizon: string, summary: string,
     *              news: string, technical: string, position: ?string, risks: list<string>, triggers: list<string>} $result
     */
    public function complete(array $result, ?float $price, int $newsCount, ?string $model, \DateTimeImmutable $now): void
    {
        $this->recommendation = $result['recommendation'];
        $this->outlook = $result['outlook'];
        $this->confidence = $result['confidence'];
        $this->urgency = $result['urgency'];
        $this->horizon = mb_substr($result['horizon'], 0, 100);
        $this->summary = $result['summary'];
        $this->news = $result['news'];
        $this->technical = $result['technical'];
        $this->position = $result['position'];
        $this->risks = $result['risks'];
        $this->triggers = $result['triggers'];
        $this->price = $price;
        $this->newsCount = $newsCount;
        $this->model = $model !== null ? mb_substr($model, 0, 100) : null;
        $this->analyzedAt = $now;
        $this->requestedAt = null;
        $this->error = null;
    }

    /** Анализ не удался: запрос снимается, чтобы не долбить модель каждый проход, — повтор по сроку. */
    public function fail(string $error, \DateTimeImmutable $now): void
    {
        $this->error = mb_substr($error, 0, 1000);
        $this->requestedAt = null;
        // Без прежнего вывода время ставится, чтобы следующая попытка была по сроку, а не сразу.
        $this->analyzedAt ??= $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSecid(): string
    {
        return $this->secid;
    }

    public function getRequestedAt(): ?\DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function getAnalyzedAt(): ?\DateTimeImmutable
    {
        return $this->analyzedAt;
    }

    public function getRecommendation(): ?string
    {
        return $this->recommendation;
    }

    public function getOutlook(): ?string
    {
        return $this->outlook;
    }

    public function getConfidence(): ?int
    {
        return $this->confidence;
    }

    public function getUrgency(): ?string
    {
        return $this->urgency;
    }

    public function getHorizon(): ?string
    {
        return $this->horizon;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getNews(): ?string
    {
        return $this->news;
    }

    public function getTechnical(): ?string
    {
        return $this->technical;
    }

    public function getPosition(): ?string
    {
        return $this->position;
    }

    /** @return list<string> */
    public function getRisks(): array
    {
        return $this->risks;
    }

    /** @return list<string> */
    public function getTriggers(): array
    {
        return $this->triggers;
    }

    public function getPrice(): ?float
    {
        return $this->price;
    }

    public function getNewsCount(): int
    {
        return $this->newsCount;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function getError(): ?string
    {
        return $this->error;
    }
}
