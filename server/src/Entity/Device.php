<?php

namespace App\Entity;

use App\Repository\DeviceRepository;
use Doctrine\ORM\Mapping as ORM;

/** Установка приложения, через которую пользователь синхронизируется. */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_device_user_install', columns: ['user_id', 'install_id'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Случайный идентификатор, который приложение генерирует при установке. */
    #[ORM\Column(length: 64)]
    private string $installId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $model = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    public function __construct(User $user, string $installId)
    {
        $this->user = $user;
        $this->installId = $installId;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return ($this->model ?? 'Устройство').' ('.substr($this->installId, 0, 8).')';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getInstallId(): string
    {
        return $this->installId;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): static
    {
        $this->model = $model;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastSyncAt(): ?\DateTimeImmutable
    {
        return $this->lastSyncAt;
    }

    public function markSynced(): void
    {
        $this->lastSyncAt = new \DateTimeImmutable();
    }
}
