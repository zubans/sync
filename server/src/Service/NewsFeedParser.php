<?php

namespace App\Service;

use App\Entity\NewsSource;

/**
 * Разбор лент изданий. RSS 2.0 читается DOM-парсером без сети и без DTD: лента — чужие данные,
 * внешние сущности в ней не нужны. Текстовая версия CNN разбирается по ссылкам `card--lite`:
 * разметка там простая и устойчивая.
 */
final class NewsFeedParser
{
    /** Больше новостей из одной ленты не берём: издания и так отдают 15–30 последних. */
    private const LIMIT = 100;
    private const SUMMARY_LIMIT = 1000;
    private const CNN_BASE = 'https://edition.cnn.com';

    /**
     * @return list<NewsEntry>
     *
     * @throws \InvalidArgumentException лента не разбирается
     */
    public function parse(string $format, string $body): array
    {
        return match ($format) {
            NewsSource::FORMAT_RSS => $this->rss($body),
            NewsSource::FORMAT_CNN_LITE => $this->cnnLite($body),
            default => throw new \InvalidArgumentException("Неизвестный формат ленты: $format"),
        };
    }

    /** @return list<NewsEntry> */
    public function rss(string $xml): array
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            throw new \InvalidArgumentException('Лента не разбирается как XML');
        }
        if ($doc->doctype !== null) {
            throw new \InvalidArgumentException('Лента с DTD не принимается');
        }

        $entries = [];
        foreach ($doc->getElementsByTagName('item') as $item) {
            $title = self::clean(self::child($item, 'title'));
            $link = trim((string) self::child($item, 'link'));
            if ($title === '' || $link === '') {
                continue;
            }
            $guid = trim((string) self::child($item, 'guid'));
            $summary = self::clean(self::child($item, 'description'));
            // Полный текст: content:encoded по стандарту, у РБК — rbc_news:full-text.
            $content = self::clean(self::childAnyNs($item, 'encoded') ?? self::childAnyNs($item, 'full-text'));
            $entries[] = new NewsEntry(
                key: $guid !== '' ? $guid : $link,
                link: $link,
                title: $title,
                summary: $summary !== '' ? mb_substr($summary, 0, self::SUMMARY_LIMIT) : null,
                content: $content !== '' ? $content : null,
                publishedAt: self::date(self::child($item, 'pubDate')),
            );
            if (\count($entries) >= self::LIMIT) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Заголовки lite.cnn.com: ссылки вида /2026/10/10/us/…; времени публикации страница не показывает.
     *
     * @return list<NewsEntry>
     */
    public function cnnLite(string $html): array
    {
        preg_match_all('~<li class="card--lite">\s*<a href="(/[^"]+)">\s*([^<]+?)\s*</a>~', $html, $matches, PREG_SET_ORDER);
        $entries = [];
        foreach ($matches as [, $path, $text]) {
            $link = self::CNN_BASE.$path;
            $title = self::clean($text);
            if ($title === '' || isset($entries[$link])) {
                continue;
            }
            $entries[$link] = new NewsEntry(key: $link, link: $link, title: $title);
            if (\count($entries) >= self::LIMIT) {
                break;
            }
        }

        return array_values($entries);
    }

    private static function child(\DOMElement $item, string $tag): ?string
    {
        foreach ($item->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->nodeName === $tag) {
                return $node->textContent;
            }
        }

        return null;
    }

    private static function childAnyNs(\DOMElement $item, string $localName): ?string
    {
        foreach ($item->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $localName && $node->namespaceURI !== null) {
                return $node->textContent;
            }
        }

        return null;
    }

    /** Текст без HTML и лишних пробелов; сущности (&laquo;, &#8216;) раскрыты. */
    private static function clean(?string $text): string
    {
        if ($text === null) {
            return '';
        }
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], ' ', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Дата RSS (RFC 2822) в UTC; неразборчивая — null, новость всё равно сохраняется. */
    private static function date(?string $text): ?\DateTimeImmutable
    {
        $text = trim((string) $text);
        if ($text === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DATE_RSS, $text)
            ?: \DateTimeImmutable::createFromFormat('D, d M Y H:i:s T', $text);
        if ($date === false) {
            try {
                $date = new \DateTimeImmutable($text);
            } catch (\Exception) {
                return null;
            }
        }

        return $date->setTimezone(new \DateTimeZone('UTC'));
    }
}
