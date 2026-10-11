<?php

namespace App\Entity;

use App\Repository\NewsItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Новость издания. Одна новость — одна запись: при повторном сборе она находится по ключу
 * (RSS guid, без него — адрес статьи) и обновляется, если редакция поправила заголовок или анонс.
 *
 * Время для ленты (`sortAt`) — время публикации, а если издание его не даёт (CNN) — когда сервер
 * впервые увидел новость: при сборе раз в несколько минут это почти время публикации.
 */
#[ORM\Entity(repositoryClass: NewsItemRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_news_item_key', columns: ['source_id', 'external_key'])]
#[ORM\Index(name: 'idx_news_item_sort', columns: ['sort_at'])]
#[ORM\Index(name: 'idx_news_item_source_sort', columns: ['source_id', 'sort_at'])]
class NewsItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private NewsSource $source;

    /** sha1 от guid или адреса статьи: сами они бывают длиннее, чем удобно индексировать. */
    #[ORM\Column(length: 40)]
    private string $externalKey;

    #[ORM\Column(length: 1000)]
    private string $link;

    #[ORM\Column(length: 1000)]
    private string $title;

    /** Анонс без HTML; null — у издания его нет. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** Полный текст без HTML, если лента его отдаёт (РБК). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $content = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $sortAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(NewsSource $source, string $externalKey, \DateTimeImmutable $seenAt)
    {
        $this->source = $source;
        $this->externalKey = $externalKey;
        $this->firstSeenAt = $seenAt;
        $this->sortAt = $seenAt;
        $this->updatedAt = $seenAt;
    }

    /**
     * Записывает то, что пришло из ленты.
     *
     * @return bool изменилось ли что-то
     */
    public function apply(
        string $link,
        string $title,
        ?string $summary,
        ?string $content,
        ?\DateTimeImmutable $publishedAt,
        \DateTimeImmutable $now,
    ): bool {
        $unchanged = isset($this->title) && $this->link === $link && $this->title === $title
            && $this->summary === $summary && $this->content === $content && $this->publishedAt == $publishedAt;
        if ($unchanged) {
            return false;
        }
        $this->link = $link;
        $this->title = $title;
        $this->summary = $summary;
        $this->content = $content;
        $this->publishedAt = $publishedAt;
        $this->sortAt = $publishedAt ?? $this->firstSeenAt;
        $this->updatedAt = $now;

        return true;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): NewsSource
    {
        return $this->source;
    }

    public function getExternalKey(): string
    {
        return $this->externalKey;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    public function getPublishedAt(): ?\DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getSortAt(): \DateTimeImmutable
    {
        return $this->sortAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
