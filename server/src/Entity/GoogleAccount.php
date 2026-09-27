<?php

namespace App\Entity;

use App\Repository\GoogleAccountRepository;
use Doctrine\ORM\Mapping as ORM;

/** Google-аккаунт, найденный на устройстве пользователя. */
#[ORM\Entity(repositoryClass: GoogleAccountRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_google_account_user_email', columns: ['user_id', 'email'])]
class GoogleAccount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 255)]
    private string $email;

    /** Устройство, на котором аккаунт видели последним. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    public function __construct(User $user, string $email)
    {
        $this->user = $user;
        $this->email = $email;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    public function seenOn(Device $device): void
    {
        $this->device = $device;
        $this->lastSeenAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }
}
