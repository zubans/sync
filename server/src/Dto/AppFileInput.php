<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AppFileInput
{
    public function __construct(
        /** base.apk или имя split APK. */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $name = '',

        #[Assert\Regex('/^[0-9a-f]{64}$/')]
        public readonly string $sha256 = '',

        #[Assert\Positive]
        public readonly int $size = 0,
    ) {
    }
}
