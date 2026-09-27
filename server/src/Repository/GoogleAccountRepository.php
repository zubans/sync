<?php

namespace App\Repository;

use App\Entity\GoogleAccount;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GoogleAccount>
 */
class GoogleAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GoogleAccount::class);
    }

    /**
     * @return array<string, GoogleAccount> ключ — email
     */
    public function findByUserIndexed(User $user): array
    {
        return $this->createQueryBuilder('g', 'g.email')
            ->where('g.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }
}
