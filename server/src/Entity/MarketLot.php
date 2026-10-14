<?php

namespace App\Entity;

use App\Repository\MarketLotRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Покупка инструмента: когда, сколько, по какой цене и на какую сумму. По покупкам считается доходность
 * позиции, а модель советует по ней — докупить, держать или продать.
 *
 * Сумма хранится отдельно от цены: у облигаций цена — процент от номинала, плюс НКД и комиссия;
 * доходность — по изменению цены, а в рублях — от суммы.
 */
#[ORM\Entity(repositoryClass: MarketLotRepository::class)]
#[ORM\Index(name: 'idx_market_lot_user_secid', columns: ['user_id', 'secid'])]
class MarketLot
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 36)]
    private string $secid;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $boughtOn;

    /** Штук (лотов не считаем: у ISS размер лота меняется). */
    #[ORM\Column]
    private float $quantity;

    /** Цена покупки в единицах биржи: рубли, у облигаций — процент от номинала. */
    #[ORM\Column]
    private float $price;

    /** Сколько потрачено, ₽. */
    #[ORM\Column]
    private float $amount;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, string $secid, \DateTimeImmutable $boughtOn, float $quantity, float $price, float $amount, \DateTimeImmutable $now)
    {
        $this->user = $user;
        $this->secid = $secid;
        $this->boughtOn = $boughtOn;
        $this->quantity = $quantity;
        $this->price = $price;
        $this->amount = $amount;
        $this->createdAt = $now;
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

    public function getBoughtOn(): \DateTimeImmutable
    {
        return $this->boughtOn;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
