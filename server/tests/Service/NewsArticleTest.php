<?php

namespace App\Tests\Service;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Service\NewsArticleExtractor;
use App\Service\NewsArticleFetcher;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NewsArticleTest extends DatabaseWebTestCase
{
    private const PAGE = <<<'HTML'
        <html><head><title>Статья</title><script>var x = 1;</script></head><body>
        <header><p>Меню сайта: главная, новости, спорт и все прочие разделы издания</p></header>
        <div class="article">
          <figure><img src="a.jpg"><figcaption>Подпись к фото, которой в тексте быть не должно</figcaption></figure>
          <p>Первый абзац статьи с достаточно длинным текстом, чтобы набрать объём и оказаться главным блоком.</p>
          <h2>Подзаголовок</h2>
          <p>Второй абзац &laquo;в кавычках&raquo;, тоже длинный: в нём продолжается рассказ о событии дня.</p>
          <p>Читайте также: другая новость</p>
          <blockquote><p>Цитата героя статьи, которая должна остаться в тексте как отдельный абзац.</p></blockquote>
          <p>Третий абзац завершает статью и добавляет ещё немного текста для надёжного объёма.</p>
        </div>
        <aside><p>Боковая колонка с рекламой и ссылками на другие материалы издания, длинная-длинная.</p></aside>
        <footer><p>© Издание, все права защищены, свидетельство о регистрации СМИ и прочее.</p></footer>
        </body></html>
        HTML;

    public function testExtractorKeepsArticleTextWithoutPhotosAndBoilerplate(): void
    {
        $paragraphs = NewsArticleExtractor::extract(self::PAGE);

        self::assertSame([
            'Первый абзац статьи с достаточно длинным текстом, чтобы набрать объём и оказаться главным блоком.',
            'Подзаголовок',
            'Второй абзац «в кавычках», тоже длинный: в нём продолжается рассказ о событии дня.',
            'Цитата героя статьи, которая должна остаться в тексте как отдельный абзац.',
            'Третий абзац завершает статью и добавляет ещё немного текста для надёжного объёма.',
        ], $paragraphs);
        self::assertSame([], NewsArticleExtractor::extract('<html><body><p>Коротко.</p></body></html>'), 'анонс — не статья');
    }

    public function testExtractorFindsArticleWhenEachParagraphHasItsOwnWrappers(): void
    {
        // Как у BBC: каждый абзац в нескольких своих обёртках, общий у них только <article>.
        $block = static fn (string $text) => "<div><div><div><div><p>$text</p></div></div></div></div>";
        $article = implode('', array_map(static fn (int $n) => $block(str_repeat("Абзац статьи номер $n. ", 8)), range(1, 6)));
        $html = '<html><body><div><div><p>'.str_repeat('Абзац рекламы рядом со статьёй. ', 6).'</p></div></div>'
            ."<article>$article</article></body></html>";

        $paragraphs = NewsArticleExtractor::extract($html);

        self::assertCount(6, $paragraphs);
        self::assertStringStartsWith('Абзац статьи номер 1.', $paragraphs[0]);
    }

    public function testLeadFromFeedGoesFirstWhenPageLacksIt(): void
    {
        self::assertSame(['Анонс из ленты.', 'Текст.'], NewsArticleFetcher::withLead(['Текст.'], 'Анонс из ленты.'));
        self::assertSame(['Анонс из ленты. И дальше.'], NewsArticleFetcher::withLead(['Анонс из ленты. И дальше.'], 'Анонс из ленты.'));
    }

    public function testFetcherStoresFullTextSkipsFeedFullTextAndGivesUpOnClosedSites(): void
    {
        $rg = new NewsSource('rg', 'РГ', 'https://rg.example/feed');
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $this->em->persist($rg);
        $this->em->persist($rbc);
        $now = new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC'));
        $open = $this->item($rg, 'open', 'Анонс открытой статьи.', null, $now);
        $closed = $this->item($rg, 'closed', null, null, $now->modify('-1 hour'));
        $this->item($rbc, 'full', null, str_repeat('Полный текст из ленты. ', 30), $now);
        $this->em->flush();

        $requested = [];
        $fetcher = new NewsArticleFetcher(new MockHttpClient(static function (string $method, string $url) use (&$requested) {
            $requested[] = $url;

            return str_ends_with($url, '/open') ? new MockResponse(self::PAGE) : new MockResponse('', ['http_code' => 403]);
        }), $this->em, static::getContainer()->get(NewsItemRepository::class), pauseUs: 0);

        self::assertSame(['fetched' => 1, 'failed' => 1], $fetcher->fetchMissing(10));
        self::assertSame(['https://example.com/open', 'https://example.com/closed'], $requested, 'РБК с полным текстом в ленте не скачивается');
        self::assertStringStartsWith("Анонс открытой статьи.\n\nПервый абзац", $open->getContent());
        self::assertNotNull($open->getContentFetchedAt());

        $fetcher->fetchMissing(10);
        $fetcher->fetchMissing(10);
        self::assertSame(3, $closed->getContentAttempts());
        $requested = [];
        self::assertSame(['fetched' => 0, 'failed' => 0], $fetcher->fetchMissing(10), 'после трёх попыток закрытый сайт не запрашивается');

        // Повторный сбор ленты не затирает скачанный текст.
        $open->apply('https://example.com/open', 'Заголовок', 'Анонс открытой статьи.', null, $now, $now);
        self::assertStringContainsString('Третий абзац', (string) $open->getContent());
    }

    public function testItemEndpointReturnsParagraphs(): void
    {
        $token = $this->login($this->createUser('anna@example.com')->getEmail());
        $rg = new NewsSource('rg', 'РГ', 'https://rg.example/feed');
        $this->em->persist($rg);
        $now = new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC'));
        $item = $this->item($rg, 'a', 'Анонс.', null, $now);
        $item->setFetchedContent("Первый.\n\nВторой.", $now);
        $short = $this->item($rg, 'b', 'Только анонс.', null, $now);
        $this->em->flush();

        $full = $this->api('GET', '/api/news/'.$item->getId(), token: $token);
        self::assertSame(['Первый.', 'Второй.'], $full['content']);
        self::assertTrue($full['fullText']);
        self::assertSame('https://example.com/a', $full['link']);

        $summary = $this->api('GET', '/api/news/'.$short->getId(), token: $token);
        self::assertSame([], $summary['content']);
        self::assertFalse($summary['fullText']);
        self::assertSame('Только анонс.', $summary['summary']);

        $this->api('GET', '/api/news/999999', token: $token);
        self::assertResponseStatusCodeSame(404);
    }

    private function item(NewsSource $source, string $key, ?string $summary, ?string $content, \DateTimeImmutable $at): NewsItem
    {
        $item = new NewsItem($source, sha1($key), $at);
        $item->apply("https://example.com/$key", "Новость $key", $summary, $content, $at, $at);
        $this->em->persist($item);

        return $item;
    }
}
