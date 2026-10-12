<?php

namespace App\Repository;

use App\Entity\NewsSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsSource>
 */
class NewsSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsSource::class);
    }

    /** @return list<NewsSource> */
    public function findEnabled(): array
    {
        return $this->findBy(['enabled' => true], ['id' => 'ASC']);
    }

    /** @return list<NewsSource> вкладки виджета: включённые издания, кроме тех, что идут только в сводку */
    public function findVisible(): array
    {
        return $this->findBy(['enabled' => true, 'showInWidget' => true], ['id' => 'ASC']);
    }

    /** @return list<NewsSource> включённые издания с лентой — без сводки, её не скачивают */
    public function findCollectable(): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.enabled = true AND s.format <> :digest')
            ->setParameter('digest', NewsSource::FORMAT_DIGEST)
            ->orderBy('s.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findDigest(): ?NewsSource
    {
        return $this->findOneBy(['format' => NewsSource::FORMAT_DIGEST]);
    }
}
