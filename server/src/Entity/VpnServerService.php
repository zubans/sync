<?php

namespace App\Entity;

use App\Repository\VpnServerServiceRepository;
use App\Vpn\VpnProtocol;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Серверный VPN/транзитный демон, запускаемый на этом узле в режиме «Сервер».
 */
#[ORM\Entity(repositoryClass: VpnServerServiceRepository::class)]
#[ORM\Table(name: 'vpn_server_service')]
class VpnServerService
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $proto = VpnProtocol::ARGON4;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 64, options: ['default' => '0.0.0.0'])]
    private string $listenHost = '0.0.0.0';

    #[ORM\Column(options: ['default' => 51820])]
    private int $listenPort = 51820;

    #[ORM\Column(length: 8, options: ['default' => 'udp'])]
    private string $transportProto = 'udp';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicHostOverride = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $subnetCidr = '10.44.0.1/24';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $masterPassword = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $transitBlind = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $transitTarget = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $transitAllow = null;

    #[ORM\Column(options: ['default' => 5])]
    private int $argonProtocolVersion = 5;

    #[ORM\Column(options: ['default' => false])]
    private bool $argonDisallowV4 = false;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $argonBehaviorProfile = null;

    #[ORM\Column(length: 24, options: ['default' => 'balanced'])]
    private string $argonPaddingProfile = 'balanced';

    #[ORM\Column(options: ['default' => 1500])]
    private int $argonPathMtu = 1500;

    #[ORM\Column(options: ['default' => true])]
    private bool $argonAllowVideoCall = true;

    /**
     * Обёртка Argon4: служба дополнительно принимает обфусцированный трафик на отдельном UDP/TCP-порту,
     * узел расшифровывает его и передаёт в эту же службу как обычное подключение.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $argonWrapEnabled = false;

    #[ORM\Column(nullable: true)]
    private ?int $argonWrapPort = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $argonWrapPassword = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $tlsDomain = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $realityPrivateKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $realityPublicKey = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $realityShortId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $obfsPassword = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extraOptions = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s:%d/%s)', $this->name !== '' ? $this->name : VpnProtocol::label($this->proto), VpnProtocol::label($this->proto), $this->listenPort, strtoupper($this->transportProto));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = trim($name);
        $this->touch();
    }

    public function getProto(): string
    {
        return $this->proto;
    }

    public function setProto(string $proto): void
    {
        if (!VpnProtocol::isSupported($proto)) {
            throw new \InvalidArgumentException(sprintf('Неподдерживаемый протокол сервиса «%s»', $proto));
        }
        $this->proto = $proto;
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

    public function getListenHost(): string
    {
        return $this->listenHost;
    }

    public function setListenHost(string $listenHost): void
    {
        $trimmed = trim($listenHost);
        $this->listenHost = $trimmed !== '' ? $trimmed : '0.0.0.0';
        $this->touch();
    }

    public function getListenPort(): int
    {
        return $this->listenPort;
    }

    public function setListenPort(int $listenPort): void
    {
        $this->listenPort = $listenPort;
        $this->touch();
    }

    public function getTransportProto(): string
    {
        return $this->transportProto;
    }

    public function setTransportProto(string $transportProto): void
    {
        $norm = strtolower(trim($transportProto));
        $this->transportProto = in_array($norm, ['udp', 'tcp', 'both'], true) ? $norm : 'udp';
        $this->touch();
    }

    public function getPublicHostOverride(): ?string
    {
        return $this->publicHostOverride;
    }

    public function setPublicHostOverride(?string $publicHostOverride): void
    {
        $this->publicHostOverride = self::cleanNullable($publicHostOverride);
        $this->touch();
    }

    public function getSubnetCidr(): ?string
    {
        return $this->subnetCidr;
    }

    public function setSubnetCidr(?string $subnetCidr): void
    {
        $this->subnetCidr = self::cleanNullable($subnetCidr);
        $this->touch();
    }

    public function getMasterPassword(): ?string
    {
        return $this->masterPassword;
    }

    public function setMasterPassword(?string $masterPassword): void
    {
        $this->masterPassword = ($masterPassword !== null && $masterPassword !== '') ? $masterPassword : null;
        $this->touch();
    }

    public function isTransitBlind(): bool
    {
        return $this->transitBlind;
    }

    public function setTransitBlind(bool $transitBlind): void
    {
        $this->transitBlind = $transitBlind;
        $this->touch();
    }

    public function getTransitTarget(): ?string
    {
        return $this->transitTarget;
    }

    public function setTransitTarget(?string $transitTarget): void
    {
        $this->transitTarget = self::cleanNullable($transitTarget);
        $this->touch();
    }

    public function getTransitAllow(): ?string
    {
        return $this->transitAllow;
    }

    public function setTransitAllow(?string $transitAllow): void
    {
        $this->transitAllow = self::cleanNullable($transitAllow);
        $this->touch();
    }

    public function getArgonProtocolVersion(): int
    {
        return $this->argonProtocolVersion;
    }

    public function setArgonProtocolVersion(int $argonProtocolVersion): void
    {
        $this->argonProtocolVersion = in_array($argonProtocolVersion, [4, 5], true) ? $argonProtocolVersion : 5;
        $this->touch();
    }

    public function isArgonDisallowV4(): bool
    {
        return $this->argonDisallowV4;
    }

    public function setArgonDisallowV4(bool $argonDisallowV4): void
    {
        $this->argonDisallowV4 = $argonDisallowV4;
        $this->touch();
    }

    public function getArgonBehaviorProfile(): ?string
    {
        return $this->argonBehaviorProfile;
    }

    public function setArgonBehaviorProfile(?string $argonBehaviorProfile): void
    {
        $this->argonBehaviorProfile = self::cleanNullable($argonBehaviorProfile);
        $this->touch();
    }

    public function getArgonPaddingProfile(): string
    {
        return $this->argonPaddingProfile;
    }

    public function setArgonPaddingProfile(string $argonPaddingProfile): void
    {
        $trimmed = trim($argonPaddingProfile);
        $this->argonPaddingProfile = $trimmed !== '' ? $trimmed : 'balanced';
        $this->touch();
    }

    public function getArgonPathMtu(): int
    {
        return $this->argonPathMtu;
    }

    public function setArgonPathMtu(int $argonPathMtu): void
    {
        $this->argonPathMtu = $argonPathMtu;
        $this->touch();
    }

    public function isArgonAllowVideoCall(): bool
    {
        return $this->argonAllowVideoCall;
    }

    public function setArgonAllowVideoCall(bool $argonAllowVideoCall): void
    {
        $this->argonAllowVideoCall = $argonAllowVideoCall;
        $this->touch();
    }

    public function isArgonWrapEnabled(): bool
    {
        return $this->argonWrapEnabled;
    }

    public function setArgonWrapEnabled(bool $argonWrapEnabled): void
    {
        $this->argonWrapEnabled = $argonWrapEnabled;
        $this->touch();
    }

    /** Обёртка имеет смысл только для «обычных» служб: Argon4 и транзит и так говорят Argon4. */
    public function hasArgonWrap(): bool
    {
        return $this->argonWrapEnabled && VpnProtocol::supportsArgonUnderlay($this->proto);
    }

    public function getArgonWrapPort(): ?int
    {
        return $this->argonWrapPort;
    }

    public function setArgonWrapPort(?int $argonWrapPort): void
    {
        $this->argonWrapPort = ($argonWrapPort !== null && $argonWrapPort > 0) ? $argonWrapPort : null;
        $this->touch();
    }

    public function getArgonWrapPassword(): ?string
    {
        return $this->argonWrapPassword;
    }

    public function setArgonWrapPassword(?string $argonWrapPassword): void
    {
        $trimmed = $argonWrapPassword !== null ? trim($argonWrapPassword) : '';
        $this->argonWrapPassword = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getTlsDomain(): ?string
    {
        return $this->tlsDomain;
    }

    public function setTlsDomain(?string $tlsDomain): void
    {
        $this->tlsDomain = self::cleanNullable($tlsDomain);
        $this->touch();
    }

    public function getRealityPrivateKey(): ?string
    {
        return $this->realityPrivateKey;
    }

    public function setRealityPrivateKey(?string $realityPrivateKey): void
    {
        $this->realityPrivateKey = self::cleanNullable($realityPrivateKey);
        $this->touch();
    }

    public function getRealityPublicKey(): ?string
    {
        return $this->realityPublicKey;
    }

    public function setRealityPublicKey(?string $realityPublicKey): void
    {
        $this->realityPublicKey = self::cleanNullable($realityPublicKey);
        $this->touch();
    }

    public function getRealityShortId(): ?string
    {
        return $this->realityShortId;
    }

    public function setRealityShortId(?string $realityShortId): void
    {
        $this->realityShortId = self::cleanNullable($realityShortId);
        $this->touch();
    }

    public function getObfsPassword(): ?string
    {
        return $this->obfsPassword;
    }

    public function setObfsPassword(?string $obfsPassword): void
    {
        $this->obfsPassword = ($obfsPassword !== null && $obfsPassword !== '') ? $obfsPassword : null;
        $this->touch();
    }

    /** @return array<string, mixed>|null */
    public function getExtraOptions(): ?array
    {
        return $this->extraOptions;
    }

    /** @param array<string, mixed>|null $extraOptions */
    public function setExtraOptions(?array $extraOptions): void
    {
        $this->extraOptions = $extraOptions;
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

    private static function cleanNullable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
