<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SyncRequest
{
    /**
     * @param list<string>       $googleAccounts email Google-аккаунтов на устройстве
     * @param list<ContactInput> $contacts       полный текущий список личных контактов устройства
     */
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Valid]
        public readonly ?DeviceInput $device = null,

        #[Assert\Count(max: 50)]
        #[Assert\All([new Assert\NotBlank(), new Assert\Email(), new Assert\Length(max: 255)])]
        public readonly array $googleAccounts = [],

        #[Assert\Count(max: 20000)]
        #[Assert\Valid]
        public readonly array $contacts = [],
    ) {
    }
}
