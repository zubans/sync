<?php

namespace App\Service;

use App\Entity\Contact;
use App\Entity\ContactLink;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Объединение дублей в основной контакт:
 * - телефоны и email — все, без повторов (телефоны сравниваются по последним 10 цифрам);
 * - имя, фото и день рождения — основного, а если у него пусто — первого из остальных, где есть;
 * - отметка «Семья» сохраняется, если она была хоть у одного;
 * - остальные уходят в корзину (их можно восстановить) и удаляются с телефонов;
 * - телефон, где был только дубль, не теряет контакт: его связь переходит к основному,
 *   и телефон получает объединённую версию вместо дубля. Телефоны с основным получают правку.
 */
final class ContactMerger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @param list<Contact> $others */
    public function merge(Contact $primary, array $others): void
    {
        $others = array_values(array_filter($others, static fn (Contact $c) => $c !== $primary));
        foreach ($others as $other) {
            if ($other->getUser() !== $primary->getUser()) {
                throw new \InvalidArgumentException('Объединять можно только контакты одного владельца.');
            }
        }
        if ($others === []) {
            return;
        }

        $all = [$primary, ...$others];
        $phones = [];
        foreach ($all as $contact) {
            foreach ($contact->getPhones() as $phone) {
                $phones[DuplicateFinder::phoneKey($phone) ?: $phone] ??= $phone;
            }
        }
        $emails = array_values(array_unique(array_merge(...array_map(static fn (Contact $c) => $c->getEmails(), $all))));
        $first = static fn (callable $get) => array_values(array_filter(array_map($get, $all), static fn ($v) => $v !== null && $v !== ''))[0] ?? null;

        $primary->apply(
            $first(static fn (Contact $c) => $c->getName()),
            array_values($phones),
            $emails,
            $first(static fn (Contact $c) => $c->getPhotoSha256()),
            $first(static fn (Contact $c) => $c->getBirthday()),
        );
        if (!$primary->isShared()) {
            $shared = $first(static fn (Contact $c) => $c->getFamily());
            if ($shared !== null && $primary->getUser()?->getFamily() === $shared) {
                $primary->setFamily($shared);
            }
        }
        $primary->markEditedOnServer();

        $links = $this->em->getRepository(ContactLink::class);
        $devicesWithPrimary = [];
        foreach ($links->findBy(['contact' => $primary]) as $link) {
            $devicesWithPrimary[$link->getDevice()->getId()] = true;
        }
        foreach ($others as $other) {
            foreach ($links->findBy(['contact' => $other]) as $link) {
                if (!isset($devicesWithPrimary[$link->getDevice()->getId()])) {
                    $link->relinkTo($primary);
                    $devicesWithPrimary[$link->getDevice()->getId()] = true;
                }
            }
            $other->moveToTrash();
        }

        $this->em->flush();
    }
}
