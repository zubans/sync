<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Service\DuplicateFinder;
use App\Tests\DatabaseWebTestCase;

final class DuplicateTest extends DatabaseWebTestCase
{
    private const PHONE_A = 'install-aaaa-0001';
    private const PHONE_B = 'install-bbbb-0002';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testFindsGroupsBySharedPhoneEmailAndName(): void
    {
        $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01']],
            ['externalId' => 'b', 'name' => 'Боря', 'phones' => ['8 (900) 000-00-01'], 'emails' => ['boris@example.com']],
            ['externalId' => 'c', 'name' => ' борис ', 'phones' => ['+7 900 000-00-99']],
            ['externalId' => 'd', 'name' => 'Б. Петров', 'phones' => ['+7 900 000-00-77'], 'emails' => ['Boris@example.com']],
            ['externalId' => 'e', 'name' => 'Вера', 'phones' => ['+7 900 000-00-02']],
        ]);
        // Такой же контакт у другого пользователя — не дубль.
        $this->createUser('oleg@example.com');
        $this->sync(self::PHONE_B, [['externalId' => 'x', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01']]], $this->login('oleg@example.com'));

        $groups = self::getContainer()->get(DuplicateFinder::class)->find();

        self::assertCount(1, $groups);
        self::assertSame(['Борис', 'Боря', 'борис', 'Б. Петров'], array_map(static fn (Contact $c) => $c->getName(), $groups[0]['contacts']));
        self::assertEqualsCanonicalizing(['общий телефон', 'общий email', 'одинаковое имя'], $groups[0]['reasons']);
    }

    public function testMergeCombinesDataTrashesOthersAndUpdatesPhones(): void
    {
        $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01']],
            ['externalId' => 'b', 'name' => 'Боря', 'phones' => ['8 (900) 000-00-01', '+7 900 000-00-99'], 'emails' => ['boris@example.com'], 'birthday' => '--05-17'],
        ]);
        // На втором телефоне только дубль.
        $b = $this->contact('Боря');
        $this->sync(self::PHONE_B, [['externalId' => 'b2', 'serverId' => $b->getUuid(), 'name' => 'Боря', 'phones' => ['8 (900) 000-00-01', '+7 900 000-00-99'], 'emails' => ['boris@example.com'], 'birthday' => '--05-17']]);
        $a = $this->contact('Борис');

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/duplicates');
        self::assertSelectorTextContains('.dup-group', 'общий телефон');
        $form = $crawler->filter('form.dup-group')->form();
        $form['primary']->select((string) $a->getId());
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/duplicates');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Объединено в «Борис»');
        self::assertSelectorTextContains('body', 'Дубликатов не найдено');

        $this->em->clear();
        $merged = $this->contact('Борис');
        self::assertSame(['+7 900 000-00-01', '+7 900 000-00-99'], $merged->getPhones());
        self::assertSame(['boris@example.com'], $merged->getEmails());
        self::assertSame('--05-17', $merged->getBirthday());
        self::assertTrue($this->contact('Боря')->isDeleted(), 'дубль — в корзине');

        // Телефон с обоими: основной переписывается, дубль удаляется.
        $result = $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01']],
            ['externalId' => 'b', 'name' => 'Боря', 'phones' => ['8 (900) 000-00-01']],
        ]);
        self::assertSame(['b'], $result['removed']);
        self::assertSame([['a', 'Борис', ['+7 900 000-00-01', '+7 900 000-00-99']]], array_map(static fn (array $u) => [$u['externalId'], $u['name'], $u['phones']], $result['updates']));

        // Телефон только с дублем: не теряет контакт, а получает объединённый вместо него.
        $result = $this->sync(self::PHONE_B, [['externalId' => 'b2', 'name' => 'Боря', 'phones' => ['8 (900) 000-00-01']]]);
        self::assertSame([], $result['removed']);
        self::assertSame([['b2', 'Борис', $merged->getUuid()]], array_map(static fn (array $u) => [$u['externalId'], $u['name'], $u['serverId']], $result['updates']));

        self::assertSame(['Борис'], array_column($this->api('GET', '/api/contacts', token: $this->token)['contacts'], 'name'));
    }

    public function testMergeRequiresPrimaryAmongSelected(): void
    {
        $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01']],
            ['externalId' => 'b', 'name' => 'Боря', 'phones' => ['+7 900 000-00-01']],
        ]);
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/duplicates');
        $form = $crawler->filter('form.dup-group')->form();
        $form['contacts'][1]->untick();
        $this->client->submit($form);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert-warning', 'хотя бы два');
        self::assertFalse($this->contact('Боря')->isDeleted());
    }

    private function contact(string $name): Contact
    {
        return $this->em->getRepository(Contact::class)->findOneBy(['name' => $name]) ?? self::fail('нет контакта '.$name);
    }

    private function sync(string $installId, array $contacts, ?string $token = null): array
    {
        $result = $this->api('POST', '/api/sync', ['device' => ['installId' => $installId], 'contacts' => $contacts], $token ?? $this->token);
        self::assertResponseIsSuccessful();

        return $result;
    }
}
