<?php

namespace App\Entity;

use App\Repository\MarketInstrumentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Инструмент Мосбиржи в виджете планшета пользователя. Список и порядок ведёт планшет (порядок задаёт
 * пользователь перетаскиванием) и присылает целиком; сервер по этому списку пишет анализ инструментов.
 */
#[ORM\Entity(repositoryClass: MarketInstrumentRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_market_instrument_user_secid', columns: ['user_id', 'secid'])]
class MarketInstrument
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** Код в ISS: SBER, IMOEX, SU26238RMFS4, USD000UTSTOM. */
    #[ORM\Column(length: 36)]
    private string $secid;

    /** Основной режим торгов ISS — по нему запрашиваются свечи. */
    #[ORM\Column(length: 20)]
    private string $engine;

    #[ORM\Column(length: 20)]
    private string $market;

    #[ORM\Column(length: 20)]
    private string $board;

    /** Название для подписи и поиска новостей: «Сбербанк», «ЛУКОЙЛ». */
    #[ORM\Column(length: 100)]
    private string $name;

    /** Место в списке виджета, с нуля. */
    #[ORM\Column]
    private int $position;

    public function __construct(User $user, string $secid, string $engine, string $market, string $board, string $name, int $position)
    {
        $this->user = $user;
        $this->secid = $secid;
        $this->update($engine, $market, $board, $name, $position);
    }

    public function update(string $engine, string $market, string $board, string $name, int $position): void
    {
        $this->engine = $engine;
        $this->market = $market;
        $this->board = $board;
        $this->name = mb_substr($name, 0, 100);
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getSecid(): string
    {
        return $this->secid;
    }

    public function getEngine(): string
    {
        return $this->engine;
    }

    public function getMarket(): string
    {
        return $this->market;
    }

    public function getBoard(): string
    {
        return $this->board;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPosition(): int
    {
        return $this->position;
    }
}
