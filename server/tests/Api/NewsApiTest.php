<?php

namespace App\Tests\Api;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Tests\DatabaseWebTestCase;

final class NewsApiTest extends DatabaseWebTestCase
{
    private function item(NewsSource $source, string $key, string $time, bool $published = true): NewsItem
    {
        $at = new \DateTimeImmutable($time, new \DateTimeZone('UTC'));
        $item = new NewsItem($source, sha1($key), $at);
        $item->apply("https://example.com/$key", "Новость $key", "Анонс $key", null, $published ? $at : null, $at);
        $this->em->persist($item);

        return $item;
    }

    public function testFeedRequiresToken(): void
    {
        $this->api('GET', '/api/news');

        self::assertResponseStatusCodeSame(401);
    }

    public function testFeedIsNewestFirstAndPagesByCursor(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $cnn = new NewsSource('cnn', 'CNN', 'https://lite.cnn.com/', NewsSource::FORMAT_CNN_LITE);
        $this->em->persist($rbc);
        $this->em->persist($cnn);
        $this->item($rbc, 'a', '2026-10-11 07:00:00');
        $this->item($cnn, 'b', '2026-10-11 09:00:00', published: false);
        // Две новости в одну секунду — курсор не должен потерять вторую.
        $this->item($rbc, 'c', '2026-10-11 08:00:00');
        $this->item($rbc, 'd', '2026-10-11 08:00:00');
        $this->em->flush();

        $page = $this->api('GET', '/api/news?limit=2', token: $token);
        self::assertResponseIsSuccessful();
        self::assertSame(['Новость b', 'Новость d'], array_column($page['items'], 'title'));
        self::assertSame('cnn', $page['items'][0]['source']);
        self::assertNull($page['items'][0]['publishedAt']);
        self::assertSame('2026-10-11T09:00:00+00:00', $page['items'][0]['time']);
        self::assertSame('Анонс d', $page['items'][1]['summary']);

        $next = $this->api('GET', '/api/news?limit=2&before='.$page['nextCursor'], token: $token);
        self::assertSame(['Новость c', 'Новость a'], array_column($next['items'], 'title'));

        $rbcOnly = $this->api('GET', '/api/news?source=rbc', token: $token);
        self::assertSame(['Новость d', 'Новость c', 'Новость a'], array_column($rbcOnly['items'], 'title'));
        self::assertNull($rbcOnly['nextCursor']);
    }

    public function testDigestHasStoriesWithSourceLinksAndStaysOutOfAll(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $digestSource = new NewsSource('digest', 'Сводка', '', NewsSource::FORMAT_DIGEST);
        $digestSource->markFailure(new \DateTimeImmutable('-1 minute', new \DateTimeZone('UTC')), 'agy: квота');
        $this->em->persist($rbc);
        $this->em->persist($digestSource);
        $news = $this->item($rbc, 'a', '2026-10-11 13:20:00');
        $digestItem = $this->item($digestSource, 'hour:2026-10-11T14:00Z', '2026-10-11 14:00:00');
        $this->em->flush();
        $digest = new NewsDigest($digestItem, NewsDigest::PERIOD_HOUR,
            new \DateTimeImmutable('2026-10-11 13:00:00'), new \DateTimeImmutable('2026-10-11 14:00:00'));
        $digest->update([['title' => 'Сюжет', 'text' => 'Суть.', 'items' => [(int) $news->getId()]]], 1, null, ['title' => 'Статья', 'text' => "А.\n\nБ."]);
        $this->em->persist($digest);
        $this->em->flush();

        $all = $this->api('GET', '/api/news', token: $token);
        self::assertSame(['Новость a'], array_column($all['items'], 'title'));
        self::assertArrayNotHasKey('digest', $all['items'][0]);

        $digests = $this->api('GET', '/api/news?source=digest', token: $token)['items'];
        self::assertSame('hour', $digests[0]['digest']['period']);
        self::assertSame('2026-10-11T13:00:00+00:00', $digests[0]['digest']['start']);
        self::assertSame(['title' => 'Статья', 'text' => "А.\n\nБ."], $digests[0]['digest']['article']);
        self::assertSame(
            [['id' => $news->getId(), 'source' => 'rbc', 'sourceTitle' => 'РБК', 'title' => 'Новость a', 'link' => 'https://example.com/a']],
            $digests[0]['digest']['stories'][0]['sources'],
        );

        $sources = $this->api('GET', '/api/news/sources', token: $token)['sources'];
        self::assertSame([false, true], array_column($sources, 'stale'), 'сводка с ошибкой последней попытки помечена сразу');
    }

    public function testDigestOnlySourcesStayOutOfWidget(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $lemonde = (new NewsSource('lemonde', 'Le Monde', 'https://lemonde.example/feed'))->setShowInWidget(false);
        $this->em->persist($rbc);
        $this->em->persist($lemonde);
        $this->item($rbc, 'a', '2026-10-11 07:00:00');
        $this->item($lemonde, 'b', '2026-10-11 08:00:00');
        $this->em->flush();

        self::assertSame(['rbc'], array_column($this->api('GET', '/api/news/sources', token: $token)['sources'], 'code'));
        self::assertSame(['Новость a'], array_column($this->api('GET', '/api/news', token: $token)['items'], 'title'));
    }

    public function testStorylinesListAndTimeline(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $bbc = new NewsSource('bbc', 'BBC', 'https://bbc.example/feed');
        $this->em->persist($rbc);
        $this->em->persist($bbc);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $line = new \App\Entity\NewsStoryline([1.0, 0.0], $now->modify('-2 days'));
        $line->add([1.0, 0.0], $now->modify('-1 hour'));
        $line->add([1.0, 0.0], $now->modify('-30 minutes'));
        $line->name('Удары по Эр-Рияду', 'Хуситы атакуют аэропорт.');
        $unnamed = new \App\Entity\NewsStoryline([0.0, 1.0], $now);
        $this->em->persist($line);
        $this->em->persist($unnamed);
        foreach ([[$rbc, 'a', '-2 days'], [$rbc, 'b', '-1 hour'], [$bbc, 'c', '-30 minutes']] as [$source, $key, $ago]) {
            $this->item($source, $key, $now->modify($ago)->format('Y-m-d H:i:s'))->setStoryline($line);
        }
        $this->em->flush();

        $list = $this->api('GET', '/api/news/storylines', token: $token)['storylines'];
        self::assertCount(1, $list, 'без названия линия не показывается');
        self::assertSame('Удары по Эр-Рияду', $list[0]['title']);
        self::assertSame(['РБК' => 2, 'BBC' => 1], $list[0]['sources']);
        self::assertSame(2, $list[0]['recent'], 'за последние сутки');

        $timeline = $this->api('GET', '/api/news/storylines/'.$line->getId(), token: $token);
        self::assertSame(['Новость c', 'Новость b', 'Новость a'], array_column($timeline['items'], 'title'));
        self::assertSame(['id' => $line->getId(), 'title' => 'Удары по Эр-Рияду', 'newsCount' => 3], $timeline['items'][0]['storyline']);

        $this->api('GET', '/api/news/storylines/999999', token: $token);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownSourceAndBadCursorAreRejected(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());

        $this->api('GET', '/api/news?source=nope', token: $token);
        self::assertResponseStatusCodeSame(404);

        $this->api('GET', '/api/news?before=yesterday', token: $token);
        self::assertResponseStatusCodeSame(422);
    }

    public function testSourcesShowStaleOnlyAfterFailedAttempts(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $utc = new \DateTimeZone('UTC');
        $fresh = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $fresh->markSuccess(new \DateTimeImmutable('-5 minutes', $utc), null, null);
        $failing = new NewsSource('nyt', 'NYT', 'https://nyt.example/feed');
        $failing->markSuccess(new \DateTimeImmutable('-2 hours', $utc), null, null);
        $failing->markFailure(new \DateTimeImmutable('-1 minute', $utc), 'HTTP 503');
        $new = new NewsSource('rg', 'РГ', 'https://rg.example/feed');
        $off = (new NewsSource('off', 'Выкл', 'https://off.example/feed'))->setEnabled(false);
        foreach ([$fresh, $failing, $new, $off] as $source) {
            $this->em->persist($source);
        }
        $this->em->flush();

        $sources = $this->api('GET', '/api/news/sources', token: $token)['sources'];

        self::assertSame(['rbc', 'nyt', 'rg'], array_column($sources, 'code'));
        self::assertSame([false, true, false], array_column($sources, 'stale'));
        self::assertSame('HTTP 503', $sources[1]['lastError']);
    }
}
