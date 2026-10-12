<?php

namespace App\Repository;

use App\Entity\NewsStoryline;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsStoryline>
 */
class NewsStorylineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsStoryline::class);
    }

    /** @return list<NewsStoryline> линии с новостями после $since — к ним можно присоединять новые */
    public function findActiveSince(\DateTimeImmutable $since): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.lastSeenAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getResult();
    }

    /** @return list<NewsStoryline> линии, которым пора дать название, самые крупные первыми */
    public function findNeedingName(\DateTimeImmutable $since, int $limit): array
    {
        $lines = $this->createQueryBuilder('l')
            ->where('l.lastSeenAt >= :since AND l.newsCount >= 2')
            ->setParameter('since', $since)
            ->orderBy('l.newsCount', 'DESC')
            ->getQuery()
            ->getResult();

        return \array_slice(array_values(array_filter($lines, static fn (NewsStoryline $l) => $l->needsName())), 0, $limit);
    }

    /**
     * Линии для экрана «Сюжеты»: названные, с новостями после $since, крупные первыми.
     *
     * @return list<NewsStoryline>
     */
    public function findForList(\DateTimeImmutable $since, int $minNews, int $limit): array
    {
        return $this->createQueryBuilder('l')
            ->where('l.lastSeenAt >= :since AND l.newsCount >= :min AND l.title IS NOT NULL')
            ->setParameter('since', $since)
            ->setParameter('min', $minNews)
            ->orderBy('l.lastSeenAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
