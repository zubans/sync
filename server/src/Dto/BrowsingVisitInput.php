<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class BrowsingVisitInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 4000)]
        public readonly string $url = '',

        #[Assert\Length(max: 500)]
        public readonly ?string $title = null,

        #[Assert\Length(max: 128)]
        public readonly ?string $browser = null,

        /** Время посещения, миллисекунды Unix. */
        #[Assert\Positive]
        public readonly int $visitedAt = 0,
    ) {
    }
}
