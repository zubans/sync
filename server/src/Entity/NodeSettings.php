<?php

namespace App\Entity;

use App\Repository\NodeSettingsRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Настройки роли текущего узла и сетевые параметры устройства, на котором развёрнута инсталляция sync.
 *
 * Роли:
 * - «Клиент» — устройство само подключается к цепочке узлов, ничего не принимает извне;
 * - «Узел на цепочке» — принимает трафик на своих портах и передаёт его следующему узлу,
 *   не расшифровывая (слепой Argon4-транзит или DNAT);
 * - «VPN-сервер» — поднимает службы VPN, расшифровывает трафик и выпускает его в сеть;
 *   может одновременно работать и как узел на цепочке.
 */
#[ORM\Entity(repositoryClass: NodeSettingsRepository::class)]
#[ORM\Table(name: 'node_settings')]
class NodeSettings
{
    public const MODE_CLIENT = 'client';
    public const MODE_RELAY = 'relay';
    public const MODE_SERVER = 'server';

    public const MODES = [
        self::MODE_CLIENT => 'Клиент (подключение к удалённым узлам и цепочкам)',
        self::MODE_RELAY => 'Узел на цепочке (пропускает трафик к следующему узлу)',
        self::MODE_SERVER => 'VPN-сервер (приём подключений, сервисы, проброс портов, пользователи)',
    ];

    /** @var array<string, string> Короткие названия ролей для бейджей и сообщений. */
    public const MODE_LABELS = [
        self::MODE_CLIENT => 'Клиент',
        self::MODE_RELAY => 'Узел на цепочке',
        self::MODE_SERVER => 'VPN-сервер',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16, options: ['default' => self::MODE_CLIENT])]
    private string $mode = self::MODE_CLIENT;

    #[ORM\Column(length: 120, options: ['default' => 'Локальный узел'])]
    private string $nodeName = 'Локальный узел';

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $publicHost = null;

    #[ORM\Column(length: 32, options: ['default' => 'eth0'])]
    private string $wanInterface = 'eth0';

    #[ORM\Column(length: 32, options: ['default' => '10.44.0.0/24'])]
    private string $vpnSubnetCidr = '10.44.0.0/24';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $dnsServers = ['1.1.1.1', '8.8.8.8'];

    #[ORM\Column(options: ['default' => true])]
    private bool $blockIpv6 = true;

    #[ORM\Column(nullable: true)]
    private ?int $activeClientChainId = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $autoApplyRuntime = true;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastRuntimeAppliedAt = null;

    #[ORM\Column(length: 32, options: ['default' => 'pending'])]
    private string $lastRuntimeStatus = 'pending';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastRuntimeMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        if (!array_key_exists($mode, self::MODES)) {
            throw new \InvalidArgumentException(sprintf('Неизвестный режим узла «%s»', $mode));
        }
        $this->mode = $mode;
        $this->touch();
    }

    public function isServerMode(): bool
    {
        return $this->mode === self::MODE_SERVER;
    }

    public function isClientMode(): bool
    {
        return $this->mode === self::MODE_CLIENT;
    }

    public function isRelayMode(): bool
    {
        return $this->mode === self::MODE_RELAY;
    }

    /** Узел принимает трафик извне и может пропускать его дальше по цепочке (роли «Узел на цепочке» и «VPN-сервер»). */
    public function canRelay(): bool
    {
        return $this->mode === self::MODE_RELAY || $this->mode === self::MODE_SERVER;
    }

    public function getModeLabel(): string
    {
        return self::MODE_LABELS[$this->mode] ?? $this->mode;
    }

    public function getNodeName(): string
    {
        return $this->nodeName;
    }

    public function setNodeName(string $nodeName): void
    {
        $this->nodeName = trim($nodeName) !== '' ? trim($nodeName) : 'Локальный узел';
        $this->touch();
    }

    public function getPublicHost(): ?string
    {
        return $this->publicHost;
    }

    public function setPublicHost(?string $publicHost): void
    {
        $trimmed = $publicHost !== null ? trim($publicHost) : '';
        $this->publicHost = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getWanInterface(): string
    {
        return $this->wanInterface;
    }

    public function setWanInterface(string $wanInterface): void
    {
        $trimmed = trim($wanInterface);
        $this->wanInterface = $trimmed !== '' ? $trimmed : 'eth0';
        $this->touch();
    }

    public function getVpnSubnetCidr(): string
    {
        return $this->vpnSubnetCidr;
    }

    public function setVpnSubnetCidr(string $vpnSubnetCidr): void
    {
        $trimmed = trim($vpnSubnetCidr);
        $this->vpnSubnetCidr = $trimmed !== '' ? $trimmed : '10.44.0.0/24';
        $this->touch();
    }

    /** @return list<string> */
    public function getDnsServers(): array
    {
        return $this->dnsServers;
    }

    /** @param list<string> $dnsServers */
    public function setDnsServers(array $dnsServers): void
    {
        $clean = [];
        foreach ($dnsServers as $dns) {
            $item = trim((string) $dns);
            if ($item !== '') {
                $clean[] = $item;
            }
        }
        $this->dnsServers = $clean !== [] ? array_values(array_unique($clean)) : ['1.1.1.1'];
        $this->touch();
    }

    public function isBlockIpv6(): bool
    {
        return $this->blockIpv6;
    }

    public function setBlockIpv6(bool $blockIpv6): void
    {
        $this->blockIpv6 = $blockIpv6;
        $this->touch();
    }

    public function getActiveClientChainId(): ?int
    {
        return $this->activeClientChainId;
    }

    public function setActiveClientChainId(?int $activeClientChainId): void
    {
        $this->activeClientChainId = $activeClientChainId;
        $this->touch();
    }

    public function isAutoApplyRuntime(): bool
    {
        return $this->autoApplyRuntime;
    }

    public function setAutoApplyRuntime(bool $autoApplyRuntime): void
    {
        $this->autoApplyRuntime = $autoApplyRuntime;
        $this->touch();
    }

    public function getLastRuntimeAppliedAt(): ?\DateTimeImmutable
    {
        return $this->lastRuntimeAppliedAt;
    }

    public function setLastRuntimeAppliedAt(?\DateTimeImmutable $lastRuntimeAppliedAt): void
    {
        $this->lastRuntimeAppliedAt = $lastRuntimeAppliedAt;
    }

    public function getLastRuntimeStatus(): string
    {
        return $this->lastRuntimeStatus;
    }

    public function setLastRuntimeStatus(string $lastRuntimeStatus): void
    {
        $this->lastRuntimeStatus = $lastRuntimeStatus;
    }

    public function getLastRuntimeMessage(): ?string
    {
        return $this->lastRuntimeMessage;
    }

    public function setLastRuntimeMessage(?string $lastRuntimeMessage): void
    {
        $this->lastRuntimeMessage = $lastRuntimeMessage;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
