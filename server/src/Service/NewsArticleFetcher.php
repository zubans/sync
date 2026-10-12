<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Полные тексты новостей: лента даёт заголовок и анонс, а статья целиком — на странице издания.
 * Скачивает страницы новостей, у которых полного текста ещё нет, и сохраняет текст без фото
 * ([NewsArticleExtractor]). У РБК полный текст приходит прямо в ленте — его не скачиваем.
 * Закрытые сайты (NYT отвечает 403) пробуются не больше трёх раз, дальше у новости остаётся анонс.
 */
final class NewsArticleFetcher
{
    /** Текст из ленты короче — это анонс, а не статья. */
    public const FULL_TEXT = 500;
    public const MAX_ATTEMPTS = 3;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ContactSync-News/1.0)';
    /** Пауза между запросами — не нагружать сайты изданий. */
    private const PAUSE_US = 300_000;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly NewsItemRepository $items,
        private readonly int $pauseUs = self::PAUSE_US,
    ) {
    }

    /** @return array{fetched: int, failed: int} */
    public function fetchMissing(int $limit): array
    {
        $fetched = $failed = 0;
        foreach ($this->items->findNeedingContent($limit, self::FULL_TEXT, self::MAX_ATTEMPTS) as $i => $item) {
            if ($i > 0 && $this->pauseUs > 0) {
                usleep($this->pauseUs);
            }
            $this->fetch($item) ? ++$fetched : ++$failed;
            $this->em->flush();
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }

    private function fetch(NewsItem $item): bool
    {
        try {
            $response = $this->http->request('GET', $item->getLink(), [
                'headers' => ['User-Agent' => self::USER_AGENT, 'Accept' => 'text/html'],
                'timeout' => 15,
                'max_duration' => 30,
            ]);
            $paragraphs = $response->getStatusCode() === 200 ? NewsArticleExtractor::extract($response->getContent()) : [];
        } catch (\Throwable) {
            $paragraphs = [];
        }
        if ($paragraphs === []) {
            $item->markContentAttempt();

            return false;
        }
        $item->setFetchedContent(implode("\n\n", self::withLead($paragraphs, (string) $item->getSummary())), new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return true;
    }

    /**
     * Вводный абзац у части изданий (РГ) свёрстан не абзацем и в текст не попадает — он же анонс в ленте.
     * Если текст начинается не с анонса и не содержит его, анонс ставится первым абзацем.
     *
     * @param list<string> $paragraphs
     *
     * @return list<string>
     */
    public static function withLead(array $paragraphs, string $summary): array
    {
        $summary = trim($summary);
        if ($summary === '') {
            return $paragraphs;
        }
        $probe = mb_substr($summary, 0, 40);
        foreach ($paragraphs as $paragraph) {
            if (str_contains($paragraph, $probe)) {
                return $paragraphs;
            }
        }

        return [$summary, ...$paragraphs];
    }
}
