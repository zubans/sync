<?php

namespace App\Dto;

final class SyncResult
{
    /**
     * @param list<array{externalId: string, serverId: string}> $links   соответствие локальных контактов серверным
     * @param list<string>                                       $removed       externalId контактов, удалённых администратором:
     *                                                                          устройство должно удалить их у себя
     * @param list<string>                                       $missingPhotos SHA-256 фото, которых на сервере ещё нет:
     *                                                                          устройство должно их загрузить
     */
    public function __construct(
        public readonly int $created,
        public readonly int $updated,
        public readonly int $deleted,
        public readonly int $total,
        public readonly array $links,
        public readonly array $removed = [],
        public readonly array $missingPhotos = [],
    ) {
    }
}
