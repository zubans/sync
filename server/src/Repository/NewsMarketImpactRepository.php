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
}
