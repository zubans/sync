<?php

namespace App\Entity;

use App\Repository\VpnSubscriptionRepository;
use App\Vpn\SubscriptionParser;
use App\Vpn\VpnProtocol;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Подписка провайдера: адрес, по которому панель (Remnawave, Marzban и т.п.) отдаёт список серверов.
 * Подключение выбирает из неё один сервер (VpnConnection::subscriptionId + subscriptionServer) и при обновлении
 * подписки получает его свежую ссылку: ключи и адреса у провайдера меняются, а выбор администратора остаётся.
 */
#[ORM\Entity(repositoryClass: VpnSubscriptionRepository::class)]
#[ORM\Table(name: 'vpn_subscription')]
class VpnSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** id подписки в profiles.json клиентов CEP. */
    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(length: 1024)]
    private string $url = '';

    /** HWID вместо HWID этого сервера — чтобы не занимать новое место в лимите устройств подписки. */
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $hwid = null;

    /** @var list<array{name: string, uri: string}> */
    #[ORM\Column(type: Types::JSON)]
    private array $servers = [];

    /** Трафик и срок действия из заголовка subscription-userinfo. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $info = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $refreshedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->externalId = VpnProtocol::generateUuidV4();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->url;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getExternalId(): string
    {
        return $this->externalId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
    }

    public function getUrl(): string
    {
        return $this->url;
    }

    public function setUrl(?string $url): void
    {
        $this->url = trim((string) $url);
    }

    public function getHwid(): ?string
    {
        return $this->hwid;
    }

    public function setHwid(?string $hwid): void
    {
        $hwid = trim((string) $hwid);
        $this->hwid = $hwid === '' ? null : $hwid;
    }

    /** @return list<array{name: string, uri: string}> */
    public function getServers(): array
    {
        return $this->servers;
    }

    /** @param list<array{name: string, uri: string}> $servers */
    public function setServers(array $servers): void
    {
        $this->servers = array_values($servers);
    }

    /** @return array{name: string, uri: string}|null */
    public function findServer(string $name): ?array
    {
        foreach ($this->servers as $server) {
            if ($server['name'] === $name) {
                return $server;
            }
        }

        return null;
    }

    /** Серверы, из которых можно сделать подключение (протоколы CEP по ссылке). */
    public function getServerCount(): int
    {
        return count($this->servers);
    }

    public function getSupportedServerCount(): int
    {
        return count(array_filter($this->servers, static fn (array $s): bool => SubscriptionParser::protocolOf($s['uri']) !== null));
    }

    public function getInfo(): ?string
    {
        return $this->info;
    }

    public function setInfo(?string $info): void
    {
        $this->info = $info;
    }

    public function getRefreshedAt(): ?\DateTimeImmutable
    {
        return $this->refreshedAt;
    }

    public function setRefreshedAt(?\DateTimeImmutable $refreshedAt): void
    {
        $this->refreshedAt = $refreshedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
