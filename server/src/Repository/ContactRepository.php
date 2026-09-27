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

    /** @return list<Contact> */
    public function findPersonal(User $user): array
    {
        return $this->findBy(['user' => $user], ['name' => 'ASC', 'id' => 'ASC']);
    }

    /** @return list<Contact> */
    public function findByFamily(Family $family): array
    {
        return $this->findBy(['family' => $family], ['name' => 'ASC', 'id' => 'ASC']);
    }
}
