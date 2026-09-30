<?php

namespace App\Service;

use App\Dto\ContactInput;
use App\Dto\SyncRequest;
use App\Dto\SyncResult;
use App\Entity\Contact;
use App\Entity\ContactLink;
use App\Entity\Device;
use App\Entity\GoogleAccount;
use App\Entity\User;
use App\Repository\ContactLinkRepository;
use App\Repository\ContactRepository;
use App\Repository\ContactTombstoneRepository;
use App\Repository\DeviceRepository;
use App\Repository\GoogleAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Синхронизация личных контактов одного устройства.
 *
 * Присланный список — актуальное состояние личных контактов устройства (учитываются только контакты с телефоном):
 * - контакт, уже связанный с устройством (по externalId), обновляется;
 * - контакт с serverId (восстановлен с сервера) привязывается к существующему серверному;
 * - остальные создаются;
 * - контакты, связанные с устройством, но пропавшие из списка, удалены на телефоне — на сервере
 *   они попадают в корзину (Contact::TRASH_DAYS дней, потом удаляются окончательно);
 * - контакт, который администратор отправил в корзину или удалил навсегда (есть «надгробие»), не создаётся
 *   заново: устройство получает его externalId в removed и удаляет у себя;
 * - контакты, восстановленные из корзины после прошлой синхронизации, устройство получает в restored.
 * Контакты, пришедшие с других устройств и ещё не попавшие на это, не трогаются.
 */
final class ContactSyncService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ContactRepository $contacts,
        private readonly ContactLinkRepository $links,
        private readonly DeviceRepository $devices,
        private readonly GoogleAccountRepository $googleAccounts,
        private readonly ContactTombstoneRepository $tombstones,
        private readonly ContactPhotoStorage $photos,
    ) {
    }

    /** @return array<string, mixed> */
    public static function view(Contact $contact): array
    {
        return [
            'serverId' => $contact->getUuid(),
            'name' => $contact->getName(),
            'phones' => $contact->getPhones(),
            'emails' => $contact->getEmails(),
            'photo' => $contact->getPhotoSha256(),
            'birthday' => $contact->getBirthday(),
            'updatedAt' => $contact->getUpdatedAt()->format(\DATE_ATOM),
        ];
    }

    private static function hasPhone(ContactInput $input): bool
    {
        foreach ($input->phones as $phone) {
            if (trim($phone) !== '') {
                return true;
            }
        }

        return false;
    }

    public function sync(User $user, SyncRequest $request): SyncResult
    {
        return $this->em->wrapInTransaction(function () use ($user, $request): SyncResult {
            $device = $this->resolveDevice($user, $request->device->installId, $request->device->model);
            $previousSync = $device->getLastSyncAt();
            $this->saveGoogleAccounts($user, $device, $request->googleAccounts);

            // При дублях externalId побеждает последний. Контакты без телефонов не синхронизируем:
            // это служебные записи приложений (например, Telegram), которых нет в телефонной книге.
            /** @var array<string, ContactInput> $incoming */
            $incoming = [];
            foreach ($request->contacts as $input) {
                if (self::hasPhone($input)) {
                    $incoming[$input->externalId] = $input;
                }
            }

            $links = $device->getId() === null ? [] : $this->links->findByDeviceIndexed($device);
            $serverIds = array_values(array_unique(array_filter(array_map(static fn (ContactInput $i) => $i->serverId, $incoming))));
            $byServerId = $this->contacts->findPersonalByUuids($user, $serverIds);
            $removedByAdmin = $this->tombstones->findDeletedUuids($user, $serverIds);

            $created = $updated = $deleted = 0;
            $seenContacts = [];
            $resultLinks = [];
            $removed = [];
            $updates = [];

            foreach ($incoming as $externalId => $input) {
                $externalId = (string) $externalId;
                $link = $links[$externalId] ?? null;
                unset($links[$externalId]);

                if ($link === null && $input->serverId !== null && isset($removedByAdmin[$input->serverId])) {
                    $removed[] = $externalId;
                    continue;
                }

                // Администратор отправил контакт в корзину — удаляем его и с телефона.
                $target = $link?->getContact() ?? ($input->serverId !== null ? $byServerId[$input->serverId] ?? null : null);
                if ($target !== null && $target->isDeletedOnServer()) {
                    if ($link !== null) {
                        $this->em->remove($link);
                    }
                    $removed[] = $externalId;
                    continue;
                }

                if ($link !== null) {
                    $contact = $link->getContact();
                } elseif ($input->serverId !== null && isset($byServerId[$input->serverId])) {
                    $contact = $byServerId[$input->serverId];
                    $this->em->persist(new ContactLink($device, $contact, $externalId));
                } else {
                    $contact = Contact::personal($user);
                    $this->em->persist($contact);
                    $this->em->persist(new ContactLink($device, $contact, $externalId));
                    ++$created;
                }

                // Контакт изменили на сервере, а устройство этой правки ещё не получило: версия сервера
                // главнее — отправляем её устройству, а его данными в этот раз не перезаписываем.
                if ($link !== null && $link->hasPendingServerEdit()) {
                    $updates[] = ['externalId' => $externalId] + self::view($contact);
                    $link->markServerEditSent();
                    $seenContacts[spl_object_id($contact)] = true;
                    $resultLinks[] = ['externalId' => $externalId, 'serverId' => $contact->getUuid()];
                    continue;
                }

                $isNew = $contact->getId() === null;
                // Контакт удалили на другом устройстве, но здесь он есть — значит, он ещё нужен.
                $revived = $contact->undelete();
                if (($contact->apply($input->name, $input->phones, $input->emails, $input->photo, $input->birthday) || $revived) && !$isNew) {
                    ++$updated;
                }
                $seenContacts[spl_object_id($contact)] = true;
                $resultLinks[] = ['externalId' => $externalId, 'serverId' => $contact->getUuid()];
            }

            // Оставшиеся связи — контакты, которые удалили на этом телефоне: на сервере — в корзину.
            foreach ($links as $link) {
                $contact = $link->getContact();
                $this->em->remove($link);
                // Контакт мог остаться под другим externalId (LOOKUP_KEY сменился) — тогда он не удалён.
                if (!isset($seenContacts[spl_object_id($contact)])) {
                    $seenContacts[spl_object_id($contact)] = true;
                    $contact->markDeleted();
                    ++$deleted;
                }
            }

            // Восстановленные из корзины с прошлой синхронизации: новое устройство их не ждёт,
            // оно получает все контакты через восстановление при входе.
            $restored = [];
            if ($previousSync !== null) {
                foreach ($this->contacts->findRestoredForDevice($user, $device, $previousSync) as $contact) {
                    if (!isset($seenContacts[spl_object_id($contact)])) {
                        $restored[] = self::view($contact);
                    }
                }
            }

            foreach ($this->contacts->findTrashExpired($user) as $contact) {
                // Запрос видит состояние до этой синхронизации: ожившие в ней контакты не трогаем.
                if ($contact->isDeleted()) {
                    $this->em->remove($contact);
                }
            }

            $device->markSynced();
            $this->em->flush();

            $photos = array_unique(array_filter(array_map(static fn (ContactInput $i) => $i->photo, $incoming)));
            $missingPhotos = array_values(array_filter($photos, fn (string $sha) => !$this->photos->has($sha)));

            return new SyncResult($created, $updated, $deleted, \count($incoming) - \count($removed), $resultLinks, $removed, $missingPhotos, $updates, $restored);
        });
    }

    private function resolveDevice(User $user, string $installId, ?string $model): Device
    {
        $device = $this->devices->findOneBy(['user' => $user, 'installId' => $installId]);
        if ($device === null) {
            $device = new Device($user, $installId);
            $this->em->persist($device);
        }
        $device->setModel($model);

        return $device;
    }

    /**
     * @param list<string> $emails
     */
    private function saveGoogleAccounts(User $user, Device $device, array $emails): void
    {
        $existing = $this->googleAccounts->findByUserIndexed($user);
        foreach (array_unique(array_map(static fn (string $e) => mb_strtolower(trim($e)), $emails)) as $email) {
            $account = $existing[$email] ?? null;
            if ($account === null) {
                $account = new GoogleAccount($user, $email);
                $this->em->persist($account);
            }
            $account->seenOn($device);
        }
    }
}
