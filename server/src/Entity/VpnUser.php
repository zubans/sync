<?php

namespace App\Entity;

use App\Repository\VpnUserRepository;
use App\Vpn\VpnProtocol;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Пользователь VPN-узла (и при необходимости системный пользователь панели sync).
 * Создание и управление доступны только когда узел переведён в режим «Сервер».
 */
#[ORM\Entity(repositoryClass: VpnUserRepository::class)]
#[ORM\Table(name: 'vpn_user')]
class VpnUser
{
    public const ROLE_USER = 'ROLE_USER';

    public const SYSTEM_ROLES = [
        'Обычный пользователь (ROLE_USER)' => self::ROLE_USER,
        'Администратор узла (ROLE_ADMIN)' => User::ROLE_ADMIN,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $username = '';

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 120, unique: true)]
    private string $clientId;

    #[ORM\Column(length: 255)]
    private string $clientSecret;

    #[ORM\Column(length: 64)]
    private string $vlessUuid;

    #[ORM\Column(length: 255)]
    private string $tunnelPassword;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $staticVpnIp = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $grantSystemAccess = false;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $systemEmail = null;

    #[ORM\Column(length: 32, options: ['default' => self::ROLE_USER])]
    private string $systemRole = self::ROLE_USER;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'system_user_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?User $systemUser = null;

    /** @var Collection<int, VpnServerService> */
    #[ORM\ManyToMany(targetEntity: VpnServerService::class)]
    #[ORM\JoinTable(name: 'vpn_user_service')]
    #[ORM\JoinColumn(name: 'vpn_user_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'vpn_server_service_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    private Collection $allowedServices;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->clientId = 'user-' . VpnProtocol::generateSecret(6);
        $this->clientSecret = VpnProtocol::generateSecret(16);
        $this->vlessUuid = VpnProtocol::generateUuidV4();
        $this->tunnelPassword = VpnProtocol::generateSecret(12);
        $this->allowedServices = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return $this->displayName !== null && $this->displayName !== ''
            ? sprintf('%s (%s)', $this->displayName, $this->username)
            : ($this->username !== '' ? $this->username : $this->clientId);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): void
    {
        $this->username = trim($username);
        $this->touch();
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): void
    {
        $trimmed = $displayName !== null ? trim($displayName) : '';
        $this->displayName = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getClientId(): string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): void
    {
        $trimmed = $clientId !== null ? trim($clientId) : '';
        $this->clientId = $trimmed !== '' ? $trimmed : ('user-' . VpnProtocol::generateSecret(6));
        $this->touch();
    }

    public function getClientSecret(): string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): void
    {
        $this->clientSecret = ($clientSecret !== null && $clientSecret !== '') ? $clientSecret : VpnProtocol::generateSecret(16);
        $this->touch();
    }

    public function getVlessUuid(): string
    {
        return $this->vlessUuid;
    }

    public function setVlessUuid(?string $vlessUuid): void
    {
        $trimmed = $vlessUuid !== null ? trim($vlessUuid) : '';
        $this->vlessUuid = $trimmed !== '' ? $trimmed : VpnProtocol::generateUuidV4();
        $this->touch();
    }

    public function getTunnelPassword(): string
    {
        return $this->tunnelPassword;
    }

    public function setTunnelPassword(?string $tunnelPassword): void
    {
        $this->tunnelPassword = ($tunnelPassword !== null && $tunnelPassword !== '') ? $tunnelPassword : VpnProtocol::generateSecret(12);
        $this->touch();
    }

    public function getStaticVpnIp(): ?string
    {
        return $this->staticVpnIp;
    }

    public function setStaticVpnIp(?string $staticVpnIp): void
    {
        $trimmed = $staticVpnIp !== null ? trim($staticVpnIp) : '';
        $this->staticVpnIp = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function isGrantSystemAccess(): bool
    {
        return $this->grantSystemAccess;
    }

    public function setGrantSystemAccess(bool $grantSystemAccess): void
    {
        $this->grantSystemAccess = $grantSystemAccess;
        $this->touch();
    }

    public function getSystemEmail(): ?string
    {
        return $this->systemEmail;
    }

    public function setSystemEmail(?string $systemEmail): void
    {
        $trimmed = $systemEmail !== null ? trim($systemEmail) : '';
        $this->systemEmail = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getSystemRole(): string
    {
        return $this->systemRole;
    }

    public function setSystemRole(string $systemRole): void
    {
        $this->systemRole = in_array($systemRole, [self::ROLE_USER, User::ROLE_ADMIN], true)
            ? $systemRole
            : self::ROLE_USER;
        $this->touch();
    }

    public function getSystemUser(): ?User
    {
        return $this->systemUser;
    }

    public function setSystemUser(?User $systemUser): void
    {
        $this->systemUser = $systemUser;
        $this->touch();
    }

    /** @return Collection<int, VpnServerService> */
    public function getAllowedServices(): Collection
    {
        return $this->allowedServices;
    }

    public function addAllowedService(VpnServerService $service): void
    {
        if (!$this->allowedServices->contains($service)) {
            $this->allowedServices->add($service);
            $this->touch();
        }
    }

    public function removeAllowedService(VpnServerService $service): void
    {
        if ($this->allowedServices->removeElement($service)) {
            $this->touch();
        }
    }

    public function getEffectiveSystemEmail(): string
    {
        if ($this->systemEmail !== null && $this->systemEmail !== '') {
            return $this->systemEmail;
        }
        $safeLocal = preg_replace('/[^a-zA-Z0-9._+-]/', '-', strtolower($this->username)) ?: 'user';

        return str_contains($safeLocal, '@') ? $safeLocal : $safeLocal . '@vpn.local';
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $trimmed = $notes !== null ? trim($notes) : '';
        $this->notes = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
