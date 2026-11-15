<?php

namespace App\Repository;

use App\Entity\VpnServerService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VpnServerService>
 */
final class VpnServerServiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VpnServerService::class);
    }

    /** @return list<VpnServerService> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['listenPort' => 'ASC', 'id' => 'ASC']);
    }
}
