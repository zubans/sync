<?php

namespace App\Tests\Service;

use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsMarketImpactRepository;
use App\Service\MoexIss;
use App\Service\NewsMarketPrompt;
use App\Service\NewsMarketReaction;
use App\Service\NewsMarketService;
use App\Service\NewsSummarizer;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NewsMarketTest extends DatabaseWebTestCase
{
    /**
     * Десятиминутные свечи с закрытиями по порядку; время — московское, как в ISS.
     *
     * @param list<float> $closes
     *
     * @return list<array{begin: \DateTimeImmutable, close: float}>
     */
    private static function candles(string $moscowStart, array $closes): array
    {
        $at = new \DateTimeImmutable($moscowStart, new \DateTimeZone('Europe/Moscow'));
        $candles = [];
        foreach ($closes as $i => $close) {
            $candles[] = ['begin' => $at->modify('+'.(10 * $i).' minutes')->setTimezone(new \DateTimeZone('UTC')), 'close' => $close];
        }

        return $candles;
    }

    private static function utc(string $moscow): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($moscow, new \DateTimeZone('Europe/Moscow')))->setTimezone(new \DateTimeZone('UTC'));
    }

    public function testReactionComparesWithLastTradeBeforeNews(): void
    {
        // Новость в 10:05: свеча 10:00 — уже после неё, цена до новости — закрытие свечи 09:50.
        $candles = [
            ...self::candles('2026-10-12 09:50', [100.0, 101.0, 102.0, 103.0, 104.0, 105.0, 106.0, 107.0]),
            ...self::candles('2026-10-12 23:40', [99.0]),
            ...self::candles('2026-10-13 07:00', [98.0]),
        ];

        $r = NewsMarketReaction::measure($candles, self::utc('2026-10-12 10:05'), self::utc('2026-10-13 12:00'));

        self::assertSame(['base' => 100.0, 'hour' => 106.0, 'hourFinal' => true, 'day' => 99.0, 'dayFinal' => true], $r);
    }

    public function testReactionIsProvisionalUntilHourAndDayPass(): void
    {
        $candles = self::candles('2026-10-12 09:50', [100.0, 101.0, 103.0]);

        $r = NewsMarketReaction::measure($candles, self::utc('2026-10-12 10:05'), self::utc('2026-10-12 10:30'));

        self::assertSame(['base' => 100.0, 'hour' => 103.0, 'hourFinal' => false, 'day' => 103.0, 'dayFinal' => false], $r);
    }

    public function testWeekendNewsComparesFridayCloseWithMonday(): void
    {
        $candles = [
            ...self::candles('2026-10-09 23:30', [200.0, 201.0]),
            ...self::candles('2026-10-12 06:50', [190.0, 191.0, 192.0, 193.0, 194.0, 195.0, 196.0]),
        ];

        $r = NewsMarketReaction::measure($candles, self::utc('2026-10-10 15:00'), self::utc('2026-10-12 08:00'));

        self::assertSame(['base' => 201.0, 'hour' => 195.0, 'hourFinal' => true, 'day' => 196.0, 'dayFinal' => false], $r);
    }

    public function testPromptAnswerKeepsOnlyKnownNewsAndInstruments(): void
    {
        $answer = 'Вот ответ: '.json_encode(['news' => [
            ['id' => 1, 'impacts' => [
                ['secid' => 'sber', 'direction' => 'down', 'reason' => 'Штраф ударит по прибыли.'],
                ['secid' => 'SBER', 'direction' => 'up', 'reason' => 'Повтор.'],
                ['secid' => 'XXXX', 'direction' => 'up', 'reason' => 'Нет в списке.'],
                ['secid' => 'IMOEX', 'direction' => 'sideways', 'reason' => 'Неизвестное направление.'],
                ['secid' => 'MOEXFN', 'direction' => 'mixed', 'reason' => ''],
            ]],
            ['id' => 99, 'impacts' => [['secid' => 'SBER', 'direction' => 'up', 'reason' => 'Чужая новость.']]],
        ]]);

        $parsed = NewsMarketPrompt::parse($answer, [1, 2], ['SBER', 'IMOEX', 'MOEXFN']);

        self::assertSame([1 => [['secid' => 'SBER', 'direction' => 'down', 'reason' => 'Штраф ударит по прибыли.']]], $parsed);

        $this->expectException(\UnexpectedValueException::class);
        NewsMarketPrompt::parse('не знаю', [1], ['SBER']);
    }

    public function testRunLinksNewsToInstrumentsAndMeasuresPrice(): void
    {
        $source = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $this->em->persist($source);
        $now = self::utc('2026-10-13 12:00');
        $sber = $this->news($source, 'Сбербанк объявил дивиденды', self::utc('2026-10-13 10:05'));
        $quiet = $this->news($source, 'В Москве открылась выставка кошек', self::utc('2026-10-13 10:30'));
        $old = $this->news($source, 'Старая новость', self::utc('2026-10-11 10:00'));

        $requests = [];
        $iss = new MockHttpClient(static function (string $method, string $url) use (&$requests) {
            $requests[] = $url;
            if (str_contains($url, '/analytics/IMOEX.json')) {
                return new MockResponse(json_encode(['analytics' => [
                    'columns' => ['indexid', 'ticker', 'shortnames', 'secids', 'weight'],
                    'data' => [['IMOEX', 'SBER', 'Сбербанк', 'SBER', 15.0], ['IMOEX', 'GAZP', 'ГАЗПРОМ ао', 'GAZP', 9.5]],
                ]]));
            }
            $rows = array_map(
                static fn (array $c) => [0, $c['close'], 0, 0, 0, 0, $c['begin']->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d H:i:s'), ''],
                self::candles('2026-10-13 09:50', [300.0, 301.0, 302.0, 303.0, 304.0, 305.0, 306.0, 307.0]),
            );

            return new MockResponse(json_encode(['candles' => ['columns' => ['open', 'close', 'high', 'low', 'value', 'volume', 'begin', 'end'], 'data' => $rows]]));
        });
        $prompts = [];
        $summarizer = new class([json_encode(['news' => [
            ['id' => $sber->getId(), 'impacts' => [['secid' => 'SBER', 'direction' => 'up', 'reason' => 'Дивиденды выше ожиданий — акции могут вырасти.']]],
        ]])], $prompts) implements NewsSummarizer {
            /**
             * @param list<string> $answers
             * @param list<string> $prompts
             */
            public function __construct(private array $answers, private array &$prompts)
            {
            }

            public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
            {
                $this->prompts[] = $prompt;

                return array_shift($this->answers) ?? throw new \LogicException('Лишний запрос к модели');
            }
        };
        $service = new NewsMarketService(
            $this->em,
            static::getContainer()->get(NewsItemRepository::class),
            static::getContainer()->get(NewsMarketImpactRepository::class),
            $summarizer,
            new MoexIss($iss),
        );

        $report = $service->run($now);

        self::assertSame(['checked' => 2, 'linked' => 1, 'measured' => 1, 'settled' => 0, 'error' => null], $report);
        self::assertStringContainsString('GAZP — ГАЗПРОМ', $prompts[0], 'тип бумаги из названия убран');
        self::assertStringContainsString('IMOEX — Индекс МосБиржи', $prompts[0]);
        self::assertStringContainsString("[{$sber->getId()}] РБК, 13.10 10:05 — Сбербанк объявил дивиденды", $prompts[0]);
        self::assertStringNotContainsString('Старая новость', $prompts[0], 'архив модели не отдаётся');
        self::assertNotNull($quiet->getMarketCheckedAt(), 'новость без последствий тоже проверена');
        self::assertNull($old->getMarketCheckedAt());

        $impacts = static::getContainer()->get(NewsMarketImpactRepository::class)->findAll();
        self::assertCount(1, $impacts);
        self::assertSame(['SBER', 'Сбербанк', 'up'], [$impacts[0]->getSecid(), $impacts[0]->getName(), $impacts[0]->getForecast()]);
        // До новости — 300, через час торгов — 306 (+2 %); день ещё идёт — замер промежуточный.
        self::assertSame([300.0, 306.0, true, 307.0, false], [
            $impacts[0]->getBasePrice(), $impacts[0]->getHourPrice(), $impacts[0]->isHourFinal(), $impacts[0]->getDayPrice(), $impacts[0]->isSettled(),
        ]);
        self::assertStringContainsString('/engines/stock/markets/shares/securities/SBER/candles.json', end($requests));
        self::assertStringContainsString('from=2026-10-08', end($requests), 'свечи с запасом до новости');

        // Следующий проход после закрытия дня: модели новости второй раз не отдаются, замер закрывается.
        $report = $service->run(self::utc('2026-10-14 08:00'));
        self::assertSame(['checked' => 0, 'linked' => 0, 'measured' => 1, 'settled' => 1, 'error' => null], $report);
        self::assertTrue($impacts[0]->isSettled());
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
