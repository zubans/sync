<?php

namespace App\Repository;

use App\Entity\VpnConnection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VpnConnection>
 */
final class VpnConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VpnConnection::class);
    }

    /** @return list<VpnConnection> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
