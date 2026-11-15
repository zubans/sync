<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Device>
 */
class DeviceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Device::class);
    }

    /** @return list<Device> */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['lastSyncAt' => 'DESC', 'id' => 'ASC']);
    }
}
