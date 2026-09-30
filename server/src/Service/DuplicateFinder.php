<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\User;
use App\Repository\ContactRepository;

/**
 * Поиск возможных дублей среди контактов одного владельца: общий телефон (последние 10 цифр,
 * как на телефоне), общий email или одинаковое имя (в том числе с разными телефонами).
 * Связи транзитивны: А и Б с общим телефоном, Б и В с одинаковым именем — одна группа.
 */
final class DuplicateFinder
{
    public function __construct(private readonly ContactRepository $contacts)
    {
    }

    /**
     * @return list<array{user: User, contacts: list<Contact>, reasons: list<string>}> группы, самые большие первыми
     */
    public function find(?User $user = null): array
    {
        $qb = $this->contacts->createQueryBuilder('c')
            ->addSelect('u')
            ->join('c.user', 'u')
            ->where('c.deletedAt IS NULL')
            ->orderBy('c.id', 'ASC');
        if ($user !== null) {
            $qb->andWhere('c.user = :user')->setParameter('user', $user);
        }

        $byUser = [];
        foreach ($qb->getQuery()->getResult() as $contact) {
            $byUser[$contact->getUser()->getId()][] = $contact;
        }

        $groups = [];
        foreach ($byUser as $list) {
            array_push($groups, ...$this->groupsOf($list));
        }
        usort($groups, static fn (array $a, array $b) => [\count($b['contacts']), $a['contacts'][0]->getName()] <=> [\count($a['contacts']), $b['contacts'][0]->getName()]);

        return $groups;
    }

    /** Последние 10 цифр: +7 900 000-00-01 и 8 (900) 000-00-01 — один номер. */
    public static function phoneKey(string $phone): string
    {
        return substr(preg_replace('/\D+/', '', $phone), -10);
    }

    public static function nameKey(?string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtolower((string) $name)));
    }

    /**
     * @param list<Contact> $list контакты одного владельца
     *
     * @return list<array{user: User, contacts: list<Contact>, reasons: list<string>}>
     */
    private function groupsOf(array $list): array
    {
        $parent = array_keys($list);
        $find = static function (int $i) use (&$parent): int {
            while ($parent[$i] !== $i) {
                $i = $parent[$i] = $parent[$parent[$i]];
            }

            return $i;
        };

        // Ключ → первый контакт с ним и причина; каждый следующий с тем же ключом объединяется с первым.
        $seen = [];
        $reasons = [];
        foreach ($list as $i => $contact) {
            $keys = [];
            foreach ($contact->getPhones() as $phone) {
                if (($key = self::phoneKey($phone)) !== '') {
                    $keys['p:'.$key] = 'общий телефон';
                }
            }
            foreach ($contact->getEmails() as $email) {
                $keys['e:'.mb_strtolower($email)] = 'общий email';
            }
            if (($name = self::nameKey($contact->getName())) !== '') {
                $keys['n:'.$name] = 'одинаковое имя';
            }
            foreach ($keys as $key => $reason) {
                if (!isset($seen[$key])) {
                    $seen[$key] = $i;
                    continue;
                }
                $a = $find($seen[$key]);
                $b = $find($i);
                if ($a !== $b) {
                    $parent[$b] = $a;
                }
                $reasons[$key] = $reason;
            }
        }

        $members = [];
        foreach ($list as $i => $contact) {
            $members[$find($i)][] = $contact;
        }
        $reasonsByRoot = [];
        foreach ($reasons as $key => $reason) {
            $reasonsByRoot[$find($seen[$key])][$reason] = true;
        }

        $groups = [];
        foreach ($members as $root => $contacts) {
            if (\count($contacts) > 1) {
                $groups[] = ['user' => $contacts[0]->getUser(), 'contacts' => $contacts, 'reasons' => array_keys($reasonsByRoot[$root] ?? [])];
            }
        }

        return $groups;
    }
}
