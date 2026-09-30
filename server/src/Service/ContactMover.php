<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Перенос контактов между личными и семейными.
 *
 * Личный контакт удаляется (ContactTombstoneListener оставляет «надгробие», и при синхронизации
 * личная копия удаляется с телефона владельца), а в семье создаётся контакт с теми же данными и фото —
 * он придёт на телефоны всех членов семьи. Если такой контакт (имя + телефоны) в семье уже есть,
 * дубль не создаётся.
 */
final class ContactMover
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<Contact> $contacts
     *
     * @return array{moved: int, merged: int, skipped: int} перенесено, совпало с уже семейным, пропущено (не личные)
     */
    public function moveToFamily(array $contacts, Family $family): array
    {
        return $this->em->wrapInTransaction(function () use ($contacts, $family): array {
            $existing = [];
            foreach ($family->getContacts() as $contact) {
                $existing[self::fingerprint($contact)] = true;
            }

            $moved = $merged = $skipped = 0;
            foreach ($contacts as $contact) {
                if ($contact->getUser() === null) {
                    ++$skipped;
                    continue;
                }

                $key = self::fingerprint($contact);
                if (isset($existing[$key])) {
                    ++$merged;
                } else {
                    $copy = (new Contact())->setFamily($family);
                    $copy->apply($contact->getName(), $contact->getPhones(), $contact->getEmails(), $contact->getPhotoSha256());
                    $this->em->persist($copy);
                    $existing[$key] = true;
                    ++$moved;
                }
                $this->em->remove($contact);
            }
            $this->em->flush();

            return ['moved' => $moved, 'merged' => $merged, 'skipped' => $skipped];
        });
    }

    /**
     * Возвращает семейный контакт в личные контакты пользователя. Контакт сохраняет идентификатор:
     * телефон этого пользователя, где контакт лежал как семейный, увидит, что тот стал его личным,
     * и оставит запись в книге; у остальных членов семьи копия удалится как у удалённого семейного.
     * Если у пользователя уже есть такой контакт, семейный просто удаляется.
     *
     * @return bool true — контакт стал личным, false — совпал с уже существующим и удалён
     */
    public function moveToUser(Contact $contact, User $user): bool
    {
        if ($contact->getFamily() === null) {
            throw new \InvalidArgumentException('Контакт не семейный.');
        }

        return $this->em->wrapInTransaction(function () use ($contact, $user): bool {
            $key = self::fingerprint($contact);
            $duplicate = false;
            foreach ($this->em->getRepository(Contact::class)->findBy(['user' => $user, 'deletedAt' => null]) as $personal) {
                if (self::fingerprint($personal) === $key) {
                    $duplicate = true;
                    break;
                }
            }

            if ($duplicate) {
                $this->em->remove($contact);
            } else {
                $contact->reassignTo($user);
            }
            $this->em->flush();

            return !$duplicate;
        });
    }

    /**
     * Тот же «отпечаток», что у клиента (Fingerprint.kt): имя без регистра и лишних пробелов
     * и последние 10 цифр каждого номера — «+7 900 …» и «8 (900) …» считаются одним номером.
     */
    private static function fingerprint(Contact $contact): string
    {
        $name = preg_replace('/\s+/u', ' ', mb_strtolower(trim((string) $contact->getName())));
        $phones = array_filter(array_map(
            static fn (string $p) => substr(preg_replace('/\D/', '', $p), -10),
            $contact->getPhones(),
        ));
        sort($phones);
        $emails = $contact->getEmails();
        sort($emails);
        $keys = $phones !== [] ? $phones : $emails;

        return $name.'|'.implode(',', array_unique($keys));
    }
}
