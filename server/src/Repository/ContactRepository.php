<?php

namespace App\Repository;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * @param list<string> $uuids
     *
     * @return array<string, Contact> личные контакты пользователя по uuid
     */
    public function findPersonalByUuids(User $user, array $uuids): array
    {
        if ($uuids === []) {
            return [];
        }

        return $this->createQueryBuilder('c', 'c.uuid')
            ->where('c.user = :user')
            ->andWhere('c.uuid IN (:uuids)')
            ->setParameter('user', $user)
            ->setParameter('uuids', $uuids)
            ->getQuery()
            ->getResult();
    }

    /** @return list<Contact> контакты пользователя (включая общие с семьёй), кроме удалённых на устройствах */
    public function findPersonal(User $user): array
    {
        return $this->findBy(['user' => $user, 'deletedAt' => null], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Общие контакты семьи, которые пользователь получает от других: свои он и так получает как личные.
     * Учитываются только владельцы, которые сейчас состоят в этой семье.
     *
     * @return list<Contact>
     */
    public function findSharedForUser(User $user, Family $family): array
    {
        return $this->createQueryBuilder('c')
            ->join('c.user', 'owner')
            ->where('c.family = :family')
            ->andWhere('owner.family = :family')
            ->andWhere('owner != :user')
            ->andWhere('c.deletedAt IS NULL')
            ->setParameter('family', $family)
            ->setParameter('user', $user)
            ->orderBy('c.name', 'ASC')
            ->addOrderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return list<Contact> общие контакты семьи (всех её членов) */
    public function findByFamily(Family $family): array
    {
        return $this->findBy(['family' => $family, 'deletedAt' => null], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /** Есть ли у пользователя (лично или через семью) контакт с таким фото. */
    public function userCanSeePhoto(User $user, string $sha256): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.photoSha256 = :sha')
            ->andWhere($user->getFamily() === null ? 'c.user = :user' : '(c.user = :user OR c.family = :family)')
            ->setParameter('sha', $sha256)
            ->setParameter('user', $user);
        if ($user->getFamily() !== null) {
            $qb->setParameter('family', $user->getFamily());
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
