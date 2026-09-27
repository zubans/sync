<?php

namespace App\Entity;

use App\Repository\InstalledAppRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Приложение, установленное на устройстве пользователя. */
#[ORM\Entity(repositoryClass: InstalledAppRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_installed_app_device_package', columns: ['device_id', 'package_name'])]
#[ORM\Index(name: 'idx_installed_app_user_package', columns: ['user_id', 'package_name'])]
class InstalledApp
{
    public const PLAY_STORE = 'com.android.vending';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Device $device;

    #[ORM\Column(length: 255)]
    private string $packageName;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $versionName = null;

    #[ORM\Column(type: Types::BIGINT)]
    private string $versionCode = '0';

    /** Пакет магазина, из которого установлено приложение. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $installer = null;

    /** SHA-256 сертификата подписи. */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $signingSha256 = null;

    /** @var list<array{name: string, sha256: string, size: int}> base и split APK */
    #[ORM\Column(type: Types::JSON)]
    private array $files = [];

    #[ORM\Column]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column]
    private \DateTimeImmutable $lastSeenAt;

    /** Когда приложение пропало с устройства; null — установлено. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $removedAt = null;

    public function __construct(Device $device, string $packageName)
    {
        $this->device = $device;
        $this->user = $device->getUser();
        $this->packageName = $packageName;
        $this->firstSeenAt = new \DateTimeImmutable();
        $this->lastSeenAt = $this->firstSeenAt;
    }

    /**
     * @param list<array{name: string, sha256: string, size: int}> $files
     */
    public function update(?string $label, ?string $versionName, int $versionCode, ?string $installer, ?string $signingSha256, array $files): void
    {
        $this->label = $label;
        $this->versionName = $versionName;
        $this->versionCode = (string) $versionCode;
        $this->installer = $installer;
        $this->signingSha256 = $signingSha256;
        $this->files = $files;
        $this->lastSeenAt = new \DateTimeImmutable();
        $this->removedAt = null;
    }

    public function markRemoved(): void
    {
        $this->removedAt ??= new \DateTimeImmutable();
    }

    public function isFromPlay(): bool
    {
        return $this->installer === self::PLAY_STORE;
    }

    /** @return list<string> */
    public function getFileHashes(): array
    {
        return array_column($this->files, 'sha256');
    }

    public function getTotalSize(): int
    {
        return array_sum(array_column($this->files, 'size'));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getVersionName(): ?string
    {
        return $this->versionName;
    }

    public function getVersionCode(): int
    {
        return (int) $this->versionCode;
    }

    public function getInstaller(): ?string
    {
        return $this->installer;
    }

    public function getSigningSha256(): ?string
    {
        return $this->signingSha256;
    }

    /** @return list<array{name: string, sha256: string, size: int}> */
    public function getFiles(): array
    {
        return $this->files;
    }

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function getRemovedAt(): ?\DateTimeImmutable
    {
        return $this->removedAt;
    }
}
