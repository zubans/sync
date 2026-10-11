<?php

namespace App\Service;

/**
 * Пишет текст сводки по готовому промпту. Реализация — Antigravity CLI ([AgyNewsSummarizer]);
 * в тестах подменяется заглушкой, чтобы не ходить в модель.
 */
interface NewsSummarizer
{
    /**
     * @return string ответ модели как есть — разбирает его [NewsDigestPrompt::parse]
     *
     * @throws \RuntimeException модель не ответила
     */
    public function complete(string $prompt, ?string $model): string;
}
