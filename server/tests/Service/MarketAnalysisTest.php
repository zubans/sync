<?php

namespace App\Tests\Service;

use App\Entity\MarketAnalysis;
use App\Entity\MarketInstrument;
use App\Entity\MarketLot;
use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use App\Entity\NewsSource;
use App\Repository\MarketAnalysisRepository;
use App\Repository\MarketInstrumentRepository;
use App\Repository\MarketLotRepository;
use App\Repository\NewsItemRepository;
use App\Repository\NewsMarketImpactRepository;
use App\Service\MarketAnalysisPrompt;
use App\Service\MarketAnalysisService;
use App\Service\MarketIndicators;
use App\Service\MoexIss;
use App\Service\NewsSummarizer;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketAnalysisTest extends DatabaseWebTestCase
{
    /**
     * Дневные свечи с закрытиями по порядку, начиная с 2025-01-01; максимум и минимум — ±1 от закрытия.
     *
     * @param list<float> $closes
     *
     * @return list<array{date: string, open: float, high: float, low: float, close: float, value: float}>
     */
    private static function days(array $closes, float $value = 1000.0): array
    {
        $day = new \DateTimeImmutable('2025-01-01');

        return array_map(static fn (int $i, float $c) => [
            'date' => $day->modify("+$i days")->format('Y-m-d'), 'open' => $c, 'high' => $c + 1, 'low' => $c - 1, 'close' => $c, 'value' => $value,
        ], array_keys($closes), $closes);
    }

    public function testIndicatorsOfSteadyGrowth(): void
    {
        $closes = array_map(static fn (int $i) => 100.0 + $i, range(0, 299));

        $ind = MarketIndicators::compute(self::days($closes));

        self::assertSame(399.0, $ind['close']);
        self::assertSame(300, $ind['bars']);
        self::assertEqualsWithDelta(389.5, $ind['sma20'], 1e-9);
        self::assertEqualsWithDelta(299.5, $ind['sma200'], 1e-9);
        self::assertSame('up', $ind['trend']);
        // Ни одного падения — RSI на максимуме.
        self::assertSame(100.0, $ind['rsi14']);
        self::assertSame(round((399 / 394 - 1) * 100, 2), $ind['returns']['week']);
        self::assertSame(round((399 / 147 - 1) * 100, 2), $ind['returns']['year']);
        self::assertSame(0.0, $ind['maxDrawdownYear']);
        self::assertSame(400.0, $ind['high52']);
        self::assertSame(379.0, $ind['support']);
        self::assertSame(1.0, $ind['volumeRatio']);
        self::assertGreaterThan(0, $ind['macd']['line']);
    }

    public function testIndicatorsNeedEnoughHistory(): void
    {
        $ind = MarketIndicators::compute(self::days([10.0, 11.0, 12.0]));

        self::assertNull($ind['sma20']);
        self::assertNull($ind['rsi14']);
        self::assertNull($ind['macd']);
        self::assertNull($ind['returns']['month']);
        self::assertNull($ind['trend']);
        self::assertSame([], MarketIndicators::compute([]));
    }

    public function testRsiDrawdownAndDeathCross(): void
    {
        self::assertSame(-50.0, MarketIndicators::maxDrawdown([100.0, 120.0, 60.0, 90.0]));
        // Рост 250 дней, потом обвал — 50-дневная уходит под 200-дневную.
        $closes = [...array_map(static fn (int $i) => 100.0 + $i, range(0, 249)), ...array_fill(0, 40, 150.0)];
        $cross = null;
        for ($n = 260; $n <= \count($closes) && $cross === null; ++$n) {
            $cross = MarketIndicators::smaCross(\array_slice($closes, 0, $n));
        }
        self::assertSame('death', $cross['type'] ?? null);
        self::assertSame('down', MarketIndicators::trend(150.0, 160.0, 170.0));
        // Только падения — RSI на минимуме.
        self::assertSame(0.0, MarketIndicators::rsi(array_map(static fn (int $i) => 200.0 - $i, range(0, 40)), 14));
    }

    public function testPromptAnswerIsValidated(): void
    {
        $answer = 'Ответ: '.json_encode([
            'recommendation' => 'HOLD', 'outlook' => 'up', 'confidence' => 140, 'urgency' => 'panic', 'horizon' => '',
            'summary' => 'Держать.', 'news' => 'Дивиденды.', 'technical' => 'Тренд вверх.', 'position' => 'Не нужно.',
            'risks' => ['санкции', '', 7, 'ставка'], 'triggers' => [],
        ]);

        $withPosition = MarketAnalysisPrompt::parse($answer, true);
        self::assertSame('hold', $withPosition['recommendation']);
        self::assertSame(100, $withPosition['confidence']);
        self::assertSame('none', $withPosition['urgency']);
        self::assertSame('1–3 месяца', $withPosition['horizon']);
        self::assertSame(['санкции', '7', 'ставка'], $withPosition['risks']);
        self::assertSame('Не нужно.', $withPosition['position']);

        // Без покупок держать нечего — наблюдать; совета по позиции нет.
        $without = MarketAnalysisPrompt::parse($answer, false);
        self::assertSame('wait', $without['recommendation']);
        self::assertNull($without['position']);

        $this->expectException(\UnexpectedValueException::class);
        MarketAnalysisPrompt::parse('{"recommendation": "moon", "outlook": "up", "summary": "x"}', true);
    }

    public function testSearchWordsSkipShortAndLegalWords(): void
    {
        self::assertSame(['сбербанк'], MarketAnalysisService::searchWords('Сбербанк ап'));
        self::assertSame(['лукойл'], MarketAnalysisService::searchWords('ЛУКОЙЛ'));
        self::assertSame(['мосбиржа'], MarketAnalysisService::searchWords('ПАО «Мосбиржа»'));
    }

    public function testRunAnalyzesDueInstrumentsWithNewsIndicatorsAndPosition(): void
    {
        $user = $this->createUser('investor@example.com');
        $now = new \DateTimeImmutable('2026-10-14 12:00:00', new \DateTimeZone('UTC'));
        $sber = new MarketInstrument($user, 'SBER', 'stock', 'shares', 'TQBR', 'Сбербанк', 0);
        $fresh = new MarketInstrument($user, 'LKOH', 'stock', 'shares', 'TQBR', 'ЛУКОЙЛ', 1);
        $this->em->persist($sber);
        $this->em->persist($fresh);
        // У ЛУКОЙЛа анализ свежий и новых новостей нет — его не трогаем.
        $done = new MarketAnalysis($user, 'LKOH');
        $done->complete(['recommendation' => 'hold', 'outlook' => 'up', 'confidence' => 60, 'urgency' => 'none', 'horizon' => '1 месяц',
            'summary' => 'Держать.', 'news' => '', 'technical' => '', 'position' => null, 'risks' => [], 'triggers' => []], 7000.0, 0, null, $now->modify('-1 hour'));
        $this->em->persist($done);
        $this->em->persist(new MarketLot($user, 'SBER', new \DateTimeImmutable('2026-05-01'), 100, 280.0, 28000.0, $now));

        $source = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $this->em->persist($source);
        $dividends = $this->news($source, 'Сбербанк рекомендовал рекордные дивиденды', $now->modify('-2 hours'));
        $mention = $this->news($source, 'Глава Сбербанка выступил на форуме', $now->modify('-3 hours'));
        $this->news($source, 'В Москве открылась выставка кошек', $now->modify('-1 hour'));
        $impact = new NewsMarketImpact($dividends, 'SBER', 'Сбербанк', 'stock', 'shares', 'up', 'Дивиденды выше ожиданий.', $now->modify('-2 hours'));
        $impact->measure(300.0, 306.0, true, 309.0, true);
        $this->em->persist($impact);
        $this->em->flush();

        $closes = array_map(static fn (int $i) => 250.0 + $i * 0.25, range(0, 259));
        $iss = new MockHttpClient(static function (string $method, string $url) use ($closes) {
            if (str_contains($url, '/candles.json')) {
                $rows = array_map(static fn (array $c) => [$c['open'], $c['close'], $c['high'], $c['low'], $c['value'], 10, $c['date'].' 00:00:00', $c['date'].' 23:59:59'], self::days($closes));

                return new MockResponse(json_encode(['candles' => ['columns' => ['open', 'close', 'high', 'low', 'value', 'volume', 'begin', 'end'], 'data' => $rows]]));
            }
            if (str_contains($url, '/dividends.json')) {
                return new MockResponse(json_encode(['dividends' => ['columns' => ['secid', 'isin', 'registryclosedate', 'value', 'currencyid'], 'data' => [['SBER', 'RU0009029540', '2026-07-18', 34.84, 'RUB']]]]));
            }

            return new MockResponse(json_encode(['description' => ['columns' => ['name', 'title', 'value'], 'data' => [['NAME', 'Полное наименование', 'Сбербанк России ПАО ао']]]]));
        });
        $prompts = [];
        $summarizer = new class($prompts) implements NewsSummarizer {
            /** @param list<string> $prompts */
            public function __construct(private array &$prompts)
            {
            }

            public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
            {
                $this->prompts[] = $prompt;

                return json_encode(['recommendation' => 'buy', 'outlook' => 'up', 'confidence' => 65, 'urgency' => 'watch', 'horizon' => '1–3 месяца',
                    'summary' => 'Докупить на откатах.', 'news' => 'Рекордные дивиденды уже частично отыграны.', 'technical' => 'Восходящий тренд.',
                    'position' => 'Позиция в плюсе, держать.', 'risks' => ['Ставка ЦБ'], 'triggers' => ['Пробой 300']], \JSON_UNESCAPED_UNICODE);
            }
        };
        $service = new MarketAnalysisService(
            $this->em,
            static::getContainer()->get(MarketInstrumentRepository::class),
            static::getContainer()->get(MarketAnalysisRepository::class),
            static::getContainer()->get(MarketLotRepository::class),
            static::getContainer()->get(NewsMarketImpactRepository::class),
            static::getContainer()->get(NewsItemRepository::class),
            $summarizer,
            new MoexIss($iss),
        );

        $report = $service->run($now);

        self::assertSame(['analyzed' => 1, 'failed' => 0, 'error' => null], $report);
        self::assertCount(1, $prompts);
        $prompt = $prompts[0];
        self::assertStringContainsString('SBER — Сбербанк (акция)', $prompt);
        self::assertStringContainsString('Сбербанк рекомендовал рекордные дивиденды', $prompt);
        self::assertStringContainsString('Прогноз для SBER: рост — Дивиденды выше ожиданий. Факт: +2% за час торгов, +3% на закрытие дня.', $prompt);
        self::assertStringContainsString('Глава Сбербанка выступил на форуме', $prompt);
        self::assertStringNotContainsString('выставка кошек', $prompt);
        self::assertStringContainsString('RSI(14):', $prompt);
        self::assertStringContainsString('Покупка 01.05.2026: 100 шт. по 280 на 28000 ₽', $prompt);
        self::assertStringContainsString('2026-07-18 — 34.84 RUB', $prompt);
        self::assertStringContainsString('Полное наименование: Сбербанк России ПАО ао', $prompt);

        $analysis = static::getContainer()->get(MarketAnalysisRepository::class)->findOneBy(['secid' => 'SBER']);
        self::assertSame('buy', $analysis->getRecommendation());
        self::assertSame('watch', $analysis->getUrgency());
        self::assertSame(314.75, $analysis->getPrice());
        self::assertSame(2, $analysis->getNewsCount());
        self::assertSame('Позиция в плюсе, держать.', $analysis->getPosition());
        self::assertNull($analysis->getRequestedAt());

        // Второй проход — всё свежее, к модели не идём.
        self::assertSame(['analyzed' => 0, 'failed' => 0, 'error' => null], $service->run($now->modify('+10 minutes')));
    }

    public function testPositionReturnFollowsPriceFromPurchasePrice(): void
    {
        $user = $this->createUser('investor@example.com');
        $now = new \DateTimeImmutable('2026-10-14');
        $lots = [
            new MarketLot($user, 'SU26238RMFS4', new \DateTimeImmutable('2026-03-01'), 10, 60.0, 6100.0, $now),
            new MarketLot($user, 'SU26238RMFS4', new \DateTimeImmutable('2026-06-01'), 10, 50.0, 5000.0, $now),
        ];

        $p = MarketAnalysisService::position($lots, 66.0);

        // 6100 × 66/60 + 5000 × 66/50 = 6710 + 6600.
        self::assertSame(13310.0, $p['value']);
        self::assertSame(11100.0, $p['amount']);
        self::assertSame(2210.0, $p['profit']);
        self::assertSame(19.91, $p['profitPercent']);
        self::assertSame(55.0, $p['averagePrice']);
        self::assertSame('2026-03-01', $p['firstBoughtOn']);
        self::assertNull(MarketAnalysisService::position([], 66.0));
    }

    private function news(NewsSource $source, string $title, \DateTimeImmutable $at): NewsItem
    {
        $item = new NewsItem($source, sha1($title), $at);
        $item->apply('https://example.com/'.sha1($title), $title, null, null, $at, $at);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }
}
