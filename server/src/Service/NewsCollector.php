<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Сбор новостей: обходит включённые издания, скачивает ленты и сохраняет новости без дублей —
 * уже известная новость находится по ключу и обновляется, если редакция её поправила.
 * Издания независимы: ошибка одного записывается в него и не мешает остальным.
 */
final class NewsCollector
{
    private const USER_AGENT = 'ContactSync-News/1.0';
    /** Сколько ждать следующую часть новостной карты сайта, прежде чем взять пришедшее. */
    private const SITEMAP_IDLE = 15;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly NewsSourceRepository $sources,
        private readonly NewsItemRepository $items,
        private readonly NewsFeedParser $parser,
    ) {
    }

    /**
     * @return array<string, array{new: int, updated: int, error: ?string}> итог по коду издания
     */
    public function collectAll(): array
    {
        $report = [];
        foreach ($this->sources->findCollectable() as $source) {
            $report[$source->getCode()] = $this->collect($source);
        }

        return $report;
    }

    /** @return array{new: int, updated: int, error: ?string} */
    public function collect(NewsSource $source): array
    {
        // В базе время хранится в UTC без пояса, как у событий календаря (см. EventTime).
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        try {
            // У издания из нескольких лент (Газета.Ru — разделы) кэш-заголовки не ведём: они одни на издание.
            $urls = $source->getUrls();
            $single = \count($urls) === 1;
            $entries = [];
            $etag = $lastModified = null;
            foreach ($urls as $url) {
                $headers = ['User-Agent' => self::USER_AGENT];
                if ($single && $source->getEtag() !== null) {
                    $headers['If-None-Match'] = $source->getEtag();
                }
                if ($single && $source->getLastModified() !== null) {
                    $headers['If-Modified-Since'] = $source->getLastModified();
                }
                // Часть после # — фильтр ссылок (см. NewsFeedParser::parse), на сайт она не уходит.
                $filter = parse_url($url, \PHP_URL_FRAGMENT) ?: null;
                $url = explode('#', $url, 2)[0];
                $response = $this->http->request('GET', $url, [
                    'headers' => $headers,
                    'timeout' => 20,
                    'max_duration' => 40,
                ]);
                $status = $response->getStatusCode();
                if ($status === 304) {
                    $source->markSuccess($now, $source->getEtag(), $source->getLastModified());
                    $this->em->flush();

                    return ['new' => 0, 'updated' => 0, 'error' => null];
                }
                if ($status !== 200) {
                    throw new \RuntimeException("HTTP $status".($single ? '' : " ($url)"));
                }
                if ($single) {
                    $responseHeaders = $response->getHeaders();
                    $etag = $responseHeaders['etag'][0] ?? null;
                    $lastModified = $responseHeaders['last-modified'][0] ?? null;
                }
                array_push($entries, ...$this->parser->parse($source->getFormat(), $this->body($response, $source->getFormat()), $filter));
            }
            [$new, $updated] = $this->save($source, $entries, $now);
            $source->markSuccess($now, $etag, $lastModified);
            $this->em->flush();

            return ['new' => $new, 'updated' => $updated, 'error' => null];
        } catch (\Throwable $e) {
            $source->markFailure($now, $e->getMessage());
            $this->em->flush();

            return ['new' => 0, 'updated' => 0, 'error' => $e->getMessage()];
        }
    }

    /**
     * Тело ответа. Новостную карту сайта читаем потоком и берём то, что успело прийти: «Аль-Баян»
     * и The Times отдают начало карты (самые свежие новости) и замолкают, не закрывая соединение.
     * Карта разбирается в режиме восстановления (NewsFeedParser::newsSitemap).
     */
    private function body(ResponseInterface $response, string $format): string
    {
        if ($format !== NewsSource::FORMAT_NEWS_SITEMAP) {
            return $response->getContent();
        }
        $body = '';
        try {
            foreach ($this->http->stream($response, self::SITEMAP_IDLE) as $chunk) {
                if ($chunk->isTimeout()) {
                    break;
                }
                $body .= $chunk->getContent();
            }
        } catch (TransportExceptionInterface $e) {
            if ($body === '') {
                throw $e;
            }
        }
        $response->cancel();
        if ($body === '') {
            throw new \RuntimeException('Карта сайта не пришла');
        }

        return $body;
    }

    /**
     * @param list<NewsEntry> $entries
     *
     * @return array{int, int} сколько новостей добавлено и обновлено
     */
    private function save(NewsSource $source, array $entries, \DateTimeImmutable $now): array
    {
        $byKey = [];
        foreach ($entries as $entry) {
            // Одна новость дважды в ленте — берём первое упоминание.
            $byKey[sha1($entry->key)] ??= $entry;
        }
        $existing = $this->items->findByKeys($source, array_map('strval', array_keys($byKey)));

        $new = $updated = 0;
        foreach ($byKey as $key => $entry) {
            $key = (string) $key;
            $item = $existing[$key] ?? null;
            if ($item === null) {
                $item = new NewsItem($source, $key, $now);
                $this->em->persist($item);
                ++$new;
            }
            $changed = $item->apply($entry->link, $entry->title, $entry->summary, $entry->content, $entry->publishedAt, $now);
            if ($changed && isset($existing[$key])) {
                ++$updated;
            }
        }

        return [$new, $updated];
    }
}
