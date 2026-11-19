<?php

namespace App\Repository;

use App\Entity\RelayRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RelayRoute>
 */
final class RelayRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RelayRoute::class);
    }

    /** @return list<RelayRoute> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['listenPort' => 'ASC', 'id' => 'ASC']);
    }
}
