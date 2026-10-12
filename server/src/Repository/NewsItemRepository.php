<?php

namespace App\Repository;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Entity\NewsStoryline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsItem>
 */
class NewsItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsItem::class);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, NewsItem> уже сохранённые новости издания по ключу
     */
    public function findByKeys(NewsSource $source, array $keys): array
    {
        if ($keys === []) {
            return [];
        }
        $items = $this->createQueryBuilder('n')
            ->where('n.source = :source AND n.externalKey IN (:keys)')
            ->setParameter('source', $source)
            ->setParameter('keys', $keys)
            ->getQuery()
            ->getResult();

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item->getExternalKey()] = $item;
        }

        return $byKey;
    }

    /**
     * Новости, у которых ещё нет полного текста, свежие первыми: текст не скачан со страницы,
     * лента дала только анонс (короче [NewsArticleFetcher::FULL_TEXT]) и попытки не исчерпаны.
     *
     * @return list<NewsItem>
     */
    public function findNeedingContent(int $limit, int $fullText, int $maxAttempts): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('s.format <> :digest AND n.contentFetchedAt IS NULL AND n.contentAttempts < :attempts')
            ->andWhere('n.content IS NULL OR LENGTH(n.content) < :fullText')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->setParameter('attempts', $maxAttempts)
            ->setParameter('fullText', $fullText)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости без вектора — свежие первыми, без сводок: сюжетные линии нужны для актуальных новостей,
     * а архив досчитается следующими проходами.
     *
     * @return list<NewsItem>
     */
    public function findNeedingEmbedding(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('n.embedding IS NULL AND s.format <> :digest')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости с вектором, ещё не привязанные к сюжетной линии, — по порядку времени:
     * линия растёт так же, как шли события.
     *
     * @return list<NewsItem>
     */
    public function findUnassigned(int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->where('n.embedding IS NOT NULL AND n.storyline IS NULL AND s.format <> :digest')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости сюжетной линии, свежие первыми.
     *
     * @return list<NewsItem>
     */
    public function findByStoryline(NewsStoryline $storyline, int $limit = 500): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.storyline = :line')
            ->setParameter('line', $storyline)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Новости изданий (без сводок), которые сервер впервые увидел за период, — материал для сводки.
     * Время первого появления, а не публикации: так в сводку попадает и CNN, и новость,
     * опубликованная задним числом.
     *
     * @return list<NewsItem>
     */
    public function findSeenBetween(\DateTimeImmutable $start, \DateTimeImmutable $end, int $limit): array
    {
        return $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('n.firstSeenAt >= :start AND n.firstSeenAt < :end AND s.format <> :digest')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('n.sortAt', 'ASC')
            ->addOrderBy('n.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Лента: свежие сверху. Курсор — время и id последней показанной новости: время у новостей
     * совпадает, поэтому одного времени мало, чтобы не потерять и не повторить новость на стыке страниц.
     *
     * @param list<NewsSource> $sources пусто — все издания, кроме сводки
     *
     * @return list<NewsItem>
     */
    public function findFeed(array $sources, int $limit, ?\DateTimeImmutable $beforeAt = null, ?int $beforeId = null): array
    {
        $qb = $this->createQueryBuilder('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->orderBy('n.sortAt', 'DESC')
            ->addOrderBy('n.id', 'DESC')
            ->setMaxResults($limit);
        if ($sources !== []) {
            $qb->andWhere('n.source IN (:sources)')->setParameter('sources', $sources);
        } else {
            // «Все» — новости изданий из вкладок; сводки пересказывают их же и живут на своей вкладке,
            // а издания «только для сводки» в виджете не показываются.
            $qb->andWhere('s.format <> :digest AND s.showInWidget = true')->setParameter('digest', NewsSource::FORMAT_DIGEST);
        }
        if ($beforeAt !== null && $beforeId !== null) {
            $qb->andWhere('n.sortAt < :at OR (n.sortAt = :at AND n.id < :id)')
                ->setParameter('at', $beforeAt)
                ->setParameter('id', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }
}
