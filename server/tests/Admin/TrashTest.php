<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Entity\ContactTombstone;
use App\Tests\DatabaseWebTestCase;

final class TrashTest extends DatabaseWebTestCase
{
    private const PHONE = 'install-aaaa-0001';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testAdminDeleteMovesToTrashAndRemovesFromPhone(): void
    {
        $this->sync([['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера']]);
        $contact = $this->contact('Борис');

        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/contact');
        self::assertSelectorTextContains('body', 'В корзину');
        $token = $crawler->filter('form[method="post"] input[name="token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/contact/'.$contact->getId().'/delete', ['token' => $token]);
        self::assertResponseRedirects();

        $this->client->request('GET', '/admin/contact');
        self::assertSelectorTextNotContains('table', 'Борис');
        $this->client->request('GET', '/admin/trash');
        self::assertSelectorTextContains('table', 'Борис');
        self::assertSelectorTextContains('table', 'в админке');

        $result = $this->sync([['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера']]);
        self::assertSame(['a'], $result['removed']);
        self::assertSame(['Вера'], array_column($this->api('GET', '/api/contacts', token: $this->token)['contacts'], 'name'));

        // Телефон удалил контакт — он остаётся в корзине, повторно не удаляется и не оживает.
        $result = $this->sync([['externalId' => 'b', 'name' => 'Вера']]);
        self::assertSame([[], 0], [$result['removed'], $result['deleted']]);
        $this->em->clear();
        self::assertTrue($this->contact('Борис')->isDeleted());
    }

    public function testRestoredContactReturnsToPhoneOnce(): void
    {
        $this->sync([['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера']]);
        $this->sync([['externalId' => 'b', 'name' => 'Вера']]);
        $contact = $this->contact('Борис');
        self::assertTrue($contact->isDeleted());

        $this->loginAdmin();
        $this->client->request('GET', '/admin/trash');
        self::assertSelectorTextContains('table', 'на телефоне');
        // Действие EasyAdmin — ссылка, отправляющая отдельную форму POST с токеном в URL.
        $this->client->request('POST', $this->client->getCrawler()->filter('form[action*="/restore"]')->attr('action'));
        self::assertResponseRedirects('/admin/trash');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'восстановлен');

        $result = $this->sync([['externalId' => 'b', 'name' => 'Вера']]);
        self::assertSame(['Борис'], array_column($result['restored'], 'name'));
        self::assertSame($contact->getUuid(), $result['restored'][0]['serverId']);

        // Телефон добавил контакт и прислал его с serverId: привязывается к тому же, без дубля и повторной доставки.
        $result = $this->sync([['externalId' => 'b', 'name' => 'Вера'], ['externalId' => 'a2', 'serverId' => $contact->getUuid(), 'name' => 'Борис']]);
        self::assertSame([[], 0], [$result['restored'], $result['created']]);
        self::assertSame(2, $this->em->getRepository(Contact::class)->count());
        $this->em->clear();
        self::assertFalse($this->contact('Борис')->isDeleted());
    }

    public function testExpiredTrashIsPurgedAndDeleteForeverLeavesTombstone(): void
    {
        $this->sync([['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера'], ['externalId' => 'c', 'name' => 'Глеб']]);
        $this->sync([['externalId' => 'c', 'name' => 'Глеб']]);
        $this->em->getConnection()->executeStatement(
            "UPDATE contact SET deleted_at = ? WHERE name = 'Борис'",
            [(new \DateTimeImmutable('-'.(Contact::TRASH_DAYS + 1).' days'))->format('Y-m-d H:i:s')],
        );

        $this->sync([['externalId' => 'c', 'name' => 'Глеб']]);
        $this->em->clear();
        self::assertNull($this->em->getRepository(Contact::class)->findOneBy(['name' => 'Борис']));
        self::assertTrue($this->contact('Вера')->isDeleted(), 'срок ещё не вышел');

        $vera = $this->contact('Вера');
        $this->loginAdmin();
        $crawler = $this->client->request('GET', '/admin/trash');
        self::assertSelectorTextContains('body', 'Удалить навсегда');
        $token = $crawler->filter('form[method="post"] input[name="token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/trash/'.$vera->getId().'/delete', ['token' => $token]);
        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Contact::class)->findOneBy(['name' => 'Вера']));
        self::assertNotNull($this->em->getRepository(ContactTombstone::class)->findOneBy(['uuid' => $vera->getUuid()]));
    }

    public function testRestoreRequiresValidToken(): void
    {
        $this->sync([['externalId' => 'a', 'name' => 'Борис']]);
        $this->sync([]);
        $contact = $this->contact('Борис');

        $this->loginAdmin();
        $this->client->request('POST', '/admin/trash/'.$contact->getId().'/restore?token=wrong');
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertTrue($this->contact('Борис')->isDeleted());
    }

    private function contact(string $name): Contact
    {
        return $this->em->getRepository(Contact::class)->findOneBy(['name' => $name]) ?? self::fail('нет контакта '.$name);
    }

    private function sync(array $contacts): array
    {
        $contacts = array_map(static fn (array $c) => $c + ['phones' => ['+7 900 000-00-0'.\strlen($c['name'])]], $contacts);
        $result = $this->api('POST', '/api/sync', ['device' => ['installId' => self::PHONE, 'model' => 'Pixel'], 'contacts' => $contacts], $this->token);
        self::assertResponseIsSuccessful();

        return $result;
    }

    private function loginAdmin(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
    }
}
