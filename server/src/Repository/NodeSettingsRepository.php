<?php

namespace App\Repository;

use App\Entity\NodeSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NodeSettings>
 */
final class NodeSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NodeSettings::class);
    }

    public function findCurrent(): ?NodeSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function getOrCreate(): NodeSettings
    {
        $settings = $this->findCurrent();
        if ($settings !== null) {
            return $settings;
        }
        $settings = new NodeSettings();
        $em = $this->getEntityManager();
        $em->persist($settings);
        $em->flush();

        return $settings;
    }
}
