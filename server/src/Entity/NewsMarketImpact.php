<?php

namespace App\Entity;

use App\Repository\NewsMarketImpactRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Последствие новости для инструмента Мосбиржи: какую бумагу или индекс новость может сдвинуть, куда
 * и почему (прогноз модели) и как цена на самом деле пошла после публикации (замер по свечам ISS).
 *
 * Факт — две цены относительно последней сделки до новости: через час торгов после неё и на закрытие
 * того торгового дня. Пока час или день не прошли, цены промежуточные и обновляются каждым проходом;
 * когда оба замера окончательные, запись закрыта ([settled]) и в ISS за ней больше не ходим.
 */
#[ORM\Entity(repositoryClass: NewsMarketImpactRepository::class)]
#[ORM\Index(name: 'idx_news_market_impact_settled', columns: ['settled'])]
class NewsMarketImpact
{
    public const UP = 'up';
    public const DOWN = 'down';
    /** Влияние есть, но в какую сторону — неясно (зависит от подробностей, которых в новости нет). */
    public const MIXED = 'mixed';
    public const FORECASTS = [self::UP, self::DOWN, self::MIXED];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NewsItem $item;

    /** Код в ISS: SBER, IMOEX, CNYRUB_TOM. */
    #[ORM\Column(length: 36)]
    private string $secid;

    /** Название для подписи: «Сбербанк», «Индекс МосБиржи». */
    #[ORM\Column(length: 100)]
    private string $name;

    /** Рынок ISS (stock/shares, stock/index, currency/selt) — по нему запрашиваются свечи. */
    #[ORM\Column(length: 20)]
    private string $engine;

    #[ORM\Column(length: 20)]
    private string $market;

    #[ORM\Column(length: 10)]
    private string $forecast;

    /** Объяснение модели: куда может пойти цена и почему, одно-два предложения. */
    #[ORM\Column(type: Types::TEXT)]
    private string $reason;

    /** Последняя сделка до новости; null — свечей ещё нет (или новость старше истории свечей). */
    #[ORM\Column(nullable: true)]
    private ?float $basePrice = null;

    /** Цена через час торгов после новости — промежуточная, пока час не набрался. */
    #[ORM\Column(nullable: true)]
    private ?float $hourPrice = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $hourFinal = false;

    /** Цена на закрытие торгового дня, в который вышла новость (или первого дня торгов после неё). */
    #[ORM\Column(nullable: true)]
    private ?float $dayPrice = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $settled = false;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(NewsItem $item, string $secid, string $name, string $engine, string $market, string $forecast, string $reason, \DateTimeImmutable $now)
    {
        $this->item = $item;
        $this->secid = $secid;
        $this->name = mb_substr($name, 0, 100);
        $this->engine = $engine;
        $this->market = $market;
        $this->forecast = $forecast;
        $this->reason = $reason;
        $this->createdAt = $now;
    }

    /** Результат замера по свечам: промежуточный или окончательный. */
    public function measure(?float $base, ?float $hour, bool $hourFinal, ?float $day, bool $settled): void
    {
        $this->basePrice = $base;
        $this->hourPrice = $hour;
        $this->hourFinal = $hourFinal;
        $this->dayPrice = $day;
        $this->settled = $settled;
    }

    /** Изменение к цене до новости, %; null — замера ещё нет. */
    public static function change(?float $base, ?float $price): ?float
    {
        return $base === null || $price === null || $base == 0.0 ? null : round(($price / $base - 1) * 100, 2);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getItem(): NewsItem
    {
        return $this->item;
    }

    public function getSecid(): string
    {
        return $this->secid;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getMarket(): string
    {
        return $this->market;
    }

    public function getForecast(): string
    {
        return $this->forecast;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getBasePrice(): ?float
    {
        return $this->basePrice;
    }

    public function getHourPrice(): ?float
    {
        return $this->hourPrice;
    }

    public function isHourFinal(): bool
    {
        return $this->hourFinal;
    }

    public function getDayPrice(): ?float
    {
        return $this->dayPrice;
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
