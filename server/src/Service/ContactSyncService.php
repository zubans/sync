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
use App\Repository\DeviceRepository;
use App\Repository\GoogleAccountRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Синхронизация личных контактов одного устройства.
 *
 * Присланный список — актуальное состояние личных контактов устройства:
 * - контакт, уже связанный с устройством (по externalId), обновляется;
 * - контакт с serverId (восстановлен с сервера) привязывается к существующему серверному;
 * - остальные создаются;
 * - контакты, связанные с устройством, но пропавшие из списка, удалены на телефоне — удаляются и на сервере.
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
    ) {
    }

    public function sync(User $user, SyncRequest $request): SyncResult
    {
        return $this->em->wrapInTransaction(function () use ($user, $request): SyncResult {
            $device = $this->resolveDevice($user, $request->device->installId, $request->device->model);
            $this->saveGoogleAccounts($user, $device, $request->googleAccounts);

            // При дублях externalId побеждает последний.
            /** @var array<string, ContactInput> $incoming */
            $incoming = [];
            foreach ($request->contacts as $input) {
                $incoming[$input->externalId] = $input;
            }

            $links = $device->getId() === null ? [] : $this->links->findByDeviceIndexed($device);
            $byServerId = $this->contacts->findPersonalByUuids(
                $user,
                array_values(array_unique(array_filter(array_map(static fn (ContactInput $i) => $i->serverId, $incoming)))),
            );

            $created = $updated = $deleted = 0;
            $seenContacts = [];
            $resultLinks = [];

            foreach ($incoming as $externalId => $input) {
                $externalId = (string) $externalId;
                $link = $links[$externalId] ?? null;
                unset($links[$externalId]);

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

                $isNew = $contact->getId() === null;
                if ($contact->apply($input->name, $input->phones, $input->emails) && !$isNew) {
                    ++$updated;
                }
                $seenContacts[spl_object_id($contact)] = true;
                $resultLinks[] = ['externalId' => $externalId, 'serverId' => $contact->getUuid()];
            }

            // Оставшиеся связи — контакты, которые удалили на этом телефоне.
            foreach ($links as $link) {
                $contact = $link->getContact();
                $this->em->remove($link);
                // Контакт мог остаться под другим externalId (LOOKUP_KEY сменился) — тогда его не удаляем.
                if (!isset($seenContacts[spl_object_id($contact)])) {
                    $seenContacts[spl_object_id($contact)] = true;
                    $this->em->remove($contact);
                    ++$deleted;
                }
            }

            $device->markSynced();
            $this->em->flush();

            return new SyncResult($created, $updated, $deleted, \count($incoming), $resultLinks);
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
