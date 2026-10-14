<?php

namespace App\Repository;

use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsMarketImpact>
 */
class NewsMarketImpactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsMarketImpact::class);
    }

    /**
     * Последствия, замер которых ещё не окончательный, — вместе с новостями: по времени новости ищется цена до неё.
     *
     * @return list<NewsMarketImpact>
     */
    public function findUnsettled(int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.item', 'n')
            ->addSelect('n')
            ->where('m.settled = false')
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Последствия новостей страницы — одним запросом.
     *
     * @param list<NewsItem> $items
     *
     * @return array<int, list<NewsMarketImpact>> по id новости
     */
    public function findByItems(array $items): array
    {
        if ($items === []) {
            return [];
        }
        $byItem = [];
        foreach ($this->findBy(['item' => $items], ['id' => 'ASC']) as $impact) {
            $byItem[$impact->getItem()->getId()][] = $impact;
        }

        return $byItem;
    }

    /**
     * Последствия новостей для инструмента с даты — вместе с новостями, свежие первыми.
     *
     * @return list<NewsMarketImpact>
     */
    public function findForSecid(string $secid, \DateTimeImmutable $since, int $limit): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.item', 'n')
            ->addSelect('n')
            ->join('n.source', 's')
            ->addSelect('s')
            ->where('m.secid = :secid AND n.sortAt >= :since')
            ->setParameter('secid', $secid)
            ->setParameter('since', $since)
            ->orderBy('n.sortAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** Когда у инструмента появилось последнее последствие; null — ни одного. */
    public function lastCreatedAt(string $secid): ?\DateTimeImmutable
    {
        $value = $this->createQueryBuilder('m')
            ->select('MAX(m.createdAt)')
            ->where('m.secid = :secid')
            ->setParameter('secid', $secid)
            ->getQuery()
            ->getSingleScalarResult();

        return \is_string($value) ? new \DateTimeImmutable($value, new \DateTimeZone('UTC')) : null;
    }
}
