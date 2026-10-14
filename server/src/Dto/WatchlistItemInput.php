<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Инструмент виджета Мосбиржи: код ISS, основной режим торгов и название для подписи. */
final class WatchlistItemInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 36)]
        #[Assert\Regex('/^[A-Za-z0-9_.\-]+$/', message: 'Код ISS — латиница, цифры, «_», «-», «.».')]
        public readonly string $secid = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $engine = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $market = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 20)]
        public readonly string $board = '',

        #[Assert\Length(max: 100)]
        public readonly string $name = '',
    ) {
    }
}
