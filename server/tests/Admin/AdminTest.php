<?php

namespace App\Tests\Admin;

use App\Entity\Calendar;
use App\Entity\CalendarEvent;
use App\Entity\Contact;
use App\Entity\Device;
use App\Entity\Family;
use App\Entity\InstalledApp;
use App\Entity\User;
use App\Entity\Vault;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AdminTest extends DatabaseWebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/admin');

        self::assertResponseRedirects('/admin/login');
    }

    public function testRegularUserIsForbidden(): void
    {
        $this->client->loginUser($this->createUser('anna@example.com'));
        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testLoginFormAuthenticatesAdmin(): void
    {
        $this->createUser('admin@example.com', admin: true);

        $crawler = $this->client->request('GET', '/admin/login');
        $this->client->submit($crawler->selectButton('Войти')->form([
            '_username' => 'admin@example.com',
            '_password' => self::PASSWORD,
        ]));

        self::assertResponseRedirects();
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Пользователи');
    }

    public function testAdminSeesAllSections(): void
    {
        $anna = $this->createUser('anna@example.com');
        $this->em->persist(Contact::personal($anna)->setName('Борис')->setPhones(['+7 900 000-00-01']));
        $withPhoto = Contact::personal($anna);
        $withPhoto->apply('С фото', ['+7 900 000-00-02'], [], str_repeat('ab', 32));
        $this->em->persist($withPhoto);
        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $family->addMember($anna);
        $this->em->persist(Contact::personal($anna)->setFamily($family)->setName('Бабушка')->setPhones(['+7 900 000-00-03']));
        $device = new Device($anna, 'install-aaaa-0001');
        $this->em->persist($device);
        $app = new InstalledApp($device, 'org.example.notes');
        $app->update('Заметки', '2.1', 21, null, null, [['name' => 'base.apk', 'sha256' => str_repeat('a', 64), 'size' => 5242880]]);
        $this->em->persist($app);
        $this->em->persist(new Vault($anna, Vault::KDF_PBKDF2_SHA256, 600000, 'c2FsdA==', 'a2V5'));
        $calendar = new Calendar($anna, 'Дом');
        $this->em->persist($calendar);
        $event = (new CalendarEvent($calendar, '0192f0c1-4444-7000-8000-000000000001'))->setTitle('Родительское собрание');
        $event->touch($anna);
        $this->em->persist($event);
        $this->em->flush();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        foreach (['/admin', '/admin/user', '/admin/family', '/admin/device', '/admin/google-account', '/admin/installed-app', '/admin/vault', '/admin/calendar', '/admin/calendar-event'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $this->client->request('GET', '/admin/installed-app');
        self::assertSelectorTextContains('table', 'Заметки');
        self::assertSelectorTextContains('table', 'ожидает загрузки');
        self::assertSelectorTextContains('table', '5');

        $this->client->request('GET', '/admin/vault');
        self::assertSelectorTextContains('table', 'anna@example.com');

        $this->client->request('GET', '/admin/calendar-event');
        self::assertSelectorTextContains('table', 'Родительское собрание');
        self::assertSelectorTextContains('table', 'Дом');

        $this->client->request('GET', '/admin/contact');
        self::assertSelectorTextContains('table', 'Борис');
        self::assertSelectorTextNotContains('table', 'Null');
        self::assertSelectorExists('table img[src="/admin/contact-photo/'.str_repeat('ab', 32).'"]');
        // В «Контактах» все контакты, общие — с отметкой семьи.
        self::assertSelectorTextContains('table', 'Бабушка');
        self::assertSelectorTextContains('table', 'Ивановы');

        $this->client->request('GET', '/admin/family-contact');
        self::assertSelectorTextContains('table', 'Бабушка');
        self::assertSelectorTextNotContains('table', 'Борис');
    }

    public function testAdminCreatesUserWithHashedPassword(): void
    {
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/user/new');
        $this->client->submit($crawler->filter('form[name="User"]')->form([
            'User[email]' => 'New@Example.com',
            'User[plainPassword]' => 'new-password',
        ]));

        self::assertResponseRedirects();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => 'new@example.com']);
        self::assertNotNull($user);
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, 'new-password'));
    }

    public function testAdminAddsMembersToFamily(): void
    {
        $anna = $this->createUser('anna@example.com');
        $boris = $this->createUser('boris@example.com');
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/family/new');
        $form = $crawler->filter('form[name="Family"]')->form();
        $form['Family[name]'] = 'Ивановы';
        $form['Family[members]']->select([(string) $anna->getId(), (string) $boris->getId()]);
        $this->client->submit($form);

        self::assertResponseRedirects();
        $this->em->clear();
        $family = $this->em->getRepository(Family::class)->findOneBy(['name' => 'Ивановы']);
        self::assertSame(
            ['anna@example.com', 'boris@example.com'],
            $family->getMembers()->map(fn (User $u) => $u->getEmail())->toArray(),
        );
    }

    public function testAdminCreatesFamilyContact(): void
    {
        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $anna = $this->createUser('anna@example.com');
        $family->addMember($anna);
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/family-contact/new');
        $form = $crawler->filter('form[name="Contact"]')->form();
        $form['Contact[name]'] = 'Бабушка';
        $form['Contact[family]']->select((string) $family->getId());
        $form['Contact[user]']->select((string) $anna->getId());
        $values = $form->getPhpValues();
        $values['Contact']['phones'] = ['+7 900 111-11-11'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['name' => 'Бабушка']);
        self::assertSame($family->getId(), $contact->getFamily()->getId());
        self::assertSame($anna->getId(), $contact->getUser()->getId());
        self::assertSame(['+7 900 111-11-11'], $contact->getPhones());
    }

    public function testAdminEditIsSentToPhone(): void
    {
        $this->createUser('anna@example.com');
        $token = $this->login('anna@example.com');
        $sync = fn (string $name) => $this->api('POST', '/api/sync', [
            'device' => ['installId' => 'install-aaaa-0001'],
            'contacts' => [['externalId' => 'a', 'name' => $name, 'phones' => ['+7 900 000-00-01']]],
        ], $token);
        $sync('Борис');
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['name' => 'Борис']);
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/contact/'.$contact->getId().'/edit');
        $form = $crawler->filter('form[name="Contact"]')->form();
        $form['Contact[birthday]'] = '17.05.1980';
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'ГГГГ-ММ-ДД');

        $form['Contact[name]'] = 'Борис Петров';
        $form['Contact[birthday]'] = '--05-17';
        $this->client->submit($form);
        self::assertResponseRedirects();

        // В этой синхронизации побеждает сервер: телефон получает правку, его старые данные не применяются.
        $this->em->clear();
        $result = $sync('Борис');
        self::assertSame([['a', 'Борис Петров', '--05-17']], array_map(static fn (array $u) => [$u['externalId'], $u['name'], $u['birthday']], $result['updates']));
        self::assertSame('Борис Петров', $this->api('GET', '/api/contacts', token: $token)['contacts'][0]['name']);

        // Правка отправляется один раз.
        $result = $sync('Борис Петров');
        self::assertSame([], $result['updates']);
    }

    public function testFamilyContactOwnerMustBeFamilyMember(): void
    {
        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $outsider = $this->createUser('olga@example.com');
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/family-contact/new');
        $form = $crawler->filter('form[name="Contact"]')->form();
        $form['Contact[name]'] = 'Бабушка';
        $form['Contact[family]']->select((string) $family->getId());
        $form['Contact[user]']->select((string) $outsider->getId());
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Владелец не состоит в этой семье.');
    }
}
