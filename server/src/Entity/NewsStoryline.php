<?php

namespace App\Entity;

use App\Repository\NewsStorylineRepository;
use App\Service\NewsVector;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Сюжетная линия — одна история во времени из новостей разных изданий и на разных языках:
 * «удары по аэропорту Эр-Рияда», «сделка США и России по дизелю». Новость присоединяется к линии,
 * если её вектор близок к центру линии ([centroid] — среднее векторов её новостей). Название
 * и краткое описание даёт модель, когда в линии набирается несколько новостей.
 */
#[ORM\Entity(repositoryClass: NewsStorylineRepository::class)]
#[ORM\Index(name: 'idx_news_storyline_last_seen', columns: ['last_seen_at'])]
class NewsStoryline
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Название линии; null — ещё не названа (одна новость или модель не отвечала). */
    #[ORM\Column(length: 300, nullable: true)]
    private ?string $title = null;

    /** О чём линия в одном-двух предложениях. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** Центр линии — нормированное среднее векторов её новостей ([NewsVector]). */
    #[ORM\Column(type: Types::TEXT)]
    private string $centroid;

    #[ORM\Column]
    private int $newsCount = 0;

    /** Сколько новостей было, когда линию называли: выросла в полтора раза — название обновляется. */
    #[ORM\Column]
    private int $namedAtCount = 0;

    /** Время первой и последней новости линии (как `sort_at` новостей). */
    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    /** @param list<float> $vector */
    public function __construct(array $vector, \DateTimeImmutable $at)
    {
        $this->centroid = NewsVector::encode($vector);
        $this->newsCount = 1;
        $this->firstSeenAt = $at;
        $this->lastSeenAt = $at;
    }

    /** @param list<float> $vector */
    public function add(array $vector, \DateTimeImmutable $at): void
    {
        $this->centroid = NewsVector::encode(NewsVector::addToCentroid($this->getCentroid(), $this->newsCount, $vector));
        ++$this->newsCount;
        if ($at < $this->firstSeenAt) {
            $this->firstSeenAt = $at;
        }
        if ($at > $this->lastSeenAt) {
            $this->lastSeenAt = $at;
        }
    }

    /**
     * Забирает новости другой линии (та же история, найденная отдельно — обычно на другом языке).
     * Название остаётся, но на следующем проходе обновляется по новостям обеих линий.
     */
    public function absorb(self $other): void
    {
        [$mine, $theirs] = [$this->newsCount, $other->newsCount];
        $sum = array_map(
            static fn (float $a, float $b) => $a * $mine + $b * $theirs,
            $this->getCentroid(),
            $other->getCentroid(),
        );
        $this->centroid = NewsVector::encode(NewsVector::normalize($sum));
        $this->newsCount += $other->newsCount;
        $this->namedAtCount = 0;
        $this->title ??= $other->title;
        $this->summary ??= $other->summary;
        if ($other->firstSeenAt < $this->firstSeenAt) {
            $this->firstSeenAt = $other->firstSeenAt;
        }
        if ($other->lastSeenAt > $this->lastSeenAt) {
            $this->lastSeenAt = $other->lastSeenAt;
        }
    }

    public function name(string $title, ?string $summary): void
    {
        $this->title = mb_substr($title, 0, 300);
        $this->summary = $summary;
        $this->namedAtCount = $this->newsCount;
    }

    /** Пора дать или обновить название: в линии хотя бы две новости, а с прошлого раза она выросла в полтора раза. */
    public function needsName(): bool
    {
        return $this->newsCount >= 2 && ($this->title === null || $this->newsCount >= $this->namedAtCount * 1.5);
    }

    public function __toString(): string
    {
        return $this->title ?? 'Сюжет #'.$this->id;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** @return list<float> */
    public function getCentroid(): array
    {
        return NewsVector::decode($this->centroid);
    }

    public function getNewsCount(): int
    {
        return $this->newsCount;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
