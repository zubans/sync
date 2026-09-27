<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

final class VaultChangeInput
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Uuid]
        public readonly string $id = '',

        /** Ревизия, от которой клиент делал правку; null — новая запись. */
        #[Assert\PositiveOrZero]
        public readonly ?int $baseRevision = null,

        /** Шифротекст записи, base64. */
        #[Assert\Length(max: 98304)]
        #[Assert\Regex('#^[A-Za-z0-9+/]+={0,2}$#')]
        public readonly ?string $data = null,

        public readonly bool $deleted = false,
    ) {
    }

    #[Assert\Callback]
    public function validateData(ExecutionContextInterface $context): void
    {
        if (!$this->deleted && ($this->data === null || $this->data === '')) {
            $context->buildViolation('Для неудалённой записи нужны данные.')->atPath('data')->addViolation();
        }
    }
}
