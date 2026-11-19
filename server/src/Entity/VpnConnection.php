<?php

namespace App\Entity;

use App\Repository\VpnConnectionRepository;
use App\Vpn\VpnProtocol;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Профиль клиентского подключения (все протоколы CEP: Argon4, Transit, VLESS, Hysteria2, Hysteria,
 * OpenConnect, OpenVPN, AmneziaWG, SSTP) со всеми параметрами обфускации v5 и профилей трафика.
 */
#[ORM\Entity(repositoryClass: VpnConnectionRepository::class)]
#[ORM\Table(name: 'vpn_connection')]
class VpnConnection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 32)]
    private string $proto = VpnProtocol::ARGON4;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $uri = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $config = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $server = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $username = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $password = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $clientId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $clientSecret = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $openconnectFlavor = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $serverCert = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $authGroup = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $ignoreCertErrors = false;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $routes = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $useArgon = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $argonPassword = null;

    /** UDP/TCP-порт обёртки Argon4 на узле; null — порт по умолчанию 51820. */
    #[ORM\Column(nullable: true)]
    private ?int $argonPort = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonProtocolVersion = 5;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $argonPaddingProfile = 'balanced';

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $argonFirstPacketProfile = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonPathMtu = null;

    #[ORM\Column(length: 24, nullable: true)]
    private ?string $argonBehaviorProfile = 'baseline';

    #[ORM\Column(options: ['default' => false])]
    private bool $argonLowPowerMode = false;

    #[ORM\Column(nullable: true)]
    private ?int $argonVideoCallMaxDurationSec = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonHandshakeMaxAttempts = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonHandshakeInitialTimeoutMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonHandshakeMaxTimeoutMs = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonKeepaliveMinIdleSec = null;

    #[ORM\Column(nullable: true)]
    private ?int $argonKeepaliveMaxIdleSec = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->externalId = VpnProtocol::generateUuidV4();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name !== '' ? $this->name : $this->externalId, VpnProtocol::label($this->proto));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function setExternalId(string $externalId): void
    {
        $trimmed = trim($externalId);
        $this->externalId = $trimmed !== '' ? $trimmed : VpnProtocol::generateUuidV4();
        $this->touch();
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
            throw new \InvalidArgumentException(sprintf('Неподдерживаемый протокол «%s»', $proto));
        }
        $this->proto = $proto;
        $this->touch();
    }

    public function getUri(): ?string
    {
        return $this->uri;
    }

    public function setUri(?string $uri): void
    {
        $this->uri = self::cleanNullable($uri);
        $this->touch();
    }

    public function getConfig(): ?string
    {
        return $this->config;
    }

    public function setConfig(?string $config): void
    {
        $this->config = self::cleanNullable($config);
        $this->touch();
    }

    public function getServer(): ?string
    {
        return $this->server;
    }

    public function setServer(?string $server): void
    {
        $this->server = self::cleanNullable($server);
        $this->touch();
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): void
    {
        $this->username = self::cleanNullable($username);
        $this->touch();
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(?string $password): void
    {
        $this->password = ($password !== null && $password !== '') ? $password : null;
        $this->touch();
    }

    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    public function setClientId(?string $clientId): void
    {
        $this->clientId = self::cleanNullable($clientId);
        $this->touch();
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientSecret(?string $clientSecret): void
    {
        $this->clientSecret = ($clientSecret !== null && $clientSecret !== '') ? $clientSecret : null;
        $this->touch();
    }

    public function getOpenconnectFlavor(): ?string
    {
        return $this->openconnectFlavor;
    }

    public function setOpenconnectFlavor(?string $openconnectFlavor): void
    {
        $this->openconnectFlavor = self::cleanNullable($openconnectFlavor);
        $this->touch();
    }

    public function getServerCert(): ?string
    {
        return $this->serverCert;
    }

    public function setServerCert(?string $serverCert): void
    {
        $this->serverCert = self::cleanNullable($serverCert);
        $this->touch();
    }

    public function getAuthGroup(): ?string
    {
        return $this->authGroup;
    }

    public function setAuthGroup(?string $authGroup): void
    {
        $this->authGroup = self::cleanNullable($authGroup);
        $this->touch();
    }

    public function isIgnoreCertErrors(): bool
    {
        return $this->ignoreCertErrors;
    }

    public function setIgnoreCertErrors(bool $ignoreCertErrors): void
    {
        $this->ignoreCertErrors = $ignoreCertErrors;
        $this->touch();
    }

    public function getRoutes(): ?string
    {
        return $this->routes;
    }

    public function setRoutes(?string $routes): void
    {
        $this->routes = self::cleanNullable($routes);
        $this->touch();
    }

    public function isUseArgon(): bool
    {
        return $this->useArgon;
    }

    public function setUseArgon(bool $useArgon): void
    {
        $this->useArgon = $useArgon;
        $this->touch();
    }

    public function getArgonPassword(): ?string
    {
        return $this->argonPassword;
    }

    public function setArgonPassword(?string $argonPassword): void
    {
        $this->argonPassword = ($argonPassword !== null && $argonPassword !== '') ? $argonPassword : null;
        $this->touch();
    }

    public function getArgonProtocolVersion(): ?int
    {
        return $this->argonProtocolVersion;
    }

    public function setArgonProtocolVersion(?int $argonProtocolVersion): void
    {
        $this->argonProtocolVersion = $argonProtocolVersion;
        $this->touch();
    }

    public function getArgonPaddingProfile(): ?string
    {
        return $this->argonPaddingProfile;
    }

    public function setArgonPaddingProfile(?string $argonPaddingProfile): void
    {
        $this->argonPaddingProfile = self::cleanNullable($argonPaddingProfile);
        $this->touch();
    }

    public function getArgonFirstPacketProfile(): ?string
    {
        return $this->argonFirstPacketProfile;
    }

    public function setArgonFirstPacketProfile(?string $argonFirstPacketProfile): void
    {
        $this->argonFirstPacketProfile = self::cleanNullable($argonFirstPacketProfile);
        $this->touch();
    }

    public function getArgonPort(): ?int
    {
        return $this->argonPort;
    }

    public function setArgonPort(?int $argonPort): void
    {
        $this->argonPort = ($argonPort !== null && $argonPort > 0) ? $argonPort : null;
        $this->touch();
    }

    public function getEffectiveArgonPort(): int
    {
        return $this->argonPort ?? VpnProtocol::ARGON_DEFAULT_PORT;
    }

    public function getArgonPathMtu(): ?int
    {
        return $this->argonPathMtu;
    }

    public function setArgonPathMtu(?int $argonPathMtu): void
    {
        $this->argonPathMtu = $argonPathMtu;
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

    public function isArgonLowPowerMode(): bool
    {
        return $this->argonLowPowerMode;
    }

    public function setArgonLowPowerMode(bool $argonLowPowerMode): void
    {
        $this->argonLowPowerMode = $argonLowPowerMode;
        $this->touch();
    }

    public function getArgonVideoCallMaxDurationSec(): ?int
    {
        return $this->argonVideoCallMaxDurationSec;
    }

    public function setArgonVideoCallMaxDurationSec(?int $argonVideoCallMaxDurationSec): void
    {
        $this->argonVideoCallMaxDurationSec = $argonVideoCallMaxDurationSec;
        $this->touch();
    }

    public function getArgonHandshakeMaxAttempts(): ?int
    {
        return $this->argonHandshakeMaxAttempts;
    }

    public function setArgonHandshakeMaxAttempts(?int $argonHandshakeMaxAttempts): void
    {
        $this->argonHandshakeMaxAttempts = $argonHandshakeMaxAttempts;
        $this->touch();
    }

    public function getArgonHandshakeInitialTimeoutMs(): ?int
    {
        return $this->argonHandshakeInitialTimeoutMs;
    }

    public function setArgonHandshakeInitialTimeoutMs(?int $argonHandshakeInitialTimeoutMs): void
    {
        $this->argonHandshakeInitialTimeoutMs = $argonHandshakeInitialTimeoutMs;
        $this->touch();
    }

    public function getArgonHandshakeMaxTimeoutMs(): ?int
    {
        return $this->argonHandshakeMaxTimeoutMs;
    }

    public function setArgonHandshakeMaxTimeoutMs(?int $argonHandshakeMaxTimeoutMs): void
    {
        $this->argonHandshakeMaxTimeoutMs = $argonHandshakeMaxTimeoutMs;
        $this->touch();
    }

    public function getArgonKeepaliveMinIdleSec(): ?int
    {
        return $this->argonKeepaliveMinIdleSec;
    }

    public function setArgonKeepaliveMinIdleSec(?int $argonKeepaliveMinIdleSec): void
    {
        $this->argonKeepaliveMinIdleSec = $argonKeepaliveMinIdleSec;
        $this->touch();
    }

    public function getArgonKeepaliveMaxIdleSec(): ?int
    {
        return $this->argonKeepaliveMaxIdleSec;
    }

    public function setArgonKeepaliveMaxIdleSec(?int $argonKeepaliveMaxIdleSec): void
    {
        $this->argonKeepaliveMaxIdleSec = $argonKeepaliveMaxIdleSec;
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

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function setNotes(?string $notes): void
    {
        $this->notes = self::cleanNullable($notes);
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

    public function getTargetSummary(): string
    {
        return match ($this->proto) {
            VpnProtocol::VLESS, VpnProtocol::HYSTERIA2, VpnProtocol::HYSTERIA => self::summarizeUri($this->uri),
            VpnProtocol::OPENVPN, VpnProtocol::AMNEZIAWG => 'Встроенная конфигурация (' . strlen((string) $this->config) . ' Б)',
            default => $this->server ?? '—',
        };
    }

    private static function summarizeUri(?string $uri): string
    {
        if ($uri === null || trim($uri) === '') {
            return '—';
        }
        $parts = parse_url(trim($uri));
        if (!is_array($parts) || !isset($parts['host'])) {
            return substr(trim($uri), 0, 48);
        }

        return isset($parts['port']) ? $parts['host'] . ':' . $parts['port'] : $parts['host'];
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
