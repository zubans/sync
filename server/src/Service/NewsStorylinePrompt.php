<?php

namespace App\Service;

/** Промпт названий сюжетных линий и разбор ответа. */
final class NewsStorylinePrompt
{
    /**
     * @param list<string>             $blocks линии: «[id] новостей N» и заголовки новостей построчно
     * @param list<array{int, int}>    $pairs  пары линий с близкими векторами — может быть, одна история
     */
    public static function build(array $blocks, array $pairs = []): string
    {
        $lines = implode("\n\n", $blocks);
        $merge = $pairs === [] ? '' : "\n\nПары линий, которые, возможно, об одном: "
            .implode(', ', array_map(static fn (array $p) => "{$p[0]} и {$p[1]}", $pairs)).'.'
            .' В merge верни пару, только если это одна и та же история — одно событие и его продолжение, а не просто общая тема'
            .' (удар по аэропорту Эр-Рияда на русском и на английском — одна история; разные удары по Украине — нет).';

        return <<<PROMPT
            Ниже сюжетные линии новостей: у каждой — id и заголовки её новостей из разных изданий, свежие первыми.
            Для каждой линии дай:
            - title — название истории по-русски до 80 символов: суть, а не последнее событие, без дат и без кавычек вокруг;
            - summary — о чём история, одно предложение по-русски.
            Новости на других языках переведи. Только факты из заголовков.$merge

            Всё нужное — в этом сообщении: не ищи в интернете и не вызывай инструменты, сразу дай ответ.

            Ответ — только JSON: {"lines": [{"id": 12, "title": "…", "summary": "…"}], "merge": [[12, 15]]}

            Линии:
            $lines
            PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'lines' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'title', 'summary'],
                    ],
                ],
                'merge' => [
                    'type' => 'array',
                    'items' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ],
            ],
            'required' => ['lines'],
        ];
    }

    /**
     * @return array{names: array<int, array{title: string, summary: ?string}>, merge: list<array{int, int}>}
     *               названия по id линии и пары линий, которые модель считает одной историей
     *
     * @throws \UnexpectedValueException ответ не разбирается
     */
    public static function parse(string $answer): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (!\is_array($data) || !\is_array($data['lines'] ?? null)) {
            throw new \UnexpectedValueException('Названия сюжетов: ответ модели — не JSON');
        }
        $names = [];
        foreach ($data['lines'] as $line) {
            $title = \is_array($line) ? trim((string) ($line['title'] ?? ''), " \t\n\"«»") : '';
            if ($title === '' || !isset($line['id'])) {
                continue;
            }
            $summary = trim((string) ($line['summary'] ?? ''));
            $names[(int) $line['id']] = ['title' => $title, 'summary' => $summary !== '' ? $summary : null];
        }

        $merge = [];
        foreach (\is_array($data['merge'] ?? null) ? $data['merge'] : [] as $pair) {
            if (\is_array($pair) && \count($pair) === 2 && is_numeric($pair[0] ?? null) && is_numeric($pair[1] ?? null) && (int) $pair[0] !== (int) $pair[1]) {
                $merge[] = [(int) $pair[0], (int) $pair[1]];
            }
        }

        return ['names' => $names, 'merge' => $merge];
    }
}
