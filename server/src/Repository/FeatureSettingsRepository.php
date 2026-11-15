<?php

namespace App\Repository;

use App\Entity\FeatureSettings;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FeatureSettings>
 */
final class FeatureSettingsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FeatureSettings::class);
    }

    public function findCurrent(): ?FeatureSettings
    {
        return $this->findOneBy([], ['id' => 'ASC']);
    }

    public function getOrCreate(): FeatureSettings
    {
        $settings = $this->findCurrent();
        if ($settings !== null) {
            return $settings;
        }
        $settings = new FeatureSettings();
        $em = $this->getEntityManager();
        $em->persist($settings);
        $em->flush();

        return $settings;
    }
}
