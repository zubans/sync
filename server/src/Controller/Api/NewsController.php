<?php

namespace App\Controller\Api;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use App\Service\EventTime;
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

        return $this->json([
            'items' => array_map(self::itemView(...), $items),
            'nextCursor' => \count($items) === $limit && $last !== null
                ? EventTime::fromDb($last->getSortAt())->getTimestamp().'.'.$last->getId()
                : null,
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
                'stale' => $s->getLastAttemptAt() !== null
                    && ($s->getLastSuccessAt() === null || EventTime::fromDb($s->getLastSuccessAt()) < $staleBefore),
            ], $this->sources->findEnabled()),
        ]);
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
