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
}
