<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class VaultChangesInput
{
    /**
     * @param list<VaultChangeInput> $changes
     */
    public function __construct(
        #[Assert\Count(min: 1, max: 500)]
        #[Assert\Valid]
        public readonly array $changes = [],
    ) {
    }
}
