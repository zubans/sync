<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Entity\ContactTombstone;
use App\Entity\Family;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class ContactMoveTest extends DatabaseWebTestCase
{
    private User $anna;
    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();
        $this->anna = $this->createUser('anna@example.com');
        $this->family = (new Family())->setName('Ивановы');
        $this->em->persist($this->family);
        $this->family->addMember($this->anna);
        $this->em->flush();
    }

    public function testDraggedContactBecomesFamilyAndLeavesOwnersPhone(): void
    {
        $token = $this->login('anna@example.com');
        $sync = fn (?string $serverId = null) => $this->api('POST', '/api/sync', ['device' => ['installId' => 'install-aaaa-0001'], 'contacts' => [
            ['externalId' => 'a', 'serverId' => $serverId, 'name' => 'Бабушка', 'phones' => ['+7 900 111-11-11']],
        ]], $token);
        $serverId = $sync()['links'][0]['serverId'];

        $result = $this->move([$this->contactId('Бабушка')]);

        self::assertSame(['moved' => 1, 'merged' => 0, 'skipped' => 0, 'familyContacts' => 1], $result);
        self::assertNotNull($this->em->getRepository(ContactTombstone::class)->findOneBy(['uuid' => $serverId]));

        // Телефон владельца удаляет личную копию, а семейная приходит как семейная.
        self::assertSame(['a'], $sync($serverId)['removed']);
        $family = $this->api('GET', '/api/family/contacts', token: $token);
        self::assertSame(['Бабушка'], array_column($family['contacts'], 'name'));
        self::assertSame(['+7 900 111-11-11'], $family['contacts'][0]['phones']);
    }

    public function testSameContactAlreadyInFamilyIsNotDuplicated(): void
    {
        $this->em->persist((new Contact())->setFamily($this->family)->setName('бабушка')->setPhones(['8 (900) 111-11-11']));
        $personal = Contact::personal($this->anna)->setName('Бабушка')->setPhones(['+7 900 111-11-11']);
        $this->em->persist($personal);
        $this->em->flush();

        $result = $this->move([$personal->getId()]);

        self::assertSame([0, 1, 1], [$result['moved'], $result['merged'], $result['familyContacts']]);
        self::assertNull($this->em->getRepository(Contact::class)->find($personal->getId()));
    }

    public function testPageListsPersonalContactsAndFamilies(): void
    {
        $this->em->persist(Contact::personal($this->anna)->setName('Пётр')->setPhones(['+7 900 000-00-01']));
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $this->client->request('GET', '/admin/contact-move');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.cm-list', 'Пётр');
        self::assertSelectorExists('.cm-family[data-family-id="'.$this->family->getId().'"]');
    }

    public function testMoveRequiresValidCsrfTokenAndAdmin(): void
    {
        $contact = Contact::personal($this->anna)->setName('Пётр')->setPhones(['+7 900 000-00-01']);
        $this->em->persist($contact);
        $this->em->flush();

        $this->client->loginUser($this->anna);
        $this->client->request('GET', '/admin/contact-move');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $this->postMove(['token' => 'forged', 'familyId' => $this->family->getId(), 'contactIds' => [$contact->getId()]]);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->em->getRepository(Contact::class)->find($contact->getId()));
    }

    /**
     * @param list<int> $ids
     *
     * @return array<string, int>
     */
    private function move(array $ids): array
    {
        $this->client->loginUser($this->createUser('admin'.bin2hex(random_bytes(3)).'@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/contact-move');
        preg_match('/const token = "([^"]+)"/', $crawler->html(), $m);

        $this->postMove(['token' => $m[1], 'familyId' => $this->family->getId(), 'contactIds' => $ids]);
        self::assertResponseIsSuccessful();
        $this->em->clear();

        return json_decode($this->client->getResponse()->getContent(), true);
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
