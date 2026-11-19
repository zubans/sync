<?php

namespace App\Entity;

use App\Repository\RelayRouteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Правило пропуска трафика через узел на цепочке: всё, что пришло на входной порт узла,
 * передаётся следующему узлу без расшифровки.
 *
 * Способы:
 * - слепой Argon4-транзит — процесс argon-tunnel-server в режиме transit -transit-blind:
 *   принимает UDP и TCP на одном порту, не знает пароля, поддерживает живую подстройку MTU;
 *   годится, когда следующий узел говорит Argon4 (служба Argon4 или обёртка Argon4);
 * - DNAT — правило iptables без своего процесса, годится для любого протокола
 *   (VLESS, OpenConnect, WireGuard и т. д.), но цель задаётся только IPv4-адресом.
 */
#[ORM\Entity(repositoryClass: RelayRouteRepository::class)]
#[ORM\Table(name: 'relay_route')]
class RelayRoute
{
    public const METHOD_ARGON_BLIND = 'argon_blind';
    public const METHOD_DNAT = 'dnat';

    /** @var array<string, string> Подписи способов для формы (метка => значение). */
    public const METHODS = [
        'Слепой Argon4-транзит (для Argon4 и обёрнутых в Argon4 служб)' => self::METHOD_ARGON_BLIND,
        'DNAT iptables (любой протокол, цель — IPv4)' => self::METHOD_DNAT,
    ];

    /** @var array<string, string> Короткие подписи способов. */
    public const METHOD_LABELS = [
        self::METHOD_ARGON_BLIND => 'Слепой Argon4',
        self::METHOD_DNAT => 'DNAT',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 16, options: ['default' => self::METHOD_ARGON_BLIND])]
    private string $method = self::METHOD_ARGON_BLIND;

    #[ORM\Column(length: 8, options: ['default' => PortForwardRule::PROTO_UDP])]
    private string $transportProto = PortForwardRule::PROTO_UDP;

    #[ORM\Column]
    private int $listenPort = 51820;

    #[ORM\Column(length: 255)]
    private string $targetHost = '';

    #[ORM\Column]
    private int $targetPort = 51820;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sourceCidr = null;

    /** Внешний MTU для слепого транзита: 0 — автоподбор при запуске, 576..9000 — фиксированный. */
    #[ORM\Column(options: ['default' => 0])]
    private int $argonPathMtu = 0;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

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
        return sprintf('%s (:%d → %s)', $this->name !== '' ? $this->name : 'Пропуск', $this->listenPort, $this->getTargetAddress());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = trim((string) $name);
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

    public function getMethod(): string
    {
        return $this->method;
    }

    public function setMethod(string $method): void
    {
        if (!in_array($method, self::METHODS, true)) {
            throw new \InvalidArgumentException(sprintf('Неизвестный способ пропуска «%s»', $method));
        }
        $this->method = $method;
        $this->touch();
    }

    public function isArgonBlind(): bool
    {
        return $this->method === self::METHOD_ARGON_BLIND;
    }

    public function getMethodLabel(): string
    {
        return self::METHOD_LABELS[$this->method] ?? $this->method;
    }

    public function getTransportProto(): string
    {
        return $this->transportProto;
    }

    public function setTransportProto(string $transportProto): void
    {
        $this->transportProto = in_array($transportProto, PortForwardRule::PROTOCOLS, true) ? $transportProto : PortForwardRule::PROTO_UDP;
        $this->touch();
    }

    /**
     * Транспорт, который реально занимает входной порт: слепой Argon4-транзит всегда слушает
     * UDP и TCP на одном порту, поэтому выбор в форме для него не важен.
     */
    public function getEffectiveTransport(): string
    {
        return $this->isArgonBlind() ? PortForwardRule::PROTO_BOTH : $this->transportProto;
    }

    public function getListenPort(): int
    {
        return $this->listenPort;
    }

    public function setListenPort(?int $listenPort): void
    {
        $this->listenPort = (int) $listenPort;
        $this->touch();
    }

    public function getTargetHost(): string
    {
        return $this->targetHost;
    }

    public function setTargetHost(?string $targetHost): void
    {
        $this->targetHost = trim((string) $targetHost);
        $this->touch();
    }

    public function getTargetPort(): int
    {
        return $this->targetPort;
    }

    public function setTargetPort(?int $targetPort): void
    {
        $this->targetPort = (int) $targetPort;
        $this->touch();
    }

    public function getTargetAddress(): string
    {
        $host = str_contains($this->targetHost, ':') ? '[' . $this->targetHost . ']' : $this->targetHost;

        return sprintf('%s:%d', $host, $this->targetPort);
    }

    public function getSourceCidr(): ?string
    {
        return $this->sourceCidr;
    }

    public function setSourceCidr(?string $sourceCidr): void
    {
        $trimmed = $sourceCidr !== null ? trim($sourceCidr) : '';
        $this->sourceCidr = $trimmed !== '' ? $trimmed : null;
        $this->touch();
    }

    public function getArgonPathMtu(): int
    {
        return $this->argonPathMtu;
    }

    public function setArgonPathMtu(?int $argonPathMtu): void
    {
        $this->argonPathMtu = (int) $argonPathMtu;
        $this->touch();
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): void
    {
        $trimmed = $comment !== null ? trim($comment) : '';
        $this->comment = $trimmed !== '' ? $trimmed : null;
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

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
