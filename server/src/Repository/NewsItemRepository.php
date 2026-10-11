<?php

namespace App\Repository;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
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
     * Лента: свежие сверху. Курсор — время и id последней показанной новости: время у новостей
     * совпадает, поэтому одного времени мало, чтобы не потерять и не повторить новость на стыке страниц.
     *
     * @param list<NewsSource> $sources пусто — все издания
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
        }
        if ($beforeAt !== null && $beforeId !== null) {
            $qb->andWhere('n.sortAt < :at OR (n.sortAt = :at AND n.id < :id)')
                ->setParameter('at', $beforeAt)
                ->setParameter('id', $beforeId);
        }

        return $qb->getQuery()->getResult();
    }
}
