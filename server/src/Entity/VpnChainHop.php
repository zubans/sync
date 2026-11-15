<?php

namespace App\Entity;

use App\Repository\VpnChainHopRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Шаг (хоп) в цепочке подключений с порядковым номером.
 */
#[ORM\Entity(repositoryClass: VpnChainHopRepository::class)]
#[ORM\Table(name: 'vpn_chain_hop')]
class VpnChainHop
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: VpnChain::class, inversedBy: 'hops')]
    #[ORM\JoinColumn(name: 'chain_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?VpnChain $chain = null;

    #[ORM\ManyToOne(targetEntity: VpnConnection::class)]
    #[ORM\JoinColumn(name: 'connection_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private ?VpnConnection $connection = null;

    #[ORM\Column(options: ['default' => 1])]
    private int $position = 1;

    public function __toString(): string
    {
        return sprintf('#%d %s', $this->position, $this->connection?->getName() ?? '—');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getChain(): ?VpnChain
    {
        return $this->chain;
    }

    public function setChain(?VpnChain $chain): void
    {
        $this->chain = $chain;
    }

    public function getConnection(): ?VpnConnection
    {
        return $this->connection;
    }

    public function setConnection(?VpnConnection $connection): void
    {
        $this->connection = $connection;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = max(1, $position);
    }
}
