<?php

namespace App\Repository;

use App\Entity\AiProvider;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AiProvider>
 */
final class AiProviderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiProvider::class);
    }

    /** @return list<AiProvider> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.priority', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<AiProvider> */
    public function findEnabledForSummarization(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.enabled = :enabled')
            ->andWhere('p.useForSummarization = :sum')
            ->setParameter('enabled', true)
            ->setParameter('sum', true)
            ->orderBy('p.priority', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findFirstEnabledForEmbeddings(): ?AiProvider
    {
        return $this->createQueryBuilder('p')
            ->where('p.enabled = :enabled')
            ->andWhere('p.useForEmbeddings = :emb')
            ->setParameter('enabled', true)
            ->setParameter('emb', true)
            ->orderBy('p.priority', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findFirstByType(string $type): ?AiProvider
    {
        return $this->findOneBy(['type' => $type], ['priority' => 'ASC', 'id' => 'ASC']);
    }

    public function countAll(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
