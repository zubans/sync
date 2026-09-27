<?php

namespace App\Repository;

use App\Entity\ContactLink;
use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactLink>
 */
class ContactLinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactLink::class);
    }

    /**
     * @return array<string, ContactLink> связи устройства, ключ — externalId
     */
    public function findByDeviceIndexed(Device $device): array
    {
        return $this->createQueryBuilder('l', 'l.externalId')
            ->addSelect('c')
            ->join('l.contact', 'c')
            ->where('l.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->getResult();
    }
}
