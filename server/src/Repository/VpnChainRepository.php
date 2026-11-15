<?php

namespace App\Repository;

use App\Entity\VpnChain;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VpnChain>
 */
final class VpnChainRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VpnChain::class);
    }

    /** @return list<VpnChain> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
