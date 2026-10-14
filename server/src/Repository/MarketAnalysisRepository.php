<?php

namespace App\Repository;

use App\Entity\MarketAnalysis;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketAnalysis>
 */
class MarketAnalysisRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketAnalysis::class);
    }

    /** @return array<string, MarketAnalysis> по коду инструмента */
    public function findByUser(User $user): array
    {
        $bySecid = [];
        foreach ($this->findBy(['user' => $user]) as $analysis) {
            $bySecid[$analysis->getSecid()] = $analysis;
        }

        return $bySecid;
    }

    public function findOrCreate(User $user, string $secid): MarketAnalysis
    {
        $analysis = $this->findOneBy(['user' => $user, 'secid' => $secid]);
        if ($analysis === null) {
            $analysis = new MarketAnalysis($user, $secid);
            $this->getEntityManager()->persist($analysis);
        }

        return $analysis;
    }
}
