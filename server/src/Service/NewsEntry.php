<?php

namespace App\Service;

/** Новость, разобранная из ленты издания, — ещё не сохранённая. */
final readonly class NewsEntry
{
    public function __construct(
        /** Ключ новости в издании: RSS guid, без него — адрес статьи. */
        public string $key,
        public string $link,
        public string $title,
        public ?string $summary = null,
        public ?string $content = null,
        /** null — издание время не даёт (CNN). */
        public ?\DateTimeImmutable $publishedAt = null,
    ) {
    }
}
