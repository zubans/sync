<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ContactInput
{
    /**
     * @param list<string> $phones
     * @param list<string> $emails
     */
    public function __construct(
        /** Идентификатор контакта на устройстве (LOOKUP_KEY). */
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        public readonly string $externalId = '',

        /** uuid серверного контакта, если клиент знает, что это он (например, после восстановления). */
        #[Assert\Uuid]
        public readonly ?string $serverId = null,

        #[Assert\Length(max: 255)]
        public readonly ?string $name = null,

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 64)])]
        public readonly array $phones = [],

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank(), new Assert\Length(max: 255)])]
        public readonly array $emails = [],

        /** SHA-256 фото контакта; сам файл загружается отдельно, если сервер его попросит. */
        #[Assert\Regex('/^[0-9a-f]{64}$/')]
        public readonly ?string $photo = null,
    ) {
    }
}
