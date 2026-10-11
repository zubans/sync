<?php

namespace App\Entity;

use App\Repository\NewsSourceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Издание, чьи новости собирает сервер. Лента читается раз в несколько минут (`app:news:collect`);
 * состояние последнего сбора хранится здесь же — по нему виджет помечает издание, которое не отвечает.
 * ETag и Last-Modified ленты запоминаются: неизменившуюся ленту издание отдаёт ответом 304 без тела.
 */
#[ORM\Entity(repositoryClass: NewsSourceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_news_source_code', columns: ['code'])]
class NewsSource
{
    /** RSS 2.0. */
    public const FORMAT_RSS = 'rss';
    /** Текстовая версия CNN (lite.cnn.com): RSS CNN не обновляется с 2023 года. */
    public const FORMAT_CNN_LITE = 'cnn_lite';

    public const FORMATS = [self::FORMAT_RSS, self::FORMAT_CNN_LITE];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Код в API: rg, rbc, cnn… */
    #[ORM\Column(length: 32)]
    private string $code;

    /** Подпись во вкладке виджета: «РБК», «NY Post». */
    #[ORM\Column(length: 100)]
    private string $title;

    #[ORM\Column(length: 500)]
    private string $url;

    #[ORM\Column(length: 20)]
    private string $format = self::FORMAT_RSS;

    #[ORM\Column]
    private bool $enabled = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSuccessAt = null;

    /** Ошибка последнего сбора; null — последний сбор удался. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $etag = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastModified = null;

    public function __construct(string $code, string $title, string $url, string $format = self::FORMAT_RSS)
    {
        $this->code = $code;
        $this->title = $title;
        $this->url = $url;
        $this->format = $format;
    }

    public function __toString(): string
    {
        return $this->title;
    }

    /** Сбор удался: ошибка стирается, кэш-заголовки ленты запоминаются для следующего запроса. */
    public function markSuccess(\DateTimeImmutable $at, ?string $etag, ?string $lastModified): void
    {
        $this->lastAttemptAt = $at;
        $this->lastSuccessAt = $at;
        $this->lastError = null;
        $this->etag = $etag;
        $this->lastModified = $lastModified;
    }

    public function markFailure(\DateTimeImmutable $at, string $error): void
    {
        $this->lastAttemptAt = $at;
        $this->lastError = mb_substr($error, 0, 1000);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(string $url): static
    {
        $this->url = $url;
        // Другая лента — прежние кэш-заголовки к ней не относятся.
        $this->etag = null;
        $this->lastModified = null;

        return $this;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): static
    {
        $this->format = $format;

        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function getLastSuccessAt(): ?\DateTimeImmutable
    {
        return $this->lastSuccessAt;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getEtag(): ?string
    {
        return $this->etag;
    }

    public function getLastModified(): ?string
    {
        return $this->lastModified;
    }
}
