<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class InventoryInput
{
    /**
     * @param list<AppInput> $apps полный список пользовательских приложений устройства
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Valid]
        public readonly ?DeviceInput $device = null,

        #[Assert\Count(max: 2000)]
        #[Assert\Valid]
        public readonly array $apps = [],
    ) {
    }
}
