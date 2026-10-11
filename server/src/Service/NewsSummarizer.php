<?php

namespace App\Service;

/**
 * Пишет текст сводки по готовому промпту. Реализация — Antigravity CLI ([AgyNewsSummarizer]);
 * в тестах подменяется заглушкой, чтобы не ходить в модель.
 */
interface NewsSummarizer
{
    /**
     * @param string|null               $effort глубина размышлений модели: low, medium, high
     * @param array<string, mixed>|null $schema JSON-схема ответа, если модель умеет её соблюдать
     *
     * @return string ответ модели как есть — разбирает его [NewsDigestPrompt::parse]
     *
     * @throws \RuntimeException модель не ответила
     */
    public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string;
}
