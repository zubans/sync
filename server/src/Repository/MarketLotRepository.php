<?php

namespace App\Repository;

use App\Entity\MarketLot;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketLot>
 */
class MarketLotRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketLot::class);
    }

    /**
     * Покупки пользователя по порядку дат; $secid — только одного инструмента.
     *
     * @return list<MarketLot>
     */
    public function findByUser(User $user, ?string $secid = null): array
    {
        return $this->findBy(['user' => $user] + ($secid !== null ? ['secid' => $secid] : []), ['boughtOn' => 'ASC', 'id' => 'ASC']);
    }
}
