<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/** Список инструментов виджета целиком, в порядке на экране. */
final class WatchlistInput
{
    /**
     * @param list<WatchlistItemInput> $instruments
     */
    public function __construct(
        #[Assert\Count(max: 100)]
        #[Assert\Valid]
        public readonly array $instruments = [],
    ) {
    }
}
