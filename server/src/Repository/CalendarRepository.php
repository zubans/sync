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

    /** @return list<Calendar> свои календари и семейные календари других членов семьи */
    public function findVisibleTo(User $user): array
    {
        $qb = $this->createQueryBuilder('c')
            ->join('c.owner', 'o')
            ->where('c.owner = :user')
            ->setParameter('user', $user)
            ->orderBy('c.id', 'ASC');
        if ($user->getFamily() !== null) {
            $qb->orWhere('c.familyShared = true AND o.family = :family')->setParameter('family', $user->getFamily());
        }

        return $qb->getQuery()->getResult();
    }

    public function findOneByUuid(string $uuid): ?Calendar
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }
}
