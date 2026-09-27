<?php

namespace App\Dto;

use App\Entity\Vault;
use Symfony\Component\Validator\Constraints as Assert;

/** Параметры ключа хранилища: как из мастер-пароля получить ключ и зашифрованный им ключ хранилища. */
final class VaultKeyInput
{
    public function __construct(
        #[Assert\Choice(choices: [Vault::KDF_PBKDF2_SHA256])]
        public readonly string $kdfAlgorithm = Vault::KDF_PBKDF2_SHA256,

        // Нижняя граница по рекомендации OWASP для PBKDF2-HMAC-SHA256.
        #[Assert\Range(min: 600_000, max: 10_000_000)]
        public readonly int $kdfIterations = 0,

        #[Assert\NotBlank]
        #[Assert\Regex('#^[A-Za-z0-9+/]{22,86}={0,2}$#')]
        public readonly string $kdfSalt = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex('#^[A-Za-z0-9+/]+={0,2}$#')]
        public readonly string $protectedKey = '',
    ) {
    }
}
