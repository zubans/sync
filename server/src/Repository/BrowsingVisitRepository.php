<?php

namespace App\Repository;

use App\Entity\BrowsingVisit;
use App\Entity\Device;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<BrowsingVisit>
 */
class BrowsingVisitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BrowsingVisit::class);
    }

    /**
     * Уже сохранённые hash-и посещений устройства из присланного набора — чтобы не вставлять повторно.
     *
     * @param list<string> $hashes
     *
     * @return array<string, true>
     */
    public function existingHashes(Device $device, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $rows = $this->createQueryBuilder('v')
            ->select('v.dedupHash')
            ->where('v.device = :device')
            ->andWhere('v.dedupHash IN (:hashes)')
            ->setParameter('device', $device)
            ->setParameter('hashes', $hashes)
            ->getQuery()
            ->getSingleColumnResult();

        return array_fill_keys($rows, true);
    }
}
