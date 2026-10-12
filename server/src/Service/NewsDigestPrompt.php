<?php

namespace App\Service;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;

/**
 * Промпт сводки и разбор ответа. Строгого формата ответа у agy нет, поэтому модель просим
 * вернуть JSON, а ответ проверяем сами: сюжеты без заголовка выбрасываются, ссылки на новости,
 * которых модели не давали, — тоже.
 */
final class NewsDigestPrompt
{
    /** Время в тексте сводки — московское: им живут и читатель, и планшет. */
    public const TIMEZONE = 'Europe/Moscow';

    private const STORIES = [NewsDigest::PERIOD_HOUR => 7, NewsDigest::PERIOD_DAY => 12];
    /** Анонсы режутся: за сутки новостей около тысячи, весь текст модели не нужен. */
    private const SUMMARY_CHARS = [NewsDigest::PERIOD_HOUR => 300, NewsDigest::PERIOD_DAY => 160];

    /**
     * Схема ответа для agy (`--json-schema`): заголовок сводки и сюжеты с id исходных новостей.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'headline' => ['type' => 'string'],
                'stories' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'text' => ['type' => 'string'],
                            'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        ],
                        'required' => ['title', 'text', 'items'],
                    ],
                ],
                'article' => [
                    'type' => 'object',
                    'properties' => ['title' => ['type' => 'string'], 'text' => ['type' => 'string']],
                    'required' => ['title', 'text'],
                ],
            ],
            'required' => ['headline', 'stories', 'article'],
        ];
    }

    /** @param list<NewsItem> $items */
    public static function build(NewsDigestPeriod $period, array $items): string
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $lines = [];
        foreach ($items as $item) {
            $summary = mb_substr((string) $item->getSummary(), 0, self::SUMMARY_CHARS[$period->period]);
            $lines[] = sprintf(
                '[%d] %s, %s — %s%s',
                $item->getId(),
                $item->getSource()->getTitle(),
                EventTime::fromDb($item->getSortAt())->setTimezone($tz)->format('d.m H:i'),
                $item->getTitle(),
                $summary !== '' ? '. '.$summary : '',
            );
        }
        $range = $period->start->setTimezone($tz)->format('d.m H:i').'–'.$period->end->setTimezone($tz)->format('d.m H:i');
        $span = $period->period === NewsDigest::PERIOD_HOUR ? 'час' : 'сутки';
        $max = self::STORIES[$period->period];
        $paragraphs = $period->period === NewsDigest::PERIOD_HOUR ? '3–5' : '5–10';
        $news = implode("\n", $lines);

        return <<<PROMPT
            Ты — редактор новостной сводки. Ниже новости за $span ($range, время московское) из нескольких изданий.
            Каждая строка: [id] издание, время — заголовок. Анонс.

            Составь краткую сводку на русском языке:
            - новости об одном событии из разных изданий объедини в один сюжет;
            - сюжеты по важности, самые значимые первыми, не больше $max;
            - рекламу, промокоды, ставки и мелочи пропусти;
            - у сюжета заголовок до 80 символов и суть в 1–3 предложениях, без оценок; английские новости переведи;
            - у сюжета перечисли id всех новостей, на которых он основан;
            - не добавляй фактов, которых нет в новостях.

            Кроме сюжетов, напиши по тем же новостям одну связную статью — обзор за $span:
            - общий заголовок до 100 символов;
            - $paragraphs абзацев, самое важное первым, с переходами между темами, без списков и без markdown;
            - абзацы раздели пустой строкой;
            - те же правила: только факты из новостей, без оценок, английское переведи.

            Всё нужное — в этом сообщении: не ищи в интернете, не открывай ссылки и не вызывай инструменты, сразу дай ответ.

            Ответ — только JSON, без пояснений и без markdown:
            {"headline": "главное за период одной фразой", "stories": [{"title": "…", "text": "…", "items": [12, 15]}], "article": {"title": "…", "text": "абзац\n\nабзац"}}

            Новости:
            $news
            PROMPT;
    }

    /**
     * @param list<int> $knownIds id новостей, которые были в промпте
     *
     * @return array{headline: string, stories: list<array{title: string, text: string, items: list<int>}>, article: ?array{title: string, text: string}}
     *
     * @throws \UnexpectedValueException ответ не разбирается
     */
    public static function parse(string $answer, array $knownIds): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Ответ модели — не JSON: «'.self::snippet($answer).'»');
        }
        $known = array_flip($knownIds);
        $stories = [];
        foreach (\is_array($data['stories'] ?? null) ? $data['stories'] : [] as $story) {
            $title = \is_array($story) ? trim((string) ($story['title'] ?? '')) : '';
            if ($title === '') {
                continue;
            }
            $ids = array_values(array_unique(array_filter(
                array_map('intval', \is_array($story['items'] ?? null) ? $story['items'] : []),
                static fn (int $id) => isset($known[$id]),
            )));
            $stories[] = ['title' => $title, 'text' => trim((string) ($story['text'] ?? '')), 'items' => $ids];
        }
        $headline = trim((string) ($data['headline'] ?? ''));
        if ($stories === []) {
            throw new \UnexpectedValueException('В ответе модели нет сюжетов: «'.self::snippet($answer).'»');
        }

        // Статья — второй формат той же сводки; без неё сводка остаётся списком сюжетов.
        $article = \is_array($data['article'] ?? null) ? $data['article'] : [];
        $articleTitle = trim((string) ($article['title'] ?? ''));
        $articleText = trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', (string) ($article['text'] ?? ''))));

        return [
            'headline' => $headline !== '' ? $headline : $stories[0]['title'],
            'stories' => $stories,
            'article' => $articleTitle !== '' && $articleText !== '' ? ['title' => $articleTitle, 'text' => $articleText] : null,
        ];
    }

    /** Начало ответа для ошибки — по нему видно, что модель написала вместо JSON. */
    private static function snippet(string $answer): string
    {
        $answer = trim((string) preg_replace('/\s+/u', ' ', $answer));

        return $answer === '' ? 'пусто' : mb_substr($answer, 0, 300).(mb_strlen($answer) > 300 ? '…' : '');
    }
}
