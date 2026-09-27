<?php

namespace App\Entity;

use App\Repository\ContactTombstoneRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * След личного контакта, удалённого администратором. По нему устройства узнают,
 * что контакт нужно убрать из телефонной книги, а не загрузить на сервер заново.
 */
#[ORM\Entity(repositoryClass: ContactTombstoneRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_contact_tombstone_user_uuid', columns: ['user_id', 'uuid'])]
class ContactTombstone
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 36)]
    private string $uuid;

    #[ORM\Column]
    private \DateTimeImmutable $deletedAt;

    public function __construct(User $user, string $uuid)
    {
        $this->user = $user;
        $this->uuid = $uuid;
        $this->deletedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getUuid(): string
    {
        return $this->uuid;
    }

    public function getDeletedAt(): \DateTimeImmutable
    {
        return $this->deletedAt;
    }
}
