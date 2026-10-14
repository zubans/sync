<?php

namespace App\Service;

use App\Entity\MarketAnalysis;
use App\Entity\MarketInstrument;
use App\Entity\MarketLot;
use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use App\Repository\MarketAnalysisRepository;
use App\Repository\MarketInstrumentRepository;
use App\Repository\MarketLotRepository;
use App\Repository\NewsItemRepository;
use App\Repository\NewsMarketImpactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Анализ инструментов из виджета Мосбиржи: по каждому инструменту пользователя модель (agy) получает
 * новостные триггеры (последствия новостей с фактической реакцией цены, [NewsMarketService]), упоминания
 * компании в новостях, фон рынка, технические индикаторы по дневным свечам ISS ([MarketIndicators]),
 * сведения о бумаге и покупки пользователя — и советует: докупить, держать, продать или пока не входить.
 *
 * Анализ пересчитывается, когда:
 * - его ещё нет или пользователь попросил (кнопка на планшете, новая покупка);
 * - у инструмента появились новые последствия новостей;
 * - он старше [TTL_HOURS].
 * За проход — не больше [PER_RUN] инструментов: квота подписки agy общая со сводками и переводом.
 */
final class MarketAnalysisService
{
    public const TTL_HOURS = 6;
    public const PER_RUN = 3;
    /** После свежей новости анализ пересчитывается не чаще этого: новости о компании идут пачками. */
    private const NEWS_COOLDOWN_MINUTES = 30;
    /** Новостные триггеры — за две недели, упоминания и фон рынка — короче: им важна свежесть. */
    private const TRIGGER_DAYS = 14;
    private const TRIGGER_LIMIT = 40;
    private const MENTION_DAYS = 7;
    private const MENTION_LIMIT = 25;
    private const BACKGROUND_DAYS = 3;
    private const BACKGROUND_LIMIT = 12;
    /** Свечи — с запасом на 200-дневную среднюю и годовые изменения (выходные и праздники). */
    public const HISTORY_DAYS = 400;
    /** Слова названия короче этого не ищутся в заголовках: «ао», «ап» и т. п. совпадают с чем угодно. */
    private const MIN_WORD = 4;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketInstrumentRepository $instruments,
        private readonly MarketAnalysisRepository $analyses,
        private readonly MarketLotRepository $lots,
        private readonly NewsMarketImpactRepository $impacts,
        private readonly NewsItemRepository $items,
        private readonly NewsSummarizer $summarizer,
        private readonly MoexIss $iss,
        #[Autowire('%env(MARKET_ANALYSIS_MODEL)%')]
        private readonly string $model = '',
    ) {
    }

    /** @return array{analyzed: int, failed: int, error: ?string} */
    public function run(\DateTimeImmutable $now, int $limit = self::PER_RUN): array
    {
        $report = ['analyzed' => 0, 'failed' => 0, 'error' => null];
        foreach ($this->due($now, $limit) as $instrument) {
            $analysis = $this->analyses->findOrCreate($instrument->getUser(), $instrument->getSecid());
            try {
                $this->analyze($instrument, $analysis, $now);
                ++$report['analyzed'];
            } catch (\Throwable $e) {
                $analysis->fail($e->getMessage(), $now);
                ++$report['failed'];
                $report['error'] ??= $instrument->getSecid().': '.$e->getMessage();
            }
            $this->em->flush();
        }

        return $report;
    }

    /**
     * Инструменты, которым пора пересчитать анализ, по очереди: запрошенные, ещё без анализа,
     * с новыми новостями, устаревшие; внутри — самые давние первыми.
     *
     * @return list<MarketInstrument>
     */
    public function due(\DateTimeImmutable $now, int $limit): array
    {
        $stale = $now->modify('-'.self::TTL_HOURS.' hours');
        $cooldown = $now->modify('-'.self::NEWS_COOLDOWN_MINUTES.' minutes');
        $analyses = [];
        foreach ($this->analyses->findAll() as $analysis) {
            $analyses[$analysis->getUser()->getId()][$analysis->getSecid()] = $analysis;
        }
        $lastNews = [];
        $queue = [];
        foreach ($this->instruments->findAllWithUsers() as $instrument) {
            $analysis = $analyses[$instrument->getUser()->getId()][$instrument->getSecid()] ?? null;
            $at = $analysis?->getAnalyzedAt() !== null ? EventTime::fromDb($analysis->getAnalyzedAt()) : null;
            $secid = $instrument->getSecid();
            $priority = match (true) {
                $analysis?->getRequestedAt() !== null => 0,
                $at === null => 1,
                $at < $cooldown && ($lastNews[$secid] ??= $this->impacts->lastCreatedAt($secid)) !== null && $lastNews[$secid] > $at => 2,
                $at < $stale => 3,
                default => null,
            };
            if ($priority !== null) {
                $queue[] = [$priority, $at?->getTimestamp() ?? 0, $instrument];
            }
        }
        usort($queue, static fn (array $a, array $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $q) => $q[2], \array_slice($queue, 0, $limit));
    }

    /**
     * Один анализ: данные с биржи, новости, покупки → модель → запись.
     *
     * @throws \RuntimeException ISS или модель не ответили
     * @throws \UnexpectedValueException ответ модели не разобрался
     */
    public function analyze(MarketInstrument $instrument, MarketAnalysis $analysis, \DateTimeImmutable $now): void
    {
        $market = $this->snapshot($instrument, $now);
        if ($market['indicators'] === []) {
            throw new \RuntimeException('ISS не отдала дневных свечей');
        }
        $price = $market['indicators']['close'];
        $lots = $this->lots->findByUser($instrument->getUser(), $instrument->getSecid());
        $news = $this->relatedNews($instrument, $now);
        $background = $instrument->getSecid() === 'IMOEX' ? [] : $this->impacts->findForSecid('IMOEX', $now->modify('-'.self::BACKGROUND_DAYS.' days'), self::BACKGROUND_LIMIT);

        $prompt = MarketAnalysisPrompt::build(
            ['secid' => $instrument->getSecid(), 'name' => $instrument->getName(), 'kind' => self::kind($instrument->getMarket())],
            self::factLines($market['description'], $market['dividends']),
            self::priceLines($market['indicators']),
            self::positionLines($lots, $price, $now),
            array_map(static fn (array $t) => self::newsLine($t['item']).self::impactText($t['impacts']), $news['triggers']),
            array_map(static fn (NewsItem $i) => self::newsLine($i, withSummary: true), $news['mentions']),
            array_map(static fn (NewsMarketImpact $m) => self::newsLine($m->getItem()).self::impactText([$m]), $background),
        );
        $model = $this->model !== '' ? $this->model : null;
        $answer = $this->summarizer->complete($prompt, $model, $model === null ? 'medium' : null, MarketAnalysisPrompt::schema());
        $result = MarketAnalysisPrompt::parse($answer, $lots !== []);
        $analysis->complete($result, $price, \count($news['triggers']) + \count($news['mentions']) + \count($background), $model, $now);
    }

    /**
     * Данные биржи по инструменту: дневные свечи за [HISTORY_DAYS], индикаторы по ним, сведения о бумаге
     * и дивиденды (только у акций). Сведения и дивиденды не обязательны — их сбой не мешает остальному.
     *
     * @return array{candles: list<array{date: string, open: float, high: float, low: float, close: float, value: float}>,
     *               indicators: array<string, mixed>, description: array<string, array{title: string, value: string}>,
     *               dividends: list<array{date: string, value: float, currency: string}>}
     *
     * @throws \RuntimeException ISS не отдала свечи
     */
    public function snapshot(MarketInstrument $instrument, \DateTimeImmutable $now): array
    {
        $candles = $this->iss->dailyCandles($instrument->getSecid(), $instrument->getEngine(), $instrument->getMarket(), $now->modify('-'.self::HISTORY_DAYS.' days'), $now);
        try {
            $description = $this->iss->description($instrument->getSecid());
        } catch (\RuntimeException) {
            $description = [];
        }
        try {
            $dividends = $instrument->getMarket() === 'shares' ? $this->iss->dividends($instrument->getSecid()) : [];
        } catch (\RuntimeException) {
            $dividends = [];
        }

        return ['candles' => $candles, 'indicators' => MarketIndicators::compute($candles), 'description' => $description, 'dividends' => $dividends];
    }

    /**
     * Новости инструмента: триггеры — новости, для которых модель уже определила последствие для этой бумаги
     * (с прогнозом и фактом), и упоминания — прочие свежие новости с названием компании в заголовке
     * (только у акций: облигации, индексы и валюты по названию не ищутся).
     *
     * @return array{triggers: list<array{item: NewsItem, impacts: list<NewsMarketImpact>}>, mentions: list<NewsItem>}
     */
    public function relatedNews(MarketInstrument $instrument, \DateTimeImmutable $now): array
    {
        $triggers = [];
        foreach ($this->impacts->findForSecid($instrument->getSecid(), $now->modify('-'.self::TRIGGER_DAYS.' days'), self::TRIGGER_LIMIT) as $impact) {
            $id = (int) $impact->getItem()->getId();
            $triggers[$id] ??= ['item' => $impact->getItem(), 'impacts' => []];
            $triggers[$id]['impacts'][] = $impact;
        }
        $mentions = [];
        if ($instrument->getMarket() === 'shares') {
            foreach ($this->items->findMentioning(self::searchWords($instrument->getName()), $now->modify('-'.self::MENTION_DAYS.' days'), self::MENTION_LIMIT) as $item) {
                if (!isset($triggers[$item->getId()])) {
                    $mentions[] = $item;
                }
            }
        }

        return ['triggers' => array_values($triggers), 'mentions' => $mentions];
    }

    /**
     * Итог по покупкам при текущей цене: стоимость каждой покупки растёт вместе с ценой от цены покупки,
     * поэтому у облигаций (цена в процентах) и с комиссией в сумме доходность тоже верная.
     *
     * @param list<MarketLot> $lots
     *
     * @return array{quantity: float, amount: float, averagePrice: float, value: ?float, profit: ?float, profitPercent: ?float, firstBoughtOn: string}|null
     */
    public static function position(array $lots, ?float $price): ?array
    {
        if ($lots === []) {
            return null;
        }
        [$quantity, $amount, $cost, $value] = [0.0, 0.0, 0.0, 0.0];
        foreach ($lots as $lot) {
            $quantity += $lot->getQuantity();
            $amount += $lot->getAmount();
            $cost += $lot->getQuantity() * $lot->getPrice();
            $value += $price !== null && $lot->getPrice() > 0 ? $lot->getAmount() * $price / $lot->getPrice() : 0.0;
        }
        $value = $price !== null ? round($value, 2) : null;

        return [
            'quantity' => $quantity,
            'amount' => round($amount, 2),
            'averagePrice' => $quantity > 0 ? $cost / $quantity : 0.0,
            'value' => $value,
            'profit' => $value !== null ? round($value - $amount, 2) : null,
            'profitPercent' => $value !== null && $amount > 0 ? round(($value / $amount - 1) * 100, 2) : null,
            'firstBoughtOn' => $lots[0]->getBoughtOn()->format('Y-m-d'),
        ];
    }

    /**
     * Слова названия для поиска в заголовках, в нижнем регистре: «ЛУКОЙЛ» → «лукойл», «Сбербанк ап» → «сбербанк».
     *
     * @return list<string>
     */
    public static function searchWords(string $name): array
    {
        $words = preg_split('/[\s"«»().,\-]+/u', mb_strtolower($name), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $words = array_filter($words, static fn (string $w) => mb_strlen($w) >= self::MIN_WORD && !\in_array($w, ['пао', 'оао', 'зао', 'группа', 'компания'], true));

        return \array_slice(array_values(array_unique($words)), 0, 2);
    }

    private static function kind(string $market): string
    {
        return match ($market) {
            'shares' => 'акция',
            'bonds' => 'облигация',
            'index' => 'индекс',
            'selt' => 'валюта',
            default => $market,
        };
    }

    /**
     * @param array<string, array{title: string, value: string}>     $description
     * @param list<array{date: string, value: float, currency: string}> $dividends
     *
     * @return list<string>
     */
    private static function factLines(array $description, array $dividends): array
    {
        $lines = [];
        foreach ($description as $field) {
            $lines[] = $field['title'].': '.mb_substr($field['value'], 0, 200);
        }
        $lines = \array_slice($lines, 0, 30);
        if ($dividends !== []) {
            $lines[] = 'Дивиденды (дата закрытия реестра — на акцию): '.implode('; ', array_map(
                static fn (array $d) => $d['date'].' — '.self::num($d['value']).' '.$d['currency'],
                \array_slice($dividends, -6),
            ));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $ind [MarketIndicators::compute]
     *
     * @return list<string>
     */
    private static function priceLines(array $ind): array
    {
        $pct = static fn (?float $v) => $v === null ? '—' : ($v > 0 ? '+' : '').self::num($v).'%';
        $r = $ind['returns'];
        $lines = [
            sprintf('Последняя цена: %s (%s)', self::num($ind['close']), $ind['date']),
            sprintf('Изменение: неделя %s, месяц %s, квартал %s, полгода %s, год %s',
                $pct($r['week']), $pct($r['month']), $pct($r['quarter']), $pct($r['halfYear']), $pct($r['year'])),
        ];
        foreach ([20, 50, 200] as $p) {
            if ($ind["sma$p"] !== null) {
                $lines[] = sprintf('SMA%d: %s (цена %s средней на %s%%)', $p, self::num($ind["sma$p"]), $ind["vsSma$p"] >= 0 ? 'выше' : 'ниже', self::num(abs((float) $ind["vsSma$p"])));
            }
        }
        if ($ind['trend'] !== null) {
            $lines[] = 'Тренд по средним: '.['up' => 'восходящий', 'down' => 'нисходящий', 'sideways' => 'боковой'][$ind['trend']];
        }
        if ($ind['smaCross'] !== null) {
            $lines[] = sprintf('%s %d торговых дней назад', $ind['smaCross']['type'] === 'golden' ? 'Золотой крест (SMA50 пересекла SMA200 снизу вверх)' : 'Крест смерти (SMA50 пересекла SMA200 сверху вниз)', $ind['smaCross']['barsAgo']);
        }
        if ($ind['rsi14'] !== null) {
            $lines[] = 'RSI(14): '.self::num($ind['rsi14']);
        }
        if ($ind['macd'] !== null) {
            $m = $ind['macd'];
            $lines[] = sprintf('MACD(12,26,9): линия %s, сигнальная %s, гистограмма %s%s', self::num($m['line']), self::num($m['signal']), self::num($m['histogram']),
                match ($m['cross']) { 'bullish' => '; за последние дни линия пересекла сигнальную снизу вверх', 'bearish' => '; за последние дни линия пересекла сигнальную сверху вниз', default => '' });
        }
        if ($ind['bollinger'] !== null) {
            $b = $ind['bollinger'];
            $lines[] = sprintf('Полосы Боллинджера (20, 2): нижняя %s, средняя %s, верхняя %s, %%B %s', self::num($b['lower']), self::num($b['middle']), self::num($b['upper']), $b['percentB'] ?? '—');
        }
        if ($ind['volatility'] !== null) {
            $lines[] = 'Волатильность за месяц: '.self::num($ind['volatility']).'% годовых';
        }
        $lines[] = sprintf('52 недели: минимум %s, максимум %s; от максимума %s, от минимума %s', self::num($ind['low52']), self::num($ind['high52']), $pct($ind['fromHigh52']), $pct($ind['fromLow52']));
        $lines[] = 'Наибольшая просадка за год: '.$pct($ind['maxDrawdownYear']);
        $lines[] = sprintf('Поддержка (минимум 20 дней): %s; сопротивление (максимум 20 дней): %s', self::num($ind['support']), self::num($ind['resistance']));
        if ($ind['volumeRatio'] !== null) {
            $lines[] = 'Оборот последней недели к среднему за 50 дней: '.self::num($ind['volumeRatio']);
        }

        return $lines;
    }

    /**
     * @param list<MarketLot> $lots
     *
     * @return list<string>
     */
    private static function positionLines(array $lots, float $price, \DateTimeImmutable $now): array
    {
        $summary = self::position($lots, $price);
        if ($summary === null) {
            return [];
        }
        $lines = array_map(static fn (MarketLot $l) => sprintf('Покупка %s: %s шт. по %s на %s ₽',
            $l->getBoughtOn()->format('d.m.Y'), self::num($l->getQuantity()), self::num($l->getPrice()), self::num($l->getAmount())), $lots);
        $days = (int) (new \DateTimeImmutable($summary['firstBoughtOn'], new \DateTimeZone('UTC')))->diff($now)->days;
        $lines[] = sprintf('Итого: %s шт., вложено %s ₽, средняя цена покупки %s; сейчас стоит %s ₽, доходность %s%s%% (%s%s ₽); первая покупка %d дн. назад',
            self::num($summary['quantity']), self::num($summary['amount']), self::num($summary['averagePrice']), self::num((float) $summary['value']),
            $summary['profitPercent'] >= 0 ? '+' : '', self::num((float) $summary['profitPercent']), $summary['profit'] >= 0 ? '+' : '', self::num((float) $summary['profit']), $days);

        return $lines;
    }

    /** «[13.10 14:05] РБК — заголовок» — по-русски, если новость переведена; с анонсом — для упоминаний. */
    private static function newsLine(NewsItem $item, bool $withSummary = false): string
    {
        $time = EventTime::fromDb($item->getSortAt())->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m H:i');
        $summary = $withSummary
            ? mb_substr(trim((string) ($item->getTranslatedTitle() !== null ? $item->getTranslatedSummary() : $item->getSummary())), 0, 160)
            : '';

        $title = $item->getTranslatedTitle() ?? $item->getTitle();
        // Заголовки идут без точки — после них ещё анонс или прогноз.
        $title .= preg_match('/[.!?…]$/u', $title) ? '' : '.';

        return sprintf('[%s] %s — %s%s', $time, $item->getSource()->getTitle(), $title, $summary !== '' ? ' '.$summary : '');
    }

    /** @param list<NewsMarketImpact> $impacts */
    private static function impactText(array $impacts): string
    {
        $parts = [];
        foreach ($impacts as $m) {
            $forecast = ['up' => 'рост', 'down' => 'падение', 'mixed' => 'неясно'][$m->getForecast()] ?? $m->getForecast();
            $hour = NewsMarketImpact::change($m->getBasePrice(), $m->getHourPrice());
            $day = NewsMarketImpact::change($m->getBasePrice(), $m->getDayPrice());
            $fact = $hour === null ? 'торгов после новости ещё не было'
                : sprintf('%s за час торгов%s', ($hour > 0 ? '+' : '').self::num($hour).'%', $day !== null ? ', '.($day > 0 ? '+' : '').self::num($day).'% на закрытие дня' : '');
            $parts[] = sprintf(' Прогноз для %s: %s — %s Факт: %s.', $m->getSecid(), $forecast, rtrim($m->getReason(), '.').'.', $fact);
        }

        return implode('', $parts);
    }

    /** Число для промпта: без лишних нулей, до 4 значащих знаков после запятой у мелких цен. */
    private static function num(float $value): string
    {
        $decimals = abs($value) >= 100 ? 2 : (abs($value) >= 1 ? 3 : 4);

        return rtrim(rtrim(number_format($value, $decimals, '.', ''), '0'), '.');
    }
}
