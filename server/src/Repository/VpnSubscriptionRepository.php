<?php

namespace App\Repository;

use App\Entity\VpnSubscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VpnSubscription>
 */
final class VpnSubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VpnSubscription::class);
    }

    /** @return list<VpnSubscription> */
    public function findOrdered(): array
    {
        return $this->findBy([], ['name' => 'ASC', 'id' => 'ASC']);
    }

    public function findOneByExternalId(?string $externalId): ?VpnSubscription
    {
        return $externalId === null || $externalId === '' ? null : $this->findOneBy(['externalId' => $externalId]);
    }
}
