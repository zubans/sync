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
 *
 * Перевод новостей изданий на других языках ([NewsTranslator]) хранится рядом с оригиналом. Оригинал
 * не трогаем, перевод — производные данные: редакция поправила заголовок или анонс — перевод заголовка
 * сбрасывается, поменялся текст — перевод текста; эта часть переведётся заново с новыми попытками.
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

    /**
     * Когда полный текст скачан со страницы статьи; null — ещё нет (или он пришёл из ленты, как у РБК).
     * Попытки считаются, чтобы закрытый сайт (NYT отвечает 403) не запрашивался бесконечно.
     */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $contentFetchedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $contentAttempts = 0;

    /** Вектор заголовка с анонсом ([NewsVector]); null — ещё не посчитан. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $embedding = null;

    /** Сюжетная линия новости; null — ещё не разобрана или векторов нет. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?NewsStoryline $storyline = null;

    /** Заголовок по-русски; null — новость на русском или ещё не переведена. */
    #[ORM\Column(length: 1000, nullable: true)]
    private ?string $translatedTitle = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $translatedSummary = null;

    /** Полный текст по-русски, абзацы через пустую строку, — переводится, когда текст статьи скачан. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $translatedContent = null;

    /**
     * Отвергнутые ответы модели на перевод заголовка и на перевод текста — после трёх новость остаётся
     * без этого перевода. Счётчики раздельные: неудача одного шага не должна блокировать другой.
     * Недоступный agy попыткой не считается — новость ни в чём не виновата.
     */
    #[ORM\Column(options: ['default' => 0])]
    private int $headlineTranslationAttempts = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $contentTranslationAttempts = 0;

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
        // Полный текст со страницы статьи лента не затирает: в ней только анонс или ничего.
        if ($this->contentFetchedAt !== null) {
            $content = $this->content;
        }
        $unchanged = isset($this->title) && $this->link === $link && $this->title === $title
            && $this->summary === $summary && $this->content === $content && $this->publishedAt == $publishedAt;
        if ($unchanged) {
            return false;
        }
        if (isset($this->title) && ($this->title !== $title || $this->summary !== $summary)) {
            $this->resetHeadlineTranslation();
        }
        if ($this->content !== $content) {
            $this->resetContentTranslation();
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

    /** Полный текст со страницы статьи: абзацы через пустую строку. */
    public function setFetchedContent(string $content, \DateTimeImmutable $at): void
    {
        $this->content = $content;
        $this->contentFetchedAt = $at;
        ++$this->contentAttempts;
        $this->resetContentTranslation();
    }

    public function markContentAttempt(): void
    {
        ++$this->contentAttempts;
    }

    public function setHeadlineTranslation(string $title, ?string $summary): void
    {
        $this->translatedTitle = mb_substr($title, 0, 1000);
        $this->translatedSummary = $summary;
    }

    /** @param list<string> $paragraphs */
    public function setContentTranslation(array $paragraphs): void
    {
        $this->translatedContent = implode("\n\n", $paragraphs);
    }

    public function markHeadlineTranslationAttempt(): void
    {
        ++$this->headlineTranslationAttempts;
    }

    public function markContentTranslationAttempt(): void
    {
        ++$this->contentTranslationAttempts;
    }

    private function resetHeadlineTranslation(): void
    {
        $this->translatedTitle = null;
        $this->translatedSummary = null;
        $this->headlineTranslationAttempts = 0;
    }

    private function resetContentTranslation(): void
    {
        $this->translatedContent = null;
        $this->contentTranslationAttempts = 0;
    }

    /**
     * Текст абзацами: в базе абзацы разделены пустой строкой.
     *
     * @return list<string>
     */
    public static function paragraphs(?string $text): array
    {
        $text = trim((string) $text);

        return $text === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $text) ?: [])));
    }

    public function getTranslatedTitle(): ?string
    {
        return $this->translatedTitle;
    }

    public function getTranslatedSummary(): ?string
    {
        return $this->translatedSummary;
    }

    public function getTranslatedContent(): ?string
    {
        return $this->translatedContent;
    }

    public function getHeadlineTranslationAttempts(): int
    {
        return $this->headlineTranslationAttempts;
    }

    public function getContentTranslationAttempts(): int
    {
        return $this->contentTranslationAttempts;
    }

    /** Есть перевод заголовка или текста — у новости есть оригинал, который можно показать вместо перевода. */
    public function isTranslated(): bool
    {
        return $this->translatedTitle !== null || $this->translatedContent !== null;
    }

    /** @param list<float> $vector */
    public function setEmbedding(array $vector): void
    {
        $this->embedding = \App\Service\NewsVector::encode($vector);
    }

    /** @return list<float>|null */
    public function getEmbedding(): ?array
    {
        return $this->embedding === null ? null : \App\Service\NewsVector::decode($this->embedding);
    }

    public function getStoryline(): ?NewsStoryline
    {
        return $this->storyline;
    }

    public function setStoryline(?NewsStoryline $storyline): void
    {
        $this->storyline = $storyline;
    }

    public function getContentFetchedAt(): ?\DateTimeImmutable
    {
        return $this->contentFetchedAt;
    }

    public function getContentAttempts(): int
    {
        return $this->contentAttempts;
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
