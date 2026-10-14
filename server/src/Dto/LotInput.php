<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Покупка инструмента: дата, количество, цена (у облигаций — % от номинала) и потраченная сумма, ₽. */
final class LotInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Date(message: 'Дата покупки — в формате ГГГГ-ММ-ДД.')]
        public readonly string $boughtOn = '',

        #[Assert\Positive]
        public readonly float $quantity = 0,

        #[Assert\Positive]
        public readonly float $price = 0,

        /** Не передана — количество × цена. */
        #[Assert\Positive]
        public readonly ?float $amount = null,
    ) {
    }
}
