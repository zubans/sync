<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class BrowsingInput
{
    /**
     * @param list<BrowsingVisitInput> $visits посещения с устройства за последний период
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Valid]
        public readonly ?DeviceInput $device = null,

        #[Assert\Count(max: 5000)]
        #[Assert\Valid]
        public readonly array $visits = [],
    ) {
    }
}
