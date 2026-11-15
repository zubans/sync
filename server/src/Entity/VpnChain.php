<?php

namespace App\Entity;

use App\Repository\VpnChainRepository;
use App\Vpn\VpnProtocol;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Упорядоченная цепочка туннельных узлов CEP (транзитные узлы и выходной узел) для экспорта в profiles.json
 * и применения на узле в режиме клиента.
 */
#[ORM\Entity(repositoryClass: VpnChainRepository::class)]
#[ORM\Table(name: 'vpn_chain')]
class VpnChain
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $externalId;

    #[ORM\Column(length: 120)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** @var Collection<int, VpnChainHop> */
    #[ORM\OneToMany(targetEntity: VpnChainHop::class, mappedBy: 'chain', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    private Collection $hops;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->externalId = VpnProtocol::generateUuidV4();
        $this->hops = new ArrayCollection();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function __toString(): string
    {
        return $this->name !== '' ? $this->name : $this->externalId;
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

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): void
    {
        $trimmed = $description !== null ? trim($description) : '';
        $this->description = $trimmed !== '' ? $trimmed : null;
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

    /** @return Collection<int, VpnChainHop> */
    public function getHops(): Collection
    {
        return $this->hops;
    }

    public function addHop(VpnChainHop $hop): void
    {
        if (!$this->hops->contains($hop)) {
            $hop->setChain($this);
            $this->hops->add($hop);
            $this->touch();
        }
    }

    public function removeHop(VpnChainHop $hop): void
    {
        if ($this->hops->removeElement($hop)) {
            $this->touch();
        }
    }

    public function getHopsSummary(): string
    {
        if ($this->hops->isEmpty()) {
            return 'Нет узлов';
        }
        $parts = [];
        foreach ($this->hops as $hop) {
            $conn = $hop->getConnection();
            $parts[] = $conn !== null ? $conn->getName() : '?';
        }

        return implode(' → ', $parts);
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
