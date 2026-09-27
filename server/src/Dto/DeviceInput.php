<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class DeviceInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Za-z0-9_-]{8,64}$/')]
        public readonly string $installId = '',

        #[Assert\Length(max: 255)]
        public readonly ?string $model = null,
    ) {
    }
}
