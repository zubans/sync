<?php

namespace App\Repository;

use App\Entity\AppRelease;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppRelease>
 */
final class AppReleaseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppRelease::class);
    }

    public function findActive(): ?AppRelease
    {
        return $this->findOneBy(['active' => true]);
    }

    /** @return list<AppRelease> от новых к старым */
    public function findNewestFirst(): array
    {
        return $this->findBy([], ['versionCode' => 'DESC']);
    }
}
