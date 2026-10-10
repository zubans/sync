<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CalendarInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        public readonly string $name = '',

        #[Assert\Regex('/^#[0-9A-Fa-f]{6}$/', message: 'Цвет — в формате #RRGGBB.')]
        public readonly ?string $color = null,

        /** Календарь видят и правят все члены семьи владельца. */
        public readonly bool $familyShared = false,
    ) {
    }
}
