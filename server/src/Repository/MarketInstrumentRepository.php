<?php

namespace App\Repository;

use App\Entity\MarketInstrument;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MarketInstrument>
 */
class MarketInstrumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MarketInstrument::class);
    }

    /** @return list<MarketInstrument> в порядке виджета */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['position' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Инструменты всех пользователей — кандидаты на анализ: сначала первые в виджете у каждого пользователя,
     * потом вторые и так далее, чтобы длинный список одного не задерживал анализ у остальных.
     *
     * @return list<MarketInstrument>
     */
    public function findAllWithUsers(): array
    {
        return $this->createQueryBuilder('i')
            ->join('i.user', 'u')
            ->addSelect('u')
            ->orderBy('i.position', 'ASC')
            ->addOrderBy('u.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
