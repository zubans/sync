<?php

namespace App\Service;

use App\Dto\VaultChangeInput;
use App\Entity\Vault;
use App\Entity\VaultItem;
use App\Repository\VaultItemRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Применение правок хранилища с оптимистичной блокировкой.
 *
 * Клиент присылает правку вместе с ревизией, от которой он её делал. Если с тех пор запись
 * изменил кто-то другой, правка не применяется: клиент получает конфликт и текущую версию,
 * сводит их у себя (сервер не видит содержимого) и присылает результат заново.
 */
final class VaultService
{
    /** Предел записей в хранилище, считая «надгробия». */
    public const MAX_ITEMS = 10000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly VaultItemRepository $items,
    ) {
    }

    /**
     * @param list<VaultChangeInput> $changes
     *
     * @return list<array<string, mixed>> результат по каждой правке
     */
    public function apply(Vault $vault, array $changes): array
    {
        return $this->em->wrapInTransaction(function () use ($vault, $changes): array {
            // Правки с разных устройств сериализуются на строке хранилища: счётчик ревизий общий.
            $this->em->lock($vault, LockMode::PESSIMISTIC_WRITE);
            $this->em->refresh($vault);

            $existing = $this->items->findByUuids($vault, array_values(array_unique(array_map(static fn (VaultChangeInput $c) => $c->id, $changes))));
            $total = $this->items->count(['vault' => $vault]);
            $results = [];

            foreach ($changes as $change) {
                $item = $existing[$change->id] ?? null;

                if ($item === null && $change->deleted) {
                    // Удаление записи, которой сервер не видел: делать нечего.
                    $results[] = ['id' => $change->id, 'status' => 'ok', 'revision' => 0];
                    continue;
                }

                if ($item === null) {
                    if ($total >= self::MAX_ITEMS) {
                        $results[] = ['id' => $change->id, 'status' => 'rejected', 'error' => 'Хранилище переполнено.'];
                        continue;
                    }
                    $item = new VaultItem($vault, $change->id);
                    $this->em->persist($item);
                    $existing[$change->id] = $item;
                    ++$total;
                } elseif ($change->baseRevision !== $item->getRevision()) {
                    $results[] = ['id' => $change->id, 'status' => 'conflict', 'current' => self::view($item)];
                    continue;
                }

                $item->write($change->data, $change->deleted);
                $results[] = ['id' => $change->id, 'status' => 'ok', 'revision' => $item->getRevision()];
            }

            $this->em->flush();

            return $results;
        });
    }

    /** @return array<string, mixed> */
    public static function view(VaultItem $item): array
    {
        return [
            'id' => $item->getUuid(),
            'revision' => $item->getRevision(),
            'data' => $item->getData(),
            'deleted' => $item->isDeleted(),
            'updatedAt' => $item->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }
}
