<?php

namespace App\Tests\Api;

use App\Entity\Contact;
use App\Entity\Family;
use App\Entity\GoogleAccount;
use App\Entity\User;
use App\Tests\DatabaseWebTestCase;

final class SyncApiTest extends DatabaseWebTestCase
{
    private const PHONE_A = 'install-aaaa-0001';
    private const PHONE_B = 'install-bbbb-0002';

    /** Минимальный валидный JPEG 1×1. */
    private const JPEG_1X1 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser('anna@example.com');
        $this->token = $this->login('anna@example.com');
    }

    public function testFirstSyncCreatesContactsAndReturnsLinks(): void
    {
        $result = $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис', 'phones' => ['+7 900 000-00-01', '+7 900 000-00-01'], 'emails' => ['Boris@Example.com']],
            ['externalId' => 'b', 'name' => 'Вера'],
        ], googleAccounts: ['Anna.Personal@gmail.com', 'anna.work@gmail.com']);

        self::assertSame([2, 0, 0, 2], [$result['created'], $result['updated'], $result['deleted'], $result['total']]);
        self::assertSame(['a', 'b'], array_column($result['links'], 'externalId'));

        $contacts = $this->personal();
        self::assertSame(['Борис', 'Вера'], array_column($contacts, 'name'));
        self::assertSame(['+7 900 000-00-01'], $contacts[0]['phones']);
        self::assertSame(['boris@example.com'], $contacts[0]['emails']);
        self::assertSame(array_column($result['links'], 'serverId'), array_column($contacts, 'serverId'));

        $accounts = $this->em->getRepository(GoogleAccount::class)->findBy([], ['email' => 'ASC']);
        self::assertSame(['anna.personal@gmail.com', 'anna.work@gmail.com'], array_map(fn (GoogleAccount $a) => $a->getEmail(), $accounts));
    }

    public function testResyncUpdatesChangedAndDeletesRemoved(): void
    {
        $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис'],
            ['externalId' => 'b', 'name' => 'Вера'],
            ['externalId' => 'c', 'name' => 'Глеб'],
        ]);

        $result = $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'name' => 'Борис'],
            ['externalId' => 'b', 'name' => 'Вера Петрова'],
            ['externalId' => 'd', 'name' => 'Дина'],
        ]);

        self::assertSame([1, 1, 1, 3], [$result['created'], $result['updated'], $result['deleted'], $result['total']]);
        self::assertSame(['Борис', 'Вера Петрова', 'Дина'], array_column($this->personal(), 'name'));
    }

    public function testSecondPhoneDoesNotDeleteContactsOfFirst(): void
    {
        $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'С телефона A']]);

        $result = $this->sync(self::PHONE_B, [['externalId' => 'x', 'name' => 'С телефона B']]);

        self::assertSame(0, $result['deleted']);
        self::assertSame(['С телефона A', 'С телефона B'], array_column($this->personal(), 'name'));
    }

    public function testRestoredContactIsLinkedInsteadOfDuplicated(): void
    {
        $serverId = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис']])['links'][0]['serverId'];

        // Телефон B восстановил контакт с сервера и выгружает его под своим LOOKUP_KEY.
        $result = $this->sync(self::PHONE_B, [['externalId' => 'b-key', 'serverId' => $serverId, 'name' => 'Борис']]);

        self::assertSame(0, $result['created']);
        self::assertSame($serverId, $result['links'][0]['serverId']);
        self::assertCount(1, $this->personal());

        // Удалили на телефоне B — удаляется и на сервере.
        $result = $this->sync(self::PHONE_B, []);
        self::assertSame(1, $result['deleted']);
        self::assertSame([], $this->personal());
    }

    public function testChangedLookupKeyWithKnownServerIdKeepsContact(): void
    {
        $serverId = $this->sync(self::PHONE_A, [['externalId' => 'old-key', 'name' => 'Борис']])['links'][0]['serverId'];

        $result = $this->sync(self::PHONE_A, [['externalId' => 'new-key', 'serverId' => $serverId, 'name' => 'Борис']]);

        self::assertSame([0, 0], [$result['created'], $result['deleted']]);
        self::assertSame([$serverId], array_column($this->personal(), 'serverId'));
    }

    public function testForeignServerIdIsIgnored(): void
    {
        $serverId = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Контакт Анны']])['links'][0]['serverId'];

        $this->createUser('boris@example.com');
        $borisToken = $this->login('boris@example.com');
        $result = $this->sync(self::PHONE_B, [['externalId' => 'x', 'serverId' => $serverId, 'name' => 'Попытка']], token: $borisToken);

        self::assertSame(1, $result['created']);
        self::assertNotSame($serverId, $result['links'][0]['serverId']);
        self::assertSame(['Контакт Анны'], array_column($this->personal(), 'name'));
    }

    public function testFamilyContactsAreSharedWithMembers(): void
    {
        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $grandma = (new Contact())->setFamily($family)->setName('Бабушка')->setPhones(['+7 900 111-11-11']);
        $this->em->persist($grandma);
        $boris = $this->createUser('boris@example.com');
        $family->addMember($boris);
        $this->em->flush();

        // Анна не в семье.
        $response = $this->api('GET', '/api/family/contacts', token: $this->token);
        self::assertNull($response['family']);
        self::assertSame([], $response['contacts']);

        // Борис в семье — получает общие контакты, даже если своих у него нет.
        $borisToken = $this->login('boris@example.com');
        $response = $this->api('GET', '/api/family/contacts', token: $borisToken);
        self::assertSame('Ивановы', $response['family']['name']);
        self::assertSame(['Бабушка'], array_column($response['contacts'], 'name'));
        self::assertSame([], $this->api('GET', '/api/contacts', token: $borisToken)['contacts']);
        self::assertSame('Ивановы', $this->api('GET', '/api/me', token: $borisToken)['family']['name']);

        // serverId семейного контакта нельзя «присвоить» как личный.
        $result = $this->sync(self::PHONE_A, [['externalId' => 'x', 'serverId' => $grandma->getUuid(), 'name' => 'Бабушка']], token: $borisToken);
        self::assertSame(1, $result['created']);
        self::assertNotSame($grandma->getUuid(), $result['links'][0]['serverId']);
    }

    public function testDeletionOnDeviceIsOnlyMarked(): void
    {
        $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера']]);

        $result = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис']]);

        self::assertSame(1, $result['deleted']);
        // На телефоны больше не восстанавливается…
        self::assertSame(['Борис'], array_column($this->personal(), 'name'));
        // …но на сервере остаётся с отметкой.
        $vera = $this->em->getRepository(Contact::class)->findOneBy(['name' => 'Вера']);
        self::assertNotNull($vera);
        self::assertNotNull($vera->getDeletedAt());
    }

    public function testDeletionByAdminRemovesContactFromDevice(): void
    {
        $serverId = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис'], ['externalId' => 'b', 'name' => 'Вера']])['links'][0]['serverId'];

        // Администратор удаляет контакт.
        $this->em->remove($this->em->getRepository(Contact::class)->findOneBy(['uuid' => $serverId]));
        $this->em->flush();

        // Телефон ещё не знает об этом и присылает контакт со своим serverId — сервер велит удалить его.
        $result = $this->sync(self::PHONE_A, [
            ['externalId' => 'a', 'serverId' => $serverId, 'name' => 'Борис'],
            ['externalId' => 'b', 'name' => 'Вера'],
        ]);

        self::assertSame(['a'], $result['removed']);
        self::assertSame([0, 1], [$result['created'], $result['total']]);
        self::assertSame(['Вера'], array_column($this->personal(), 'name'));
    }

    public function testContactDeletedOnOnePhoneButKeptOnAnotherIsRevived(): void
    {
        $serverId = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис']])['links'][0]['serverId'];
        $this->sync(self::PHONE_B, [['externalId' => 'b', 'serverId' => $serverId, 'name' => 'Борис']]);

        $this->sync(self::PHONE_A, []);
        self::assertSame([], $this->personal());

        // На телефоне B контакт по-прежнему есть — значит, он нужен.
        $result = $this->sync(self::PHONE_B, [['externalId' => 'b', 'serverId' => $serverId, 'name' => 'Борис']]);

        self::assertSame(1, $result['updated']);
        self::assertSame([$serverId], array_column($this->personal(), 'serverId'));
    }

    public function testContactsWithoutPhonesAreNotSynced(): void
    {
        // Служебные записи мессенджеров (Telegram и т. п.): только имя, без телефона — их нет в телефонной книге.
        $result = $this->api('POST', '/api/sync', [
            'device' => ['installId' => self::PHONE_A],
            'contacts' => [
                ['externalId' => 'tg', 'name' => 'Из Telegram', 'phones' => [], 'emails' => []],
                ['externalId' => 'mail', 'name' => 'Только email', 'phones' => ['  '], 'emails' => ['a@b.c']],
                ['externalId' => 'real', 'name' => 'С телефоном', 'phones' => ['+7 900 111-22-33']],
            ],
        ], $this->token);

        self::assertSame([1, 1], [$result['created'], $result['total']]);
        self::assertSame(['real'], array_column($result['links'], 'externalId'));
        self::assertSame(['С телефоном'], array_column($this->personal(), 'name'));
    }

    public function testContactPhotoIsRequestedUploadedAndServed(): void
    {
        $jpeg = base64_decode(self::JPEG_1X1);
        $sha = hash('sha256', $jpeg);

        $result = $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис', 'photo' => $sha]]);
        self::assertSame([$sha], $result['missingPhotos']);

        $upload = fn (string $bytes, string $as = '') => $this->client->request('PUT', '/api/contact-photos/'.($as ?: $sha), server: [
            'CONTENT_TYPE' => 'image/jpeg',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], content: $bytes);

        $upload('not an image at all');
        self::assertResponseStatusCodeSame(422);
        $upload($jpeg, str_repeat('a', 64));
        self::assertResponseStatusCodeSame(404);
        $upload($jpeg);
        self::assertResponseStatusCodeSame(204);

        // Второй раз сервер фото уже не просит, а при восстановлении отдаёт.
        self::assertSame([], $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис', 'photo' => $sha]])['missingPhotos']);
        self::assertSame($sha, $this->personal()[0]['photo']);
        $this->client->request('GET', '/api/contact-photos/'.$sha, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token]);
        self::assertResponseIsSuccessful();
        self::assertSame($jpeg, $this->client->getInternalResponse()->getContent());

        // Чужое фото недоступно.
        $this->createUser('boris@example.com');
        $this->client->request('GET', '/api/contact-photos/'.$sha, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->login('boris@example.com')]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeletingUserRemovesTheirData(): void
    {
        $this->sync(self::PHONE_A, [['externalId' => 'a', 'name' => 'Борис']], googleAccounts: ['anna@gmail.com']);

        $this->em->clear();
        $this->em->remove($this->em->getRepository(User::class)->findOneBy(['email' => 'anna@example.com']));
        $this->em->flush();

        $connection = $this->em->getConnection();
        foreach (['contact', 'contact_link', 'contact_tombstone', 'device', 'google_account', 'api_token'] as $table) {
            self::assertSame(0, (int) $connection->fetchOne("SELECT COUNT(*) FROM $table"), $table);
        }
    }

    public function testInvalidPayloadIsRejected(): void
    {
        $this->api('POST', '/api/sync', ['device' => ['installId' => 'bad'], 'contacts' => []], $this->token);
        self::assertResponseStatusCodeSame(422);

        $this->api('POST', '/api/sync', [
            'device' => ['installId' => self::PHONE_A],
            'contacts' => [['externalId' => '', 'name' => 'Без id']],
        ], $this->token);
        self::assertResponseStatusCodeSame(422);
    }

    /**
     * @param list<array<string, mixed>> $contacts
     * @param list<string>               $googleAccounts
     *
     * @return array<string, mixed>
     */
    private function sync(string $installId, array $contacts, array $googleAccounts = [], ?string $token = null): array
    {
        // Синхронизируются только контакты с телефоном — тестам, где номер не важен, подставляем его.
        $contacts = array_map(static fn (array $c) => $c + ['phones' => ['+7 900 000-00-00']], $contacts);

        $result = $this->api('POST', '/api/sync', [
            'device' => ['installId' => $installId, 'model' => 'Pixel'],
            'googleAccounts' => $googleAccounts,
            'contacts' => $contacts,
        ], $token ?? $this->token);
        self::assertResponseIsSuccessful();

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function personal(): array
    {
        $response = $this->api('GET', '/api/contacts', token: $this->token);
        self::assertResponseIsSuccessful();

        return $response['contacts'];
    }
}
