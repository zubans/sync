<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class AppInput
{
    /**
     * @param list<AppFileInput> $files
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)+$/')]
        #[Assert\Length(max: 255)]
        public readonly string $packageName = '',

        #[Assert\Length(max: 255)]
        public readonly ?string $label = null,

        #[Assert\Length(max: 100)]
        public readonly ?string $versionName = null,

        #[Assert\PositiveOrZero]
        public readonly int $versionCode = 0,

        #[Assert\Length(max: 255)]
        public readonly ?string $installer = null,

        #[Assert\Regex('/^[0-9a-f]{64}$/')]
        public readonly ?string $signingSha256 = null,

        #[Assert\Count(min: 1, max: 100)]
        #[Assert\Valid]
        public readonly array $files = [],
    ) {
    }
}
