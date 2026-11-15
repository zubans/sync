<?php

namespace App\Repository;

use App\Entity\AiAgentAccount;
use App\Entity\AiProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiAgentAccount>
 */
final class AiAgentAccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiAgentAccount::class);
    }

    /** @return list<AiAgentAccount> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.provider', 'p')
            ->addSelect('p')
            ->orderBy('a.agentType', 'ASC')
            ->addOrderBy('a.priority', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AiAgentAccount> */
    public function findEnabledForProvider(AiProvider $provider): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.provider = :provider')
            ->andWhere('a.enabled = :enabled')
            ->setParameter('provider', $provider)
            ->setParameter('enabled', true)
            ->orderBy('a.priority', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTypeAndSlot(string $agentType, string $slot): ?AiAgentAccount
    {
        return $this->findOneBy(['agentType' => $agentType, 'slot' => trim($slot)]);
    }

    public function findByIdentifier(string $identifier): ?AiAgentAccount
    {
        $trimmed = trim($identifier);
        if (ctype_digit($trimmed)) {
            return $this->find((int) $trimmed);
        }
        if (str_contains($trimmed, ':')) {
            [$type, $slot] = explode(':', $trimmed, 2);

            return $this->findOneByTypeAndSlot($type, $slot);
        }

        return $this->findOneBy(['slot' => $trimmed], ['id' => 'ASC']);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
