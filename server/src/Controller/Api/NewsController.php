<?php

namespace App\Controller\Api;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
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
            ], $this->sources->findEnabled()),
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
            $sources[$news->getId()] = ['source' => $news->getSource()->getCode(), 'title' => $news->getTitle(), 'link' => $news->getLink()];
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
            'title' => $item->getTitle(),
            'link' => $item->getLink(),
            'summary' => $item->getSummary(),
            'publishedAt' => self::time($item->getPublishedAt()),
            'firstSeenAt' => self::time($item->getFirstSeenAt()),
            // Время для подписи и сортировки: публикация, без неё — когда сервер увидел новость.
            'time' => self::time($item->getSortAt()),
        ];
    }

    /** Время из базы (UTC без пояса) в ISO 8601. */
    private static function time(?\DateTimeImmutable $value): ?string
    {
        return $value === null ? null : EventTime::fromDb($value)->format(\DATE_ATOM);
    }
}
