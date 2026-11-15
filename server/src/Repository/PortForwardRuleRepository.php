<?php

namespace App\Repository;

use App\Entity\PortForwardRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PortForwardRule>
 */
final class PortForwardRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PortForwardRule::class);
    }

    /** @return list<PortForwardRule> */
    public function findEnabledOrdered(): array
    {
        return $this->findBy(['enabled' => true], ['listenPort' => 'ASC', 'id' => 'ASC']);
    }
}
