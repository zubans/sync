<?php

namespace App\Repository;

use App\Entity\Vault;
use App\Entity\VaultItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<VaultItem>
 */
class VaultItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, VaultItem::class);
    }

    /** @return list<VaultItem> записи, изменённые после ревизии $since */
    public function findChangedSince(Vault $vault, int $since): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.vault = :vault')
            ->andWhere('i.revision > :since')
            ->setParameter('vault', $vault)
            ->setParameter('since', $since)
            ->orderBy('i.revision', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, VaultItem>
     */
    public function findByUuids(Vault $vault, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return $this->createQueryBuilder('i', 'i.uuid')
            ->where('i.vault = :vault')
            ->andWhere('i.uuid IN (:uuids)')
            ->setParameter('vault', $vault)
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getResult();
    }

    public function countAlive(Vault $vault): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.vault = :vault')
            ->andWhere('i.deleted = false')
            ->setParameter('vault', $vault)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
