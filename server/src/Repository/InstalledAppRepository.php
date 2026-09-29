<?php

namespace App\Repository;

use App\Entity\Device;
use App\Entity\InstalledApp;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InstalledApp>
 */
class InstalledAppRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InstalledApp::class);
    }

    /** @return array<string, InstalledApp> ключ — packageName */
    public function findByDeviceIndexed(Device $device): array
    {
        return $this->createQueryBuilder('a', 'a.packageName')
            ->where('a.device = :device')
            ->setParameter('device', $device)
            ->getQuery()
            ->getResult();
    }

    /** @return list<InstalledApp> все записи пользователя, свежие первыми */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['lastSeenAt' => 'DESC', 'id' => 'DESC']);
    }

    /** Был ли у пользователя установлен файл с таким хэшем — только тогда его можно скачать. */
    public function userHasFile(User $user, string $sha256): bool
    {
        foreach ($this->findBy(['user' => $user]) as $app) {
            if (\in_array($sha256, $app->getAllFileHashes(), true)) {
                return true;
            }
        }

        return false;
    }
}
