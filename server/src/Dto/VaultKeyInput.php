<?php

namespace App\Dto;

use App\Entity\Vault;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Параметры ключа хранилища: как из мастер-пароля получить ключ и зашифрованный им ключ хранилища.
 *
 * Нижние границы — рекомендации OWASP: Argon2id не слабее 19 МиБ и 2 проходов, PBKDF2-HMAC-SHA256 — 600 000 итераций.
 */
final class VaultKeyInput
{
    public function __construct(
        #[Assert\Choice(choices: [Vault::KDF_ARGON2ID, Vault::KDF_PBKDF2_SHA256])]
        public readonly string $kdfAlgorithm = Vault::KDF_ARGON2ID,

        #[Assert\Positive]
        public readonly int $kdfIterations = 0,

        /** Argon2id: память, КиБ. */
        public readonly ?int $kdfMemory = null,

        /** Argon2id: число потоков. */
        public readonly ?int $kdfParallelism = null,

        #[Assert\NotBlank]
        #[Assert\Regex('#^[A-Za-z0-9+/]{22,86}={0,2}$#')]
        public readonly string $kdfSalt = '',

        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Assert\Regex('#^[A-Za-z0-9+/]+={0,2}$#')]
        public readonly string $protectedKey = '',
    ) {
    }

    #[Assert\Callback]
    public function validateKdf(ExecutionContextInterface $context): void
    {
        $violation = match ($this->kdfAlgorithm) {
            Vault::KDF_ARGON2ID => match (true) {
                $this->kdfMemory === null || $this->kdfMemory < 19456 || $this->kdfMemory > 1048576 => ['kdfMemory', 'Память Argon2id: от 19456 до 1048576 КиБ.'],
                $this->kdfIterations < 2 || $this->kdfIterations > 20 => ['kdfIterations', 'Проходов Argon2id: от 2 до 20.'],
                $this->kdfParallelism === null || $this->kdfParallelism < 1 || $this->kdfParallelism > 16 => ['kdfParallelism', 'Потоков Argon2id: от 1 до 16.'],
                default => null,
            },
            Vault::KDF_PBKDF2_SHA256 => $this->kdfIterations < 600_000 || $this->kdfIterations > 10_000_000
                ? ['kdfIterations', 'Итераций PBKDF2: от 600000 до 10000000.']
                : null,
            default => null,
        };
        if ($violation !== null) {
            $context->buildViolation($violation[1])->atPath($violation[0])->addViolation();
        }
    }
}
