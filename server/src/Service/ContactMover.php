<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\Family;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Общие контакты семьи. Контакт всегда остаётся у владельца: добавление в семью и удаление из неё
 * меняют только отметку. Общие контакты ставятся на устройства членов семьи, где их ещё нет
 * (новый телефон, новый член семьи); после удаления из семьи они просто перестают приходить —
 * с телефонов ничего не удаляется.
 */
final class ContactMover
{
    public const SHARED = 'shared';
    public const ALREADY = 'already';
    public const DUPLICATE = 'duplicate';
    public const NOT_MEMBER = 'notMember';

    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param list<Contact> $contacts
     *
     * @return array<string, int> сколько добавлено, уже было в семье, совпало с общим контактом другого члена, владелец не в семье
     */
    public function share(array $contacts, Family $family): array
    {
        $result = [self::SHARED => 0, self::ALREADY => 0, self::DUPLICATE => 0, self::NOT_MEMBER => 0];
        $existing = [];
        foreach ($family->getContacts() as $shared) {
            if ($shared->getDeletedAt() === null) {
                $existing[self::fingerprint($shared)] = true;
            }
        }

        foreach ($contacts as $contact) {
            $key = self::fingerprint($contact);
            $status = match (true) {
                $contact->getFamily() === $family => self::ALREADY,
                $contact->getUser()?->getFamily() !== $family => self::NOT_MEMBER,
                // Такой же контакт уже общий (от другого члена семьи) — второй дал бы дубли на телефонах.
                isset($existing[$key]) => self::DUPLICATE,
                default => self::SHARED,
            };
            if ($status === self::SHARED) {
                $contact->setFamily($family);
                $existing[$key] = true;
            }
            ++$result[$status];
        }
        $this->em->flush();

        return $result;
    }

    public function unshare(Contact $contact): void
    {
        $contact->setFamily(null);
        $this->em->flush();
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
