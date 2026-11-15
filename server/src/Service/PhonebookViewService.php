<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\ContactLink;
use App\Entity\Device;
use App\Entity\User;
use App\Repository\ContactLinkRepository;
use App\Repository\ContactRepository;
use App\Repository\DeviceRepository;
use App\Repository\UserRepository;

/** Собирает данные телефонной книги выбранного пользователя для админки и экспорта vCard. */
final class PhonebookViewService
{
    public const TAB_PERSONAL = 'personal';
    public const TAB_FAMILY = 'family';
    public const TAB_TRASH = 'trash';

    public function __construct(
        private readonly UserRepository $users,
        private readonly ContactRepository $contacts,
        private readonly DeviceRepository $devices,
        private readonly ContactLinkRepository $contactLinks,
    ) {
    }

    /** Находит явно запрошенного пользователя или возвращает первого пользователя с контактами / первого по алфавиту. */
    public function resolveUser(?int $requestedUserId): ?User
    {
        if ($requestedUserId !== null) {
            return $this->users->find($requestedUserId);
        }

        $allUsers = $this->users->findAllOrdered();
        if ($allUsers === []) {
            return null;
        }

        $countsByUser = $this->contacts->countPersonalByUser();
        foreach ($allUsers as $user) {
            $stat = $countsByUser[$user->getId()] ?? null;
            if ($stat !== null && ($stat['active'] > 0 || $stat['trash'] > 0)) {
                return $user;
            }
        }

        return $allUsers[0];
    }

    /**
     * Формирует полную модель телефонной книги: список всех пользователей, устройства выбранного
     * пользователя, личные контакты, доступные от семьи, корзину и связи ContactLink с устройствами.
     *
     * @return array<string, mixed>
     */
    public function buildView(?User $selectedUser, ?int $requestedDeviceId, string $tab, string $query): array
    {
        $tab = \in_array($tab, [self::TAB_PERSONAL, self::TAB_FAMILY, self::TAB_TRASH], true)
            ? $tab
            : self::TAB_PERSONAL;
        $query = trim($query);

        $allUsers = $this->users->findAllOrdered();
        $countsByUser = $this->contacts->countPersonalByUser();
        $userRows = array_map(static function (User $user) use ($countsByUser): array {
            $stat = $countsByUser[$user->getId()] ?? ['active' => 0, 'trash' => 0];

            return [
                'user' => $user,
                'activeCount' => $stat['active'],
                'trashCount' => $stat['trash'],
            ];
        }, $allUsers);

        if ($selectedUser === null) {
            return [
                'users' => $userRows,
                'selectedUser' => null,
                'devices' => [],
                'selectedDevice' => null,
                'tab' => $tab,
                'query' => $query,
                'linksByContactId' => [],
                'personalContacts' => [],
                'familyContacts' => [],
                'trashContacts' => [],
                'counts' => [
                    'personalTotal' => 0,
                    'personalFiltered' => 0,
                    'familyTotal' => 0,
                    'familyFiltered' => 0,
                    'trashTotal' => 0,
                    'trashFiltered' => 0,
                    'effectiveTotal' => 0,
                ],
            ];
        }

        $userDevices = $this->devices->findForUser($selectedUser);
        $selectedDevice = $this->findOwnedDevice($userDevices, $requestedDeviceId);

        [$linksByContactId, $syncedCountByDevice] = $this->loadLinksForUser($selectedUser);

        $deviceRows = array_map(static fn (Device $device): array => [
            'device' => $device,
            'syncedCount' => $syncedCountByDevice[$device->getId()] ?? 0,
        ], $userDevices);

        $allPersonal = $this->contacts->findPersonal($selectedUser);
        $family = $selectedUser->getFamily();
        $allFamily = $family !== null ? $this->contacts->findSharedForUser($selectedUser, $family) : [];
        $allTrash = $this->contacts->findTrashedPersonal($selectedUser);

        $personalFiltered = $this->filterContacts($allPersonal, $selectedDevice, $query, $linksByContactId);
        $familyFiltered = $this->filterContacts($allFamily, $selectedDevice, $query, $linksByContactId);
        $trashFiltered = $this->filterContacts($allTrash, null, $query, $linksByContactId);

        return [
            'users' => $userRows,
            'selectedUser' => $selectedUser,
            'devices' => $deviceRows,
            'selectedDevice' => $selectedDevice,
            'tab' => $tab,
            'query' => $query,
            'linksByContactId' => $linksByContactId,
            'personalContacts' => $personalFiltered,
            'familyContacts' => $familyFiltered,
            'trashContacts' => $trashFiltered,
            'counts' => [
                'personalTotal' => \count($allPersonal),
                'personalFiltered' => \count($personalFiltered),
                'familyTotal' => \count($allFamily),
                'familyFiltered' => \count($familyFiltered),
                'trashTotal' => \count($allTrash),
                'trashFiltered' => \count($trashFiltered),
                'effectiveTotal' => \count($allPersonal) + \count($allFamily),
            ],
        ];
    }

    /** Экспортирует активные контакты пользователя в формат vCard 3.0 (.vcf). */
    public function buildVCard(User $user, bool $includeFamily = false): string
    {
        $items = $this->contacts->findPersonal($user);
        if ($includeFamily && $user->getFamily() !== null) {
            $items = [...$items, ...$this->contacts->findSharedForUser($user, $user->getFamily())];
        }

        $cards = array_map(
            fn (Contact $contact): string => $this->formatVCardItem($contact, $contact->getUser() !== $user),
            $items,
        );

        return $cards === [] ? '' : implode("\r\n", $cards)."\r\n";
    }

    /**
     * @param list<Device> $userDevices
     */
    private function findOwnedDevice(array $userDevices, ?int $requestedDeviceId): ?Device
    {
        if ($requestedDeviceId === null) {
            return null;
        }
        foreach ($userDevices as $device) {
            if ($device->getId() === $requestedDeviceId) {
                return $device;
            }
        }

        return null;
    }

    /**
     * Загружает все ContactLink на устройствах пользователя одним запросом.
     *
     * @return array{
     *     0: array<int, list<array{device: Device, externalId: string, pendingSync: bool}>>,
     *     1: array<int, int>
     * }
     */
    private function loadLinksForUser(User $user): array
    {
        /** @var list<ContactLink> $links */
        $links = $this->contactLinks->createQueryBuilder('l')
            ->addSelect('d', 'c')
            ->join('l.device', 'd')
            ->join('l.contact', 'c')
            ->where('d.user = :user')
            ->orderBy('d.model', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $byContact = [];
        $countByDevice = [];
        foreach ($links as $link) {
            $contact = $link->getContact();
            $device = $link->getDevice();
            $contactId = $contact->getId();
            $deviceId = $device->getId();
            if ($contactId === null || $deviceId === null) {
                continue;
            }
            $byContact[$contactId][] = [
                'device' => $device,
                'externalId' => $link->getExternalId(),
                'pendingSync' => $link->hasPendingServerEdit(),
            ];
            if (!$contact->isDeleted()) {
                $countByDevice[$deviceId] = ($countByDevice[$deviceId] ?? 0) + 1;
            }
        }

        return [$byContact, $countByDevice];
    }

    /**
     * @param list<Contact> $contacts
     * @param array<int, list<array{device: Device, externalId: string, pendingSync: bool}>> $linksByContactId
     *
     * @return list<Contact>
     */
    private function filterContacts(array $contacts, ?Device $device, string $query, array $linksByContactId): array
    {
        $deviceId = $device?->getId();
        $queryDigits = preg_replace('/\D+/', '', $query) ?? '';

        return array_values(array_filter(
            $contacts,
            function (Contact $contact) use ($deviceId, $query, $queryDigits, $linksByContactId): bool {
                if ($deviceId !== null && !$this->isLinkedToDevice($contact, $deviceId, $linksByContactId)) {
                    return false;
                }
                if ($query === '') {
                    return true;
                }

                return $this->matchesQuery($contact, $query, $queryDigits);
            },
        ));
    }

    /** @param array<int, list<array{device: Device, externalId: string, pendingSync: bool}>> $linksByContactId */
    private function isLinkedToDevice(Contact $contact, int $deviceId, array $linksByContactId): bool
    {
        foreach ($linksByContactId[$contact->getId() ?? 0] ?? [] as $link) {
            if ($link['device']->getId() === $deviceId) {
                return true;
            }
        }

        return false;
    }

    private function matchesQuery(Contact $contact, string $query, string $queryDigits): bool
    {
        $fields = [
            $contact->getName(),
            $contact->getBirthday(),
            $contact->getUuid(),
            $contact->getUser()?->getEmail(),
            ...$contact->getPhones(),
            ...$contact->getEmails(),
        ];

        foreach ($fields as $value) {
            if ($value !== null && $value !== '' && mb_stripos($value, $query) !== false) {
                return true;
            }
        }

        if (\strlen($queryDigits) >= 3) {
            foreach ($contact->getPhones() as $phone) {
                $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
                if ($phoneDigits !== '' && str_contains($phoneDigits, $queryDigits)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function formatVCardItem(Contact $contact, bool $isFamilyShared): string
    {
        $lines = [
            'BEGIN:VCARD',
            'VERSION:3.0',
            'UID:'.$contact->getUuid(),
            'FN:'.$this->escapeVCardValue((string) $contact),
        ];

        foreach ($contact->getPhones() as $phone) {
            $lines[] = 'TEL;TYPE=CELL:'.$this->escapeVCardValue($phone);
        }
        foreach ($contact->getEmails() as $email) {
            $lines[] = 'EMAIL;TYPE=INTERNET:'.$this->escapeVCardValue($email);
        }
        if ($contact->getBirthday() !== null && $contact->getBirthday() !== '') {
            $lines[] = 'BDAY:'.$this->escapeVCardValue($contact->getBirthday());
        }
        if ($isFamilyShared && $contact->getUser() !== null) {
            $lines[] = 'NOTE:'.$this->escapeVCardValue('Семейный контакт от '.$contact->getUser()->getEmail());
        }
        $lines[] = 'END:VCARD';

        return implode("\r\n", $lines);
    }

    private function escapeVCardValue(string $value): string
    {
        return str_replace(
            ["\\", "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }
}
