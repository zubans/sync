<?php

namespace App\Service;

use App\Entity\MarketAnalysis;

/**
 * Промпт «что делать с инструментом» и разбор ответа. Модель получает всё одним сообщением: сведения
 * о бумаге, цены и индикаторы, покупки пользователя, новостные триггеры с фактической реакцией цены
 * и фон рынка, — и возвращает рекомендацию, прогноз и объяснения по-русски.
 */
final class MarketAnalysisPrompt
{
    private const MAX_LIST = 6;

    /**
     * @param array{secid: string, name: string, kind: string} $instrument
     * @param list<string>                                     $facts      строки «поле: значение» о бумаге
     * @param list<string>                                     $prices     строки о цене и индикаторах
     * @param list<string>                                     $position   строки о покупках; пусто — покупок нет
     * @param list<string>                                     $triggers   новости, связанные с инструментом, с прогнозом и фактом
     * @param list<string>                                     $mentions   прочие новости, где упоминается компания
     * @param list<string>                                     $background новости, влияющие на рынок целиком
     */
    public static function build(array $instrument, array $facts, array $prices, array $position, array $triggers, array $mentions, array $background): string
    {
        $block = static fn (array $lines, string $empty) => $lines === [] ? $empty : implode("\n", $lines);
        $facts = $block($facts, 'нет данных');
        $prices = $block($prices, 'нет данных');
        $hasPosition = $position !== [];
        $position = $block($position, 'Покупок нет — инвестор решает, входить ли.');
        $triggers = $block($triggers, 'нет');
        $mentions = $block($mentions, 'нет');
        $background = $block($background, 'нет');
        $positionTask = $hasPosition
            ? 'position — совет по позиции с учётом цены покупки и текущей доходности: держать, зафиксировать часть прибыли, усредниться, где поставить стоп; 2–3 предложения.'
            : 'position — пустая строка: покупок нет.';
        $holdRule = $hasPosition ? '' : ' Позиции нет, поэтому hold не используй: buy или wait.';

        return <<<PROMPT
            Ты аналитик российского фондового рынка. Дай взвешенный вывод по одному инструменту Московской биржи для частного инвестора.

            Инструмент: {$instrument['secid']} — {$instrument['name']} ({$instrument['kind']}).

            Сведения о бумаге:
            $facts

            Цена и технические индикаторы (дневные свечи, задержка 15 минут):
            $prices

            Позиция инвестора:
            $position

            Новостные триггеры — новости, связанные с инструментом: прогноз, сделанный при выходе новости, и как цена на самом деле пошла:
            $triggers

            Другие новости, где упоминается компания:
            $mentions

            Фон рынка — новости, влияющие на весь рынок:
            $background

            Задача:
            1. news — суммируй новостные триггеры: какие события главные, в какую сторону давят, отыгран ли уже их эффект (сравни прогноз с фактом), чего ждать дальше; 3–5 предложений.
            2. technical — что говорят тренд, средние, RSI, MACD, полосы Боллинджера, волатильность, уровни поддержки и сопротивления; 2–4 предложения.
            3. outlook — куда вероятнее пойдёт цена на горизонте horizon (например «1–3 месяца»): up, down или sideways; confidence — уверенность 0–100, без завышения: при противоречивых сигналах — 40–55.
            4. recommendation — buy (купить или докупить), hold (держать), sell (продать или сократить), wait (не входить, наблюдать).$holdRule
            5. urgency — act: сильный сигнал, действовать сейчас (резкое ухудшение новостей, пробой важного уровня, цель достигнута); watch — присмотреться; none — спокойно.
            6. $positionTask
            7. summary — итог в 2–3 предложениях: что делать и почему.
            8. risks — до 4 главных рисков; triggers — до 4 событий или уровней цены, при которых вывод стоит пересмотреть.

            Опирайся только на данные из этого сообщения и общеизвестные факты о компании и рынке; не выдумывай цифры и события.
            Всё нужное — в этом сообщении: не ищи в интернете и не вызывай инструменты, сразу дай ответ.
            Пиши по-русски, коротко и по делу; в текстах не пиши коды полей и значений (buy, wait, up) — только слова. Ответ — только JSON указанного вида.
            PROMPT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $text = ['type' => 'string'];
        $list = ['type' => 'array', 'items' => ['type' => 'string']];

        return [
            'type' => 'object',
            'properties' => [
                'recommendation' => ['type' => 'string', 'enum' => MarketAnalysis::RECOMMENDATIONS],
                'outlook' => ['type' => 'string', 'enum' => MarketAnalysis::OUTLOOKS],
                'confidence' => ['type' => 'integer'],
                'urgency' => ['type' => 'string', 'enum' => MarketAnalysis::URGENCIES],
                'horizon' => $text,
                'summary' => $text,
                'news' => $text,
                'technical' => $text,
                'position' => $text,
                'risks' => $list,
                'triggers' => $list,
            ],
            'required' => ['recommendation', 'outlook', 'confidence', 'urgency', 'horizon', 'summary', 'news', 'technical', 'position', 'risks', 'triggers'],
        ];
    }

    /**
     * Ответ модели, проверенный сервером: значения только из перечней, уверенность 0–100, списки строк
     * не длиннее [MAX_LIST]. Без позиции «держать» превращается в «наблюдать», совет по позиции — null.
     *
     * @return array{recommendation: string, outlook: string, confidence: int, urgency: string, horizon: string, summary: string,
     *               news: string, technical: string, position: ?string, risks: list<string>, triggers: list<string>}
     *
     * @throws \UnexpectedValueException ответ не разбирается
     */
    public static function parse(string $answer, bool $hasPosition): array
    {
        $start = strpos($answer, '{');
        $end = strrpos($answer, '}');
        $data = $start !== false && $end !== false ? json_decode(substr($answer, $start, $end - $start + 1), true) : null;
        if (!\is_array($data)) {
            throw new \UnexpectedValueException('Анализ инструмента: ответ модели — не JSON');
        }
        $text = static fn (string $key) => \is_scalar($data[$key] ?? null) ? trim((string) $data[$key]) : '';
        $list = static fn (string $key) => \is_array($data[$key] ?? null)
            ? \array_slice(array_values(array_filter(array_map(static fn ($v) => \is_scalar($v) ? trim((string) $v) : '', $data[$key]))), 0, self::MAX_LIST)
            : [];
        $recommendation = strtolower($text('recommendation'));
        $outlook = strtolower($text('outlook'));
        $urgency = strtolower($text('urgency'));
        if (!\in_array($recommendation, MarketAnalysis::RECOMMENDATIONS, true) || !\in_array($outlook, MarketAnalysis::OUTLOOKS, true)
            || $text('summary') === '') {
            throw new \UnexpectedValueException('Анализ инструмента: в ответе нет рекомендации, прогноза или итога: '.mb_substr($answer, 0, 200));
        }
        if (!$hasPosition && $recommendation === MarketAnalysis::HOLD) {
            $recommendation = MarketAnalysis::WAIT;
        }
        $position = $text('position');

        return [
            'recommendation' => $recommendation,
            'outlook' => $outlook,
            'confidence' => max(0, min(100, is_numeric($data['confidence'] ?? null) ? (int) round((float) $data['confidence']) : 50)),
            'urgency' => \in_array($urgency, MarketAnalysis::URGENCIES, true) ? $urgency : 'none',
            'horizon' => $text('horizon') ?: '1–3 месяца',
            'summary' => $text('summary'),
            'news' => $text('news'),
            'technical' => $text('technical'),
            'position' => $hasPosition && $position !== '' ? $position : null,
            'risks' => $list('risks'),
            'triggers' => $list('triggers'),
        ];
    }
}
