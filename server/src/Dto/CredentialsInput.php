<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CredentialsInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',

        #[Assert\NotBlank]
        #[Assert\Length(min: 8, max: 4096)]
        public readonly string $password = '',
    ) {
    }
}
