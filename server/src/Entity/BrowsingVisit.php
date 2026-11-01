<?php

namespace App\Entity;

use App\Repository\BrowsingVisitRepository;
use Doctrine\ORM\Mapping as ORM;

/** Посещение страницы в браузере, присланное клиентом с устройства пользователя. */
#[ORM\Entity(repositoryClass: BrowsingVisitRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_browsing_visit_device_dedup', columns: ['device_id', 'dedup_hash'])]
class BrowsingVisit
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Устройство, с которого пришло посещение. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?Device $device = null;

    #[ORM\Column(type: 'text')]
    private string $url;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $title = null;

    /** Пакет браузера, например com.android.chrome. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $browser = null;

    #[ORM\Column]
    private \DateTimeImmutable $visitedAt;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** SHA-256 от url и времени посещения — чтобы повторная выгрузка не плодила дубли. */
    #[ORM\Column(length: 64)]
    private string $dedupHash;

    public function __construct(User $user, ?Device $device, string $url, \DateTimeImmutable $visitedAt)
    {
        $this->user = $user;
        $this->device = $device;
        $this->url = $url;
        $this->visitedAt = $visitedAt;
        $this->createdAt = new \DateTimeImmutable();
        $this->dedupHash = self::hash($url, $visitedAt);
    }

    public static function hash(string $url, \DateTimeImmutable $visitedAt): string
    {
        return hash('sha256', $url."\n".$visitedAt->getTimestamp());
    }

    public function setTitle(?string $title): static
    {
        $this->title = $title !== null ? mb_substr($title, 0, 500) : null;

        return $this;
    }

    public function setBrowser(?string $browser): static
    {
        $this->browser = $browser;

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function getBrowser(): ?string
    {
        return $this->browser;
    }

    public function getVisitedAt(): \DateTimeImmutable
    {
        return $this->visitedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
