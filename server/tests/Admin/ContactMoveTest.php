<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Entity\ContactTombstone;
use App\Entity\Family;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

/**
 * Семья — отметка общего доступа: добавление в семью и удаление из неё не копируют и не удаляют контакт.
 */
final class ContactMoveTest extends DatabaseWebTestCase
{
    private User $anna;
    private User $boris;
    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->createUser('anna@example.com');
        $this->boris = $this->createUser('boris@example.com');
        $this->family = (new Family())->setName('Ивановы');
        $this->em->persist($this->family);
        $this->family->addMember($this->anna);
        $this->family->addMember($this->boris);
        $this->em->flush();
    }

    public function testSharedContactStaysWithOwnerAndReachesOtherMembers(): void
    {
        $annaToken = $this->login('anna@example.com');
        $borisToken = $this->login('boris@example.com');
        $sync = fn (string $token, array $contacts) => $this->api('POST', '/api/sync', ['device' => ['installId' => 'install-'.substr($token, 0, 12)], 'contacts' => $contacts], $token);
        $wife = ['externalId' => 'a', 'name' => 'Жена', 'phones' => ['+7 920 702-04-33']];
        $serverId = $sync($annaToken, [$wife])['links'][0]['serverId'];

        $result = $this->shareViaDragAndDrop([$this->contactId('Жена')]);

        self::assertSame(1, $result['shared']);
        self::assertSame(1, $result['familyContacts']);
        // Ничего не удалено: ни надгробий, ни «удалить» для телефона владельца, контакт тот же.
        self::assertSame(0, $this->em->getRepository(ContactTombstone::class)->count([]));
        $again = $sync($annaToken, [$wife + ['serverId' => $serverId]]);
        self::assertSame([[], 0], [$again['removed'], $again['deleted']]);
        self::assertSame([$serverId], array_column($this->api('GET', '/api/contacts', token: $annaToken)['contacts'], 'serverId'));
        // Своё владелец в семейном списке не получает, остальные — получают.
        self::assertSame([], $this->api('GET', '/api/family/contacts', token: $annaToken)['contacts']);
        self::assertSame([$serverId], array_column($this->api('GET', '/api/family/contacts', token: $borisToken)['contacts'], 'serverId'));
    }

    public function testUnshareKeepsContactWithOwner(): void
    {
        $contact = Contact::personal($this->anna)->setName('Жена')->setPhones(['+79207020433'])->setFamily($this->family);
        $this->em->persist($contact);
        $this->em->flush();
        $borisToken = $this->login('boris@example.com');

        $this->clickSharingAction('/admin/family-contact', $contact->getId(), 'unshare');

        self::assertResponseRedirects();
        $this->em->clear();
        $reloaded = $this->em->getRepository(Contact::class)->find($contact->getId());
        self::assertSame($this->anna->getId(), $reloaded->getUser()->getId());
        self::assertNull($reloaded->getFamily());
        self::assertSame([], $this->api('GET', '/api/family/contacts', token: $borisToken)['contacts']);
    }

    public function testDuplicateAndForeignContactsAreNotShared(): void
    {
        $this->em->persist(Contact::personal($this->boris)->setName('бабушка')->setPhones(['8 (900) 111-11-11'])->setFamily($this->family));
        $same = Contact::personal($this->anna)->setName('Бабушка')->setPhones(['+7 900 111-11-11']);
        $outsider = Contact::personal($this->createUser('olga@example.com'))->setName('Чужой')->setPhones(['+7 900 222-22-22']);
        $this->em->persist($same);
        $this->em->persist($outsider);
        $this->em->flush();

        $result = $this->shareViaDragAndDrop([$same->getId(), $outsider->getId()]);

        self::assertSame([0, 1, 1], [$result['shared'], $result['duplicate'], $result['notMember']]);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Contact::class)->find($same->getId())->getFamily());
    }

    public function testLeavingFamilyUnsharesOwnContacts(): void
    {
        $contact = Contact::personal($this->anna)->setName('Жена')->setPhones(['+79207020433'])->setFamily($this->family);
        $this->em->persist($contact);
        $this->em->flush();

        $this->family->removeMember($this->anna);
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->em->getRepository(Contact::class)->find($contact->getId());
        self::assertNull($reloaded->getFamily());
        self::assertSame($this->anna->getId(), $reloaded->getUser()->getId());
    }

    public function testDeletingFamilyKeepsContacts(): void
    {
        $contact = Contact::personal($this->anna)->setName('Жена')->setPhones(['+79207020433'])->setFamily($this->family);
        $this->em->persist($contact);
        $this->em->flush();

        $this->em->remove($this->family);
        $this->em->flush();
        $this->em->clear();

        self::assertNotNull($this->em->getRepository(Contact::class)->find($contact->getId()));
    }

    public function testSharingRequiresAdminAndValidToken(): void
    {
        $contact = Contact::personal($this->anna)->setName('Жена')->setPhones(['+79207020433']);
        $this->em->persist($contact);
        $this->em->flush();

        $this->client->loginUser($this->anna);
        $this->client->request('GET', '/admin/contact-move');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->client->request('POST', '/admin/contact/'.$contact->getId().'/share?token=forged');
        self::assertResponseStatusCodeSame(403);
        $this->postMove(['token' => 'forged', 'familyId' => $this->family->getId(), 'contactIds' => [$contact->getId()]]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Contact::class)->find($contact->getId())->getFamily());
    }

    public function testShareActionFromContactsList(): void
    {
        $contact = Contact::personal($this->anna)->setName('Жена')->setPhones(['+79207020433']);
        $this->em->persist($contact);
        $this->em->flush();

        $this->clickSharingAction('/admin/contact', $contact->getId(), 'share');

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertSame($this->family->getId(), $this->em->getRepository(Contact::class)->find($contact->getId())->getFamily()->getId());
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, mixed>
     */
    private function shareViaDragAndDrop(array $ids): array
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/contact-move');
        preg_match('/const token = "([^"]+)"/', $crawler->html(), $m);

        $this->postMove(['token' => $m[1], 'familyId' => $this->family->getId(), 'contactIds' => $ids]);
        self::assertResponseIsSuccessful();
        $this->em->clear();

        return json_decode($this->client->getResponse()->getContent(), true);
    }

    /** Нажимает кнопку действия в списке: берёт из страницы форму с URL и CSRF-токеном и отправляет её. */
    private function clickSharingAction(string $listUrl, int $contactId, string $action): void
    {
        $this->loginAdmin();
        $crawler = $this->client->request('GET', $listUrl);
        $form = $crawler->filter(\sprintf('form[action*="/admin/contact/%d/%s?"]', $contactId, $action));
        self::assertCount(1, $form, "Нет кнопки «$action» для контакта $contactId");
        $this->client->request('POST', $form->attr('action'), server: ['HTTP_REFERER' => 'http://localhost'.$listUrl]);
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->createUser('admin'.bin2hex(random_bytes(3)).'@example.com', admin: true));
    }

    /** @param array<string, mixed> $payload */
    private function postMove(array $payload): void
    {
        $this->client->request('POST', '/admin/contact-move/move', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
    }

    private function contactId(string $name): int
    {
        return $this->em->getRepository(Contact::class)->findOneBy(['name' => $name])->getId();
    }
}
