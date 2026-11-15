<?php

namespace App\Repository;

use App\Entity\VpnUser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VpnUser>
 */
final class VpnUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VpnUser::class);
    }

    /** @return list<VpnUser> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['username' => 'ASC', 'id' => 'ASC']);
    }
}
