<?php

namespace App\Entity;

use App\Repository\PortForwardRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Правило проброса портов (DNAT/MASQUERADE) на узле в режиме «Сервер».
 */
#[ORM\Entity(repositoryClass: PortForwardRuleRepository::class)]
#[ORM\Table(name: 'port_forward_rule')]
class PortForwardRule
{
    public const PROTO_TCP = 'tcp';
    public const PROTO_UDP = 'udp';
    public const PROTO_BOTH = 'both';

    public const PROTOCOLS = [
        'TCP и UDP' => self::PROTO_BOTH,
        'Только TCP' => self::PROTO_TCP,
        'Только UDP' => self::PROTO_UDP,
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    #[ORM\Column(length: 8, options: ['default' => self::PROTO_BOTH])]
    private string $protocol = self::PROTO_BOTH;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $listenInterface = null;

    #[ORM\Column]
    private int $listenPort = 8080;

    #[ORM\Column(nullable: true)]
    private ?int $listenPortEnd = null;

    #[ORM\Column(length: 64)]
    private string $targetIp = '';

    #[ORM\Column]
    private int $targetPort = 8080;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $sourceCidr = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enableMasquerade = true;

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
        return sprintf('%s (%s → %s:%d)', $this->name !== '' ? $this->name : 'Правило', $this->getPortRangeDisplay(), $this->targetIp, $this->targetPort);
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

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        $this->touch();
    }

    public function getProtocol(): string
    {
        return $this->protocol;
    }

    public function setProtocol(string $protocol): void
    {
        $norm = strtolower(trim($protocol));
        if (!in_array($norm, [self::PROTO_TCP, self::PROTO_UDP, self::PROTO_BOTH], true)) {
            throw new \InvalidArgumentException(sprintf('Недопустимый протокол проброса портов «%s»', $protocol));
        }
        $this->protocol = $norm;
        $this->touch();
    }

    public function getListenInterface(): ?string
    {
        return $this->listenInterface;
    }

    public function setListenInterface(?string $listenInterface): void
    {
        $trimmed = $listenInterface !== null ? trim($listenInterface) : '';
        $this->listenInterface = $trimmed !== '' ? $trimmed : null;
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

    public function getListenPortEnd(): ?int
    {
        return $this->listenPortEnd;
    }

    public function setListenPortEnd(?int $listenPortEnd): void
    {
        $this->listenPortEnd = ($listenPortEnd !== null && $listenPortEnd > 0) ? $listenPortEnd : null;
        $this->touch();
    }

    public function getTargetIp(): string
    {
        return $this->targetIp;
    }

    public function setTargetIp(string $targetIp): void
    {
        $this->targetIp = trim($targetIp);
        $this->touch();
    }

    public function getTargetPort(): int
    {
        return $this->targetPort;
    }

    public function setTargetPort(int $targetPort): void
    {
        $this->targetPort = $targetPort;
        $this->touch();
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

    public function isEnableMasquerade(): bool
    {
        return $this->enableMasquerade;
    }

    public function setEnableMasquerade(bool $enableMasquerade): void
    {
        $this->enableMasquerade = $enableMasquerade;
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

    public function getPortRangeDisplay(): string
    {
        if ($this->listenPortEnd !== null && $this->listenPortEnd > $this->listenPort) {
            return sprintf('%d-%d', $this->listenPort, $this->listenPortEnd);
        }

        return (string) $this->listenPort;
    }

    public function getEffectiveEndPort(): int
    {
        return ($this->listenPortEnd !== null && $this->listenPortEnd >= $this->listenPort)
            ? $this->listenPortEnd
            : $this->listenPort;
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
