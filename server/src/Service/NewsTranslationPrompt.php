<?php

namespace App\Service;

/**
 * Промпты перевода новостей на русский и разбор ответов: заголовки с анонсами — пачкой,
 * полный текст — по статье (длинная статья — частями). Ответ модели проверяется строго:
 * сокращённый пересказ вместо перевода хуже, чем оригинал, поэтому такой ответ отбрасывается.
 */
final class NewsTranslationPrompt
{
    /** Расхождение числа абзацев с оригиналом больше этой доли — модель пересказала или сократила текст. */
    public const PARAGRAPH_TOLERANCE = 0.2;

    private const LANGUAGES = ['en' => 'английского', 'fr' => 'французского', 'uk' => 'украинского', 'ja' => 'японского', 'zh' => 'китайского', 'pt' => 'португальского', 'fa' => 'персидского', 'ar' => 'арабского'];

    private const RULES = <<<RULES
        Переводи точно и полностью: без пересказа, сокращений, пояснений и оценок. Имена, названия
        организаций и мест — как принято в русских СМИ. Стиль — русской новостной ленты.

        Всё нужное — в этом сообщении: не ищи в интернете и не вызывай инструменты, сразу дай ответ.
        RULES;

    /**
     * @param list<array{id: int, language: string, title: string, summary: ?string}> $items
     */
    public static function buildHeadlines(array $items): string
    {
        $lines = implode("\n", array_map(static function (array $item): string {
            $summary = trim((string) $item['summary']);

            return "[{$item['id']}] {$item['language']} — ".trim($item['title']).($summary !== '' ? '. '.$summary : '');
        }, $items));
        $rules = self::RULES;

        return <<<PROMPT
            Переведи на русский заголовки и анонсы новостей. Строка новости: [id] язык — заголовок. Анонс.
            Для каждой новости дай title — перевод заголовка и summary — перевод анонса; анонса нет — summary пустой.
            $rules

            Ответ — только JSON: {"items": [{"id": 12, "title": "…", "summary": "…"}]}

            Новости:
            $lines
            PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schemaHeadlines(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title'],
                    ],
                ],
            ],
            'required' => ['items'],
        ];
    }

    /**
     * @param list<int> $ids новости запроса: чужие id модели не принимаются
     *
     * @return array<int, array{title: string, summary: ?string}> переводы по id новости
     *
     * @throws \UnexpectedValueException ответ не разбирается
     */
    public static function parseHeadlines(string $answer, array $ids): array
    {
        $data = self::json($answer);
        if (!\is_array($data['items'] ?? null)) {
            throw new \UnexpectedValueException('Перевод заголовков: ответ модели — не JSON');
        }
        $allowed = array_flip($ids);
        $result = [];
        foreach ($data['items'] as $row) {
            if (!\is_array($row) || !is_numeric($row['id'] ?? null) || !isset($allowed[(int) $row['id']])) {
                continue;
            }
            $title = trim((string) ($row['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $summary = trim((string) ($row['summary'] ?? ''));
            $result[(int) $row['id']] = ['title' => $title, 'summary' => $summary !== '' ? $summary : null];
        }

        return $result;
    }

    /**
     * @param string|null  $title      заголовок — только у первой части статьи
     * @param list<string> $paragraphs
     */
    public static function buildArticle(string $language, ?string $title, array $paragraphs): string
    {
        $from = self::languageName($language);
        $text = implode("\n\n", array_map(static fn (int $i, string $p) => '['.($i + 1)."] $p", array_keys($paragraphs), $paragraphs));
        $count = \count($paragraphs);
        $head = $title !== null ? "Заголовок: $title\n\n" : '';
        $task = $title !== null
            ? 'Переведи на русский статью'.($from !== null ? " с $from" : '').': заголовок и все абзацы.'
            : 'Переведи на русский продолжение статьи'.($from !== null ? " с $from" : '').': все абзацы.';
        $rules = self::RULES;

        return <<<PROMPT
            $task Абзацы пронумерованы, их $count — в ответе тоже $count абзацев в том же порядке, без номеров;
            абзацы не объединяй и не пропускай.
            $rules

            Ответ — только JSON: {"title": "…", "paragraphs": ["…", "…"]}

            $head$text
            PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schemaArticle(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['paragraphs'],
        ];
    }

    /**
     * @param int $expected сколько абзацев в оригинале
     *
     * @return array{title: ?string, paragraphs: list<string>}
     *
     * @throws \UnexpectedValueException ответ не разбирается или абзацев заметно больше или меньше, чем в оригинале
     */
    public static function parseArticle(string $answer, int $expected): array
    {
        $data = self::json($answer);
        if (!\is_array($data['paragraphs'] ?? null)) {
            throw new \UnexpectedValueException('Перевод статьи: ответ модели — не JSON');
        }
        $raw = array_values(array_filter($data['paragraphs'], 'is_string'));
        $paragraphs = self::cleanParagraphs($raw);
        // Claude и другие модели agy иногда складывают все абзацы в один элемент — через пустые строки
        // или с номерами «[2] …» из промпта. Мало абзацев — режем элементы сначала по пустым строкам, потом по номерам.
        if (\count($paragraphs) < $expected) {
            $paragraphs = self::cleanParagraphs(array_merge(...array_map(static fn (string $p) => preg_split('/\n\s*\n/u', $p) ?: [$p], $raw)));
        }
        if (\count($paragraphs) < $expected) {
            $paragraphs = self::cleanParagraphs(array_merge(...array_map(static fn (string $p) => preg_split('/(?:^|\s)\[\d+\]\s+/u', $p) ?: [$p], $raw)));
        }
        if (abs(\count($paragraphs) - $expected) > (int) floor($expected * self::PARAGRAPH_TOLERANCE)) {
            throw new \UnexpectedValueException(sprintf('Перевод статьи: абзацев %d вместо %d — похоже на пересказ', \count($paragraphs), $expected));
        }
        $title = trim((string) ($data['title'] ?? ''));

        return ['title' => $title !== '' ? $title : null, 'paragraphs' => $paragraphs];
    }

    /**
     * Абзацы без пустых и без номеров «[3]» в начале.
     *
     * @param list<string> $raw
     *
     * @return list<string>
     */
    private static function cleanParagraphs(array $raw): array
    {
        $paragraphs = [];
        foreach ($raw as $paragraph) {
            $paragraph = trim(preg_replace('/^\[\d+\]\s*/u', '', trim($paragraph)) ?? $paragraph);
            if ($paragraph !== '') {
                $paragraphs[] = $paragraph;
            }
        }

        return $paragraphs;
    }

    /** «английского» для «перевод с английского»; null — язык не из известных. */
    public static function languageName(string $language): ?string
    {
        return self::LANGUAGES[$language] ?? null;
    }

    /** @return array<mixed>|null */
    private static function json(string $answer): ?array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;

        return \is_array($data) ? $data : null;
    }
}
