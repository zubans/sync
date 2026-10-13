<?php

namespace App\Service;

use App\Entity\NewsMarketImpact;

/** Промпт «какие инструменты Мосбиржи затронет новость» и разбор ответа. */
final class NewsMarketPrompt
{
    /** Не больше стольких инструментов на новость: дальше — уже не последствие, а общий фон. */
    public const MAX_PER_NEWS = 3;

    /**
     * @param list<string>          $news        строки «[id] издание, время — заголовок. Анонс»
     * @param array<string, string> $instruments код ISS → название
     */
    public static function build(array $news, array $instruments): string
    {
        $list = implode("\n", array_map(static fn (string $secid, string $name) => "$secid — $name", array_keys($instruments), $instruments));
        $lines = implode("\n", $news);
        $max = self::MAX_PER_NEWS;

        return <<<PROMPT
            Ты аналитик российского фондового рынка. Ниже новости разных изданий (на разных языках) и список инструментов Московской биржи.
            Для каждой новости реши, может ли она заметно сдвинуть цену инструментов из списка: события компании (отчётность, дивиденды, сделки,
            аварии, санкции против неё), решения ЦБ и правительства, цены на нефть, газ и металлы, курс рубля, санкции и геополитика,
            которые касаются российского рынка. Большинство новостей рынок не двигают — их пропусти.

            Для новости, которая влияет, дай до $max инструментов — самые прямо затронутые: сначала компании, потом отраслевой индекс,
            индекс МосБиржи — только если новость касается всего рынка. Для каждого:
            - secid — код строго из списка;
            - direction — куда может пойти цена: up (вырастет), down (упадёт), mixed (влияние есть, направление неясно);
            - reason — одно-два предложения по-русски: почему и куда может пойти цена, без воды и без оговорок про риски.
            Только то, что следует из новости и общеизвестных фактов о компаниях; не выдумывай цифры.

            Всё нужное — в этом сообщении: не ищи в интернете и не вызывай инструменты, сразу дай ответ.

            Ответ — только JSON: {"news": [{"id": 12, "impacts": [{"secid": "SBER", "direction": "down", "reason": "…"}]}]}
            Новости без влияния в ответ не включай.

            Инструменты:
            $list

            Новости:
            $lines
            PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'news' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'impacts' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'secid' => ['type' => 'string'],
                                        'direction' => ['type' => 'string', 'enum' => NewsMarketImpact::FORECASTS],
                                        'reason' => ['type' => 'string'],
                                    ],
                                    'required' => ['secid', 'direction', 'reason'],
                                ],
                            ],
                        ],
                        'required' => ['id', 'impacts'],
                    ],
                ],
            ],
            'required' => ['news'],
        ];
    }

    /**
     * Ответ модели, проверенный сервером: только новости из запроса и инструменты из списка, без повторов,
     * не больше [MAX_PER_NEWS] на новость; без объяснения или с неизвестным направлением — отбрасывается.
     *
     * @param list<int>    $ids     id новостей запроса
     * @param list<string> $secids  коды инструментов списка
     *
     * @return array<int, list<array{secid: string, direction: string, reason: string}>> по id новости
     *
     * @throws \UnexpectedValueException ответ не разбирается
     */
    public static function parse(string $answer, array $ids, array $secids): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (!\is_array($data) || !\is_array($data['news'] ?? null)) {
            throw new \UnexpectedValueException('Последствия для Мосбиржи: ответ модели — не JSON');
        }
        $ids = array_flip($ids);
        $secids = array_flip($secids);
        $result = [];
        foreach ($data['news'] as $news) {
            $id = \is_array($news) && is_numeric($news['id'] ?? null) ? (int) $news['id'] : null;
            if ($id === null || !isset($ids[$id]) || !\is_array($news['impacts'] ?? null)) {
                continue;
            }
            foreach ($news['impacts'] as $impact) {
                $secid = \is_array($impact) ? strtoupper(trim((string) ($impact['secid'] ?? ''))) : '';
                $direction = \is_array($impact) ? (string) ($impact['direction'] ?? '') : '';
                $reason = \is_array($impact) ? trim((string) ($impact['reason'] ?? '')) : '';
                $seen = array_column($result[$id] ?? [], 'secid');
                if (!isset($secids[$secid]) || !\in_array($direction, NewsMarketImpact::FORECASTS, true) || $reason === ''
                    || \in_array($secid, $seen, true) || \count($seen) >= self::MAX_PER_NEWS) {
                    continue;
                }
                $result[$id][] = ['secid' => $secid, 'direction' => $direction, 'reason' => $reason];
            }
        }

        return $result;
    }
}
