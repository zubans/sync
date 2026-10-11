<?php

namespace App\Tests\Service;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use App\Service\NewsCollector;
use App\Service\NewsFeedParser;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NewsCollectorTest extends DatabaseWebTestCase
{
    private const FEED = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss version="2.0"><channel>
        <item><title>%s</title><link>https://example.com/a</link><guid>a</guid><pubDate>Sun, 11 Oct 2026 10:00:00 +0300</pubDate></item>
        <item><title>Вторая</title><link>https://example.com/b</link><guid>b</guid></item>
        </channel></rss>
        XML;

    /** @param list<MockResponse> $responses */
    private function collector(array $responses): NewsCollector
    {
        $container = static::getContainer();

        return new NewsCollector(
            new MockHttpClient($responses),
            $this->em,
            $container->get(NewsSourceRepository::class),
            $container->get(NewsItemRepository::class),
            new NewsFeedParser(),
        );
    }

    private function source(string $code = 'rbc'): NewsSource
    {
        $source = new NewsSource($code, strtoupper($code), "https://$code.example/feed");
        $this->em->persist($source);
        $this->em->flush();

        return $source;
    }

    public function testCollectSavesNewItemsOnceAndUpdatesEditedTitle(): void
    {
        $source = $this->source();
        $collector = $this->collector([
            new MockResponse(\sprintf(self::FEED, 'Первая'), ['response_headers' => ['ETag' => '"v1"']]),
            new MockResponse(\sprintf(self::FEED, 'Первая, исправлено')),
        ]);

        self::assertSame(['new' => 2, 'updated' => 0, 'error' => null], $collector->collect($source));
        self::assertSame(['new' => 0, 'updated' => 1, 'error' => null], $collector->collect($source));

        $items = $this->em->getRepository(NewsItem::class)->findBy([], ['id' => 'ASC']);
        self::assertCount(2, $items);
        self::assertSame('Первая, исправлено', $items[0]->getTitle());
        self::assertSame('2026-10-11 07:00:00', $items[0]->getSortAt()->format('Y-m-d H:i:s'), 'время публикации в UTC');
        self::assertEquals($items[1]->getFirstSeenAt(), $items[1]->getSortAt(), 'без времени публикации — время первого сбора');
    }

    public function testNotModifiedFeedKeepsItemsAndSendsCacheHeaders(): void
    {
        $source = $this->source();
        $sent = [];
        $responses = [
            new MockResponse(\sprintf(self::FEED, 'Первая'), ['response_headers' => ['ETag' => '"v1"', 'Last-Modified' => 'Sun, 11 Oct 2026 07:00:00 GMT']]),
            new MockResponse('', ['http_code' => 304]),
        ];
        $collector = new NewsCollector(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, &$responses) {
                $sent[] = $options['headers'];

                return array_shift($responses);
            }),
            $this->em,
            static::getContainer()->get(NewsSourceRepository::class),
            static::getContainer()->get(NewsItemRepository::class),
            new NewsFeedParser(),
        );

        $collector->collect($source);
        self::assertSame(['new' => 0, 'updated' => 0, 'error' => null], $collector->collect($source));

        self::assertContains('If-None-Match: "v1"', $sent[1]);
        self::assertContains('If-Modified-Since: Sun, 11 Oct 2026 07:00:00 GMT', $sent[1]);
        self::assertNull($source->getLastError());
        self::assertCount(2, $this->em->getRepository(NewsItem::class)->findAll());
    }

    public function testFailingSourceRecordsErrorAndDoesNotStopOthers(): void
    {
        $broken = $this->source('broken');
        $this->source('rbc');
        $collector = $this->collector([
            new MockResponse('', ['http_code' => 503]),
            new MockResponse(\sprintf(self::FEED, 'Первая')),
        ]);

        $report = $collector->collectAll();

        self::assertSame('HTTP 503', $report['broken']['error']);
        self::assertSame(2, $report['rbc']['new']);
        self::assertSame('HTTP 503', $broken->getLastError());
        self::assertNull($broken->getLastSuccessAt());
        self::assertNotNull($broken->getLastAttemptAt());
    }

    public function testDisabledSourceAndDigestAreNotCollected(): void
    {
        $this->source()->setEnabled(false);
        $this->em->persist(new NewsSource('digest', 'Сводка', '', NewsSource::FORMAT_DIGEST));
        $this->em->flush();

        self::assertSame([], $this->collector([])->collectAll());
    }
}
