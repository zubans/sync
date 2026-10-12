<?php

namespace App\Repository;

use App\Entity\Calendar;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Calendar>
 */
class CalendarRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Calendar::class);
    }

    /** @return list<Calendar> свои календари, семейные календари других членов семьи и те, которыми с пользователем поделились */
    public function findVisibleTo(User $user): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.owner', 'o')
            ->where('c.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('c.id', 'ASC');
        if ($user->getFamily() !== null) {
            // Поделиться можно только внутри семьи: вышел из семьи — доступ к календарю пропал, даже если он в списке.
            $qb->orWhere('o.family = :family AND (c.familyShared = true OR :user MEMBER OF c.sharedWith)')
                ->setParameter('family', $user->getFamily());
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUuid(string $uuid): ?Calendar
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
