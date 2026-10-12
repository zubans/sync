<?php

namespace App\Tests\Service;

use App\Entity\NewsSource;
use App\Service\NewsFeedParser;
use PHPUnit\Framework\TestCase;

final class NewsFeedParserTest extends TestCase
{
    private const RSS = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <rss xmlns:rbc_news="https://www.rbc.ru" xmlns:content="http://purl.org/rss/1.0/modules/content/" version="2.0"><channel>
        <title>Главные новости</title>
        <item>
            <title><![CDATA[Правительство создаст нацпарк «Вулканы Камчатки»]]></title>
            <link>https://www.rbc.ru/society/10/10/2026/6aca</link>
            <guid isPermaLink="false">rssexport.rbc.ru:society:6aca</guid>
            <pubDate>Sat, 10 Oct 2026 23:08:52 +0300</pubDate>
            <description><![CDATA[Парк займет <b>888 тыс. га</b>]]></description>
            <rbc_news:full-text><![CDATA[Парк создадут на базе &laquo;Южно-Камчатского&raquo;.]]></rbc_news:full-text>
        </item>
        <item>
            <title>&#8216;No other date&#8217;: pastor &amp; prophecy</title>
            <link> https://nypost.com/2026/10/10/pastor/ </link>
            <pubDate>Sat, 10 Oct 2026 16:44:53 -0400</pubDate>
            <content:encoded><![CDATA[<p>Full story.</p>]]></content:encoded>
        </item>
        <item><title>Без ссылки</title></item>
        </channel></rss>
        XML;

    public function testRssItemsTakeTitleLinkTimeAndText(): void
    {
        $entries = (new NewsFeedParser())->parse(NewsSource::FORMAT_RSS, self::RSS);

        self::assertCount(2, $entries);
        self::assertSame('Правительство создаст нацпарк «Вулканы Камчатки»', $entries[0]->title);
        self::assertSame('rssexport.rbc.ru:society:6aca', $entries[0]->key);
        self::assertSame('2026-10-10T20:08:52+00:00', $entries[0]->publishedAt?->format(\DATE_ATOM));
        self::assertSame('Парк займет 888 тыс. га', $entries[0]->summary);
        self::assertSame('Парк создадут на базе «Южно-Камчатского».', $entries[0]->content);

        self::assertSame('‘No other date’: pastor & prophecy', $entries[1]->title);
        self::assertSame('https://nypost.com/2026/10/10/pastor/', $entries[1]->link);
        self::assertSame('https://nypost.com/2026/10/10/pastor/', $entries[1]->key, 'без guid ключ — адрес статьи');
        self::assertNull($entries[1]->summary);
        self::assertSame('Full story.', $entries[1]->content);
    }

    public function testRssWithDoctypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NewsFeedParser())->rss('<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&x;</title><link>https://a</link></item></channel></rss>');
    }

    public function testBrokenXmlIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new NewsFeedParser())->rss('<html>не лента');
    }

    public function testNewsSitemapIsFilteredAndSortedByTime(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:news="http://www.google.com/schemas/sitemap-news/0.9">
            <url><loc>https://bbc.example/news/articles/old</loc><news:news><news:publication_date>2026-10-12T05:00:00Z</news:publication_date><news:title>Old &amp; gold</news:title></news:news></url>
            <url><loc>https://bbc.example/serbian/articles/x</loc><news:news><news:publication_date>2026-10-12T07:00:00Z</news:publication_date><news:title>Сербская</news:title></news:news></url>
            <url><loc>https://bbc.example/news/articles/new</loc><news:news><news:publication_date>2026-10-12T06:00:00+01:00</news:publication_date><news:title>New</news:title></news:news></url>
            </urlset>
            XML;

        $entries = (new NewsFeedParser())->parse(NewsSource::FORMAT_NEWS_SITEMAP, $xml, '/news/articles/');

        self::assertSame(['Old & gold', 'New'], array_map(static fn ($e) => $e->title, $entries), 'по убыванию времени, 05:00Z позже 05:00+01:00');
        self::assertSame('2026-10-12T05:00:00+00:00', $entries[1]->publishedAt?->format(\DATE_ATOM));
        self::assertCount(3, (new NewsFeedParser())->parse(NewsSource::FORMAT_NEWS_SITEMAP, $xml), 'без фильтра — все');
    }

    public function testRssInWindows1251IsDecoded(): void
    {
        $xml = mb_convert_encoding('<?xml version="1.0" encoding="windows-1251"?><rss><channel><item><title>Газета</title><link>https://g.example/1</link></item></channel></rss>', 'Windows-1251', 'UTF-8');

        self::assertSame('Газета', (new NewsFeedParser())->rss($xml)[0]->title);
    }

    public function testCnnLiteGivesAbsoluteLinksWithoutTime(): void
    {
        $html = <<<'HTML'
            <ul>
              <li class="card--lite">
                <a href="/2026/10/10/us/philadelphia-officer-killed">
                    Philadelphia police officer killed &amp; another injured
                </a>
            </li>
              <li class="card--lite">
                <a href="/2026/10/10/us/philadelphia-officer-killed">Повтор</a>
            </li>
              <li class="card--lite">
                <a href="/2026/10/09/world/old-story">Yesterday&#39;s story</a>
            </li></ul>
            HTML;

        $entries = (new NewsFeedParser())->parse(NewsSource::FORMAT_CNN_LITE, $html);

        self::assertCount(2, $entries);
        self::assertSame('Philadelphia police officer killed & another injured', $entries[0]->title);
        self::assertSame('https://edition.cnn.com/2026/10/10/us/philadelphia-officer-killed', $entries[0]->link);
        self::assertNull($entries[0]->publishedAt);
        self::assertSame("Yesterday's story", $entries[1]->title);
    }
}
