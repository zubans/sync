<?php

namespace App\Controller\Api;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Entity\NewsStoryline;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use App\Repository\NewsStorylineRepository;
use App\Service\EventTime;
use App\Service\NewsArticleFetcher;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Новости, собранные сервером (`app:news:collect`), — для виджета на главном экране планшета.
 * Лента листается курсором: `nextCursor` из ответа передаётся в `before` следующего запроса.
 */
#[Route('/api/news')]
final class NewsController extends AbstractController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;
    /** Издание, которое не удаётся собрать дольше этого, виджет помечает как недоступное. */
    private const STALE_AFTER = 'PT30M';

    public function __construct(
        private readonly NewsItemRepository $items,
        private readonly NewsSourceRepository $sources,
        private readonly EntityManagerInterface $em,
        private readonly NewsStorylineRepository $storylines,
    ) {
    }

    #[Route('', name: 'api_news', methods: ['GET'])]
    public function feed(
        #[MapQueryParameter] ?string $source = null,
        #[MapQueryParameter] ?int $limit = null,
        #[MapQueryParameter] ?string $before = null,
    ): JsonResponse {
        $selected = [];
        if ($source !== null && $source !== '') {
            $found = $this->sources->findOneBy(['code' => $source]);
            if ($found === null) {
                return $this->json(['error' => 'Издание не найдено.'], Response::HTTP_NOT_FOUND);
            }
            $selected = [$found];
        }
        $limit = max(1, min($limit ?? self::DEFAULT_LIMIT, self::MAX_LIMIT));
        [$beforeAt, $beforeId] = [null, null];
        if ($before !== null && $before !== '') {
            if (!preg_match('/^(\d+)\.(\d+)$/', $before, $m)) {
                return $this->json(['error' => 'Неверный курсор.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            [$beforeAt, $beforeId] = [(new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTimestamp((int) $m[1]), (int) $m[2]];
        }

        $items = $this->items->findFeed($selected, $limit, $beforeAt, $beforeId);
        $last = end($items) ?: null;
        $digests = $this->digestViews($items);

        return $this->json([
            'items' => array_map(static fn (NewsItem $i) => self::itemView($i) + (isset($digests[$i->getId()]) ? ['digest' => $digests[$i->getId()]] : []), $items),
            'nextCursor' => \count($items) === $limit && $last !== null
                ? EventTime::fromDb($last->getSortAt())->getTimestamp().'.'.$last->getId()
                : null,
        ]);
    }

    /**
     * Новость целиком — для чтения на планшете: полный текст абзацами. Нет полного текста
     * (сайт издания закрыт, как NYT) — `content` пустой и `fullText: false`, показывается анонс.
     */
    #[Route('/{id}', name: 'api_news_item', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function item(int $id): JsonResponse
    {
        $item = $this->items->find($id);
        if ($item === null) {
            return $this->json(['error' => 'Новость не найдена.'], Response::HTTP_NOT_FOUND);
        }
        $content = trim((string) $item->getContent());
        $paragraphs = $content === '' ? [] : array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/u', $content) ?: [])));
        $digest = $this->digestViews([$item]);

        return $this->json(self::itemView($item) + [
            'content' => $paragraphs,
            // Полный текст — скачанный со страницы или достаточно длинный из ленты (РБК), а не анонс.
            'fullText' => $item->getContentFetchedAt() !== null || mb_strlen($content) >= NewsArticleFetcher::FULL_TEXT || $item->getSource()->isDigest(),
        ] + (isset($digest[$id]) ? ['digest' => $digest[$id]] : []));
    }

    /**
     * Сюжетные линии за последние часы — экран «Сюжеты» на планшете: названные линии, где хотя бы
     * $minNews новостей, самые оживлённые за сутки первыми; у линии — сколько новостей каждого издания.
     */
    #[Route('/storylines', name: 'api_news_storylines', methods: ['GET'])]
    public function storylines(
        #[MapQueryParameter] ?int $hours = null,
        #[MapQueryParameter] ?int $minNews = null,
    ): JsonResponse {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $lines = $this->storylines->findForList($now->modify('-'.max(1, min($hours ?? 72, 24 * 30)).' hours'), max(2, $minNews ?? 3), 200);
        $stats = $this->storylineStats($lines, $now->modify('-24 hours'));
        $views = array_map(fn (NewsStoryline $l) => $this->storylineView($l, $stats[$l->getId()] ?? ['sources' => [], 'recent' => 0]), $lines);
        usort($views, static fn (array $a, array $b) => [$b['recent'], $b['newsCount']] <=> [$a['recent'], $a['newsCount']]);

        return $this->json(['storylines' => \array_slice($views, 0, 60)]);
    }

    /** Линия целиком: описание и все её новости, свежие первыми, — хроника истории. */
    #[Route('/storylines/{id}', name: 'api_news_storyline', requirements: ['id' => '\\d+'], methods: ['GET'])]
    public function storyline(int $id): JsonResponse
    {
        $line = $this->storylines->find($id);
        if ($line === null) {
            return $this->json(['error' => 'Сюжет не найден.'], Response::HTTP_NOT_FOUND);
        }
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $stats = $this->storylineStats([$line], $now->modify('-24 hours'));

        return $this->json($this->storylineView($line, $stats[$id] ?? ['sources' => [], 'recent' => 0]) + [
            'items' => array_map(self::itemView(...), $this->items->findByStoryline($line)),
        ]);
    }

    #[Route('/sources', name: 'api_news_sources', methods: ['GET'])]
    public function sources(): JsonResponse
    {
        $staleBefore = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->sub(new \DateInterval(self::STALE_AFTER));

        return $this->json([
            'sources' => array_map(static fn (NewsSource $s) => [
                'code' => $s->getCode(),
                'title' => $s->getTitle(),
                'lastSuccessAt' => self::time($s->getLastSuccessAt()),
                'lastError' => $s->getLastError(),
                // Ещё ни разу не собирали — не помечаем: сервер мог только что запуститься.
                // Сводка пишется раз в час, поэтому у неё признак — неудачная последняя попытка.
                'stale' => $s->getLastAttemptAt() !== null && ($s->isDigest()
                    ? $s->getLastError() !== null
                    : $s->getLastSuccessAt() === null || EventTime::fromDb($s->getLastSuccessAt()) < $staleBefore),
            ], $this->sources->findVisible()),
        ]);
    }

    /**
     * Сюжеты сводок со ссылками на исходные новости — одним запросом на страницу ленты.
     *
     * @param list<NewsItem> $items
     *
     * @return array<int, array<string, mixed>> по id новости-сводки
     */
    private function digestViews(array $items): array
    {
        $digestItems = array_filter($items, static fn (NewsItem $i) => $i->getSource()->isDigest());
        if ($digestItems === []) {
            return [];
        }
        /** @var list<NewsDigest> $digests */
        $digests = $this->em->getRepository(NewsDigest::class)->findBy(['item' => array_values($digestItems)]);
        $ids = [];
        foreach ($digests as $digest) {
            foreach ($digest->getStories() as $story) {
                array_push($ids, ...$story['items']);
            }
        }
        $sources = [];
        foreach ($ids === [] ? [] : $this->items->findBy(['id' => array_values(array_unique($ids))]) as $news) {
            // id — открыть новость с сервера; sourceTitle — у изданий «только для сводки» нет вкладки, где взять название.
            $sources[$news->getId()] = [
                'id' => $news->getId(),
                'source' => $news->getSource()->getCode(),
                'sourceTitle' => $news->getSource()->getTitle(),
                'title' => $news->getTitle(),
                'link' => $news->getLink(),
            ];
        }

        $views = [];
        foreach ($digests as $digest) {
            $views[$digest->getItem()->getId()] = [
                'period' => $digest->getPeriod(),
                'start' => self::time($digest->getPeriodStart()),
                'end' => self::time($digest->getPeriodEnd()),
                'newsCount' => $digest->getNewsCount(),
                'article' => $digest->getArticle(),
                'stories' => array_map(static fn (array $story) => [
                    'title' => $story['title'],
                    'text' => $story['text'],
                    'sources' => array_values(array_filter(array_map(static fn (int $id) => $sources[$id] ?? null, $story['items']))),
                ], $digest->getStories()),
            ];
        }

        return $views;
    }

    /** @return array<string, mixed> */
    private static function itemView(NewsItem $item): array
    {
        return [
            'id' => $item->getId(),
            'source' => $item->getSource()->getCode(),
            'sourceTitle' => $item->getSource()->getTitle(),
            'title' => $item->getTitle(),
            'link' => $item->getLink(),
            'summary' => $item->getSummary(),
            'publishedAt' => self::time($item->getPublishedAt()),
            'firstSeenAt' => self::time($item->getFirstSeenAt()),
            // Время для подписи и сортировки: публикация, без неё — когда сервер увидел новость.
            'time' => self::time($item->getSortAt()),
            // Сюжетная линия, если у неё уже есть название, — с экрана чтения можно открыть всю историю.
            'storyline' => $item->getStoryline()?->getTitle() !== null
                ? ['id' => $item->getStoryline()->getId(), 'title' => $item->getStoryline()->getTitle(), 'newsCount' => $item->getStoryline()->getNewsCount()]
                : null,
        ];
    }

    /**
     * Сколько новостей каждого издания в линиях (по названию издания: у изданий «только для сводки»
     * нет вкладки, где взять название) и сколько из них за последние сутки — одним запросом.
     *
     * @param list<NewsStoryline> $lines
     *
     * @return array<int, array{sources: array<string, int>, recent: int}>
     */
    private function storylineStats(array $lines, \DateTimeImmutable $recentSince): array
    {
        if ($lines === []) {
            return [];
        }
        $rows = $this->em->createQuery(
            'SELECT IDENTITY(n.storyline) AS line, s.title AS source, COUNT(n.id) AS total,
                SUM(CASE WHEN n.sortAt >= :recent THEN 1 ELSE 0 END) AS recent
             FROM App\Entity\NewsItem n JOIN n.source s
             WHERE n.storyline IN (:lines) GROUP BY n.storyline, s.title',
        )->setParameter('lines', $lines)->setParameter('recent', $recentSince)->getArrayResult();
        $stats = [];
        foreach ($rows as $row) {
            $id = (int) $row['line'];
            $stats[$id]['sources'][$row['source']] = (int) $row['total'];
            $stats[$id]['recent'] = ($stats[$id]['recent'] ?? 0) + (int) $row['recent'];
        }

        return $stats;
    }

    /**
     * @param array{sources: array<string, int>, recent: int} $stats
     *
     * @return array<string, mixed>
     */
    private function storylineView(NewsStoryline $line, array $stats): array
    {
        arsort($stats['sources']);

        return [
            'id' => $line->getId(),
            'title' => $line->getTitle(),
            'summary' => $line->getSummary(),
            'firstSeenAt' => self::time($line->getFirstSeenAt()),
            'lastSeenAt' => self::time($line->getLastSeenAt()),
            'newsCount' => $line->getNewsCount(),
            // Новостей за последние сутки — по ним видно, набирает история обороты или затихает.
            'recent' => $stats['recent'],
            'sources' => $stats['sources'],
        ];
    }

    /** Время из базы (UTC без пояса) в ISO 8601. */
    private static function time(?\DateTimeImmutable $value): ?string
    {
        return $value === null ? null : EventTime::fromDb($value)->format(\DATE_ATOM);
    }
}
