<?php

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    public function revokeAllForUser(User $user): int
    {
        if ($user->getId() === null) {
            return 0;
        }

        return (int) $this->getEntityManager()
            ->createQuery('DELETE FROM App\Entity\ApiToken t WHERE t.user = :user')
            ->setParameter('user', $user)
            ->execute();
    }
}
