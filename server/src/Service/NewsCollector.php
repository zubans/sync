<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сбор новостей: обходит включённые издания, скачивает ленты и сохраняет новости без дублей —
 * уже известная новость находится по ключу и обновляется, если редакция её поправила.
 * Издания независимы: ошибка одного записывается в него и не мешает остальным.
 */
final class NewsCollector
{
    private const USER_AGENT = 'ContactSync-News/1.0';

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
        foreach ($this->sources->findEnabled() as $source) {
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
            $headers = ['User-Agent' => self::USER_AGENT];
            if ($source->getEtag() !== null) {
                $headers['If-None-Match'] = $source->getEtag();
            }
            if ($source->getLastModified() !== null) {
                $headers['If-Modified-Since'] = $source->getLastModified();
            }
            $response = $this->http->request('GET', $source->getUrl(), [
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
                throw new \RuntimeException("HTTP $status");
            }
            $entries = $this->parser->parse($source->getFormat(), $response->getContent());
            $responseHeaders = $response->getHeaders();
            [$new, $updated] = $this->save($source, $entries, $now);
            $source->markSuccess($now, $responseHeaders['etag'][0] ?? null, $responseHeaders['last-modified'][0] ?? null);
            $this->em->flush();

            return ['new' => $new, 'updated' => $updated, 'error' => null];
        } catch (\Throwable $e) {
            $source->markFailure($now, $e->getMessage());
            $this->em->flush();

            return ['new' => 0, 'updated' => 0, 'error' => $e->getMessage()];
        }
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
