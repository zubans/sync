<?php

namespace App\Tests\Api;

use App\Entity\MarketAnalysis;
use App\Entity\User;
use App\Repository\MarketAnalysisRepository;
use App\Service\MoexIss;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class MarketApiTest extends DatabaseWebTestCase
{
    private const SBER = ['secid' => 'SBER', 'engine' => 'stock', 'market' => 'shares', 'board' => 'TQBR', 'name' => 'Сбербанк'];
    private const LKOH = ['secid' => 'LKOH', 'engine' => 'stock', 'market' => 'shares', 'board' => 'TQBR', 'name' => 'ЛУКОЙЛ'];

    public function testWatchlistKeepsOrderAndRemovesDropped(): void
    {
        $this->createUser('investor@example.com');
        $token = $this->login('investor@example.com');

        $this->api('PUT', '/api/market/watchlist', ['instruments' => [self::SBER, self::LKOH]], $token);
        self::assertResponseIsSuccessful();
        $this->api('PUT', '/api/market/watchlist', ['instruments' => [self::LKOH, ['secid' => 'IMOEX', 'engine' => 'stock', 'market' => 'index', 'board' => 'SNDX']]], $token);

        $overview = $this->api('GET', '/api/market/overview', token: $token);
        self::assertSame(['LKOH', 'IMOEX'], array_column($overview['instruments'], 'secid'));
        self::assertNull($overview['instruments'][0]['analysis']);

        $this->api('PUT', '/api/market/watchlist', ['instruments' => [['secid' => 'SBER;DROP', 'engine' => 'stock', 'market' => 'shares', 'board' => 'TQBR']]], $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testLotsAreOwnAndRequestAnalysis(): void
    {
        $this->createUser('investor@example.com');
        $this->createUser('other@example.com');
        $token = $this->login('investor@example.com');
        $this->api('PUT', '/api/market/watchlist', ['instruments' => [self::SBER]], $token);

        $lot = $this->api('POST', '/api/market/instruments/sber/lots', ['boughtOn' => '2026-05-01', 'quantity' => 100, 'price' => 280.5], $token);
        self::assertResponseStatusCodeSame(201);
        // Сумма не передана — количество × цена.
        self::assertEquals(['secid' => 'SBER', 'boughtOn' => '2026-05-01', 'quantity' => 100, 'price' => 280.5, 'amount' => 28050], array_diff_key($lot, ['id' => 0]));

        $overview = $this->api('GET', '/api/market/overview', token: $token);
        self::assertCount(1, $overview['instruments'][0]['lots']);
        // Покупка меняет совет — анализ запрошен.
        self::assertTrue($overview['instruments'][0]['analysis']['pending']);
        self::assertNull($overview['instruments'][0]['analysis']['recommendation']);

        $this->api('POST', '/api/market/instruments/SBER/lots', ['boughtOn' => '2999-01-01', 'quantity' => 1, 'price' => 1], $token);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', '/api/market/instruments/SBER/lots', ['boughtOn' => '2026-05-01', 'quantity' => 0, 'price' => 1], $token);
        self::assertResponseStatusCodeSame(422);
        $this->api('POST', '/api/market/instruments/GAZP/lots', ['boughtOn' => '2026-05-01', 'quantity' => 1, 'price' => 1], $token);
        self::assertResponseStatusCodeSame(404);

        $this->api('DELETE', '/api/market/lots/'.$lot['id'], token: $this->login('other@example.com'));
        self::assertResponseStatusCodeSame(404);
        $this->api('DELETE', '/api/market/lots/'.$lot['id'], token: $token);
        self::assertResponseStatusCodeSame(204);
    }

    public function testInstrumentDetailHasIndicatorsChartAndAnalysis(): void
    {
        // ISS — заглушка: свечи есть, сведения и дивиденды не отвечают. Ядро не пересоздаётся между запросами,
        // иначе подменённый сервис пропал бы.
        $this->client->disableReboot();
        static::getContainer()->set(MoexIss::class, new MoexIss(new MockHttpClient(static function (string $method, string $url) {
            if (str_contains($url, '/candles.json')) {
                $rows = array_map(static fn (int $i) => [100 + $i, 100 + $i, 101 + $i, 99 + $i, 1e6, 10, date('Y-m-d', strtotime("2025-06-01 +$i days")).' 00:00:00', ''], range(0, 299));

                return new MockResponse(json_encode(['candles' => ['columns' => ['open', 'close', 'high', 'low', 'value', 'volume', 'begin', 'end'], 'data' => $rows]]));
            }

            return new MockResponse('', ['http_code' => 500]);
        })));
        $this->createUser('investor@example.com');
        $token = $this->login('investor@example.com');
        $this->api('PUT', '/api/market/watchlist', ['instruments' => [self::SBER]], $token);
        // Менеджер сущностей очищается после каждого запроса — пользователь заново из базы.
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'investor@example.com']);
        $analysis = new MarketAnalysis($user, 'SBER');
        $analysis->complete(['recommendation' => 'buy', 'outlook' => 'up', 'confidence' => 65, 'urgency' => 'watch', 'horizon' => '1–3 месяца',
            'summary' => 'Докупить.', 'news' => 'Дивиденды.', 'technical' => 'Тренд вверх.', 'position' => null, 'risks' => ['Ставка'], 'triggers' => []],
            300.0, 3, null, new \DateTimeImmutable('2026-10-14 09:00:00', new \DateTimeZone('UTC')));
        $this->em->persist($analysis);
        $this->em->flush();


        $detail = $this->api('GET', '/api/market/instruments/SBER', token: $token);

        self::assertResponseIsSuccessful();
        self::assertSame('Сбербанк', $detail['name']);
        self::assertCount(260, $detail['chart']);
        self::assertSame(399, (int) $detail['indicators']['close']);
        self::assertSame('up', $detail['indicators']['trend']);
        // Сведения и дивиденды не пришли — остальное есть.
        self::assertSame([], $detail['description']);
        self::assertSame('buy', $detail['analysis']['recommendation']);
        self::assertSame('2026-10-14T09:00:00+00:00', $detail['analysis']['analyzedAt']);
        self::assertFalse($detail['analysis']['pending']);
        self::assertNull($detail['error']);

        $requested = $this->api('POST', '/api/market/instruments/SBER/analysis', token: $token);
        self::assertResponseStatusCodeSame(202);
        self::assertTrue($requested['pending']);
        self::assertNotNull(static::getContainer()->get(MarketAnalysisRepository::class)->findOneBy(['secid' => 'SBER'])->getRequestedAt());
    }
}
