<?php

namespace App\Repository;

use App\Entity\ContactTombstone;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactTombstone>
 */
class ContactTombstoneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactTombstone::class);
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, true> какие из uuid удалены администратором
     */
    public function findDeletedUuids(User $user, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('t')
            ->select('t.uuid')
            ->where('t.user = :user')
            ->andWhere('t.uuid IN (:uuids)')
            ->setParameter('user', $user)
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($rows, true);
    }
}
