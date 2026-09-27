<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Entity\ContactTombstone;
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
        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $this->em->persist((new Contact())->setFamily($family)->setName('Бабушка'));
        $device = new Device($anna, 'install-aaaa-0001');
        $this->em->persist($device);
        $app = new InstalledApp($device, 'org.example.notes');
        $app->update('Заметки', '2.1', 21, null, null, [['name' => 'base.apk', 'sha256' => str_repeat('a', 64), 'size' => 5242880]]);
        $this->em->persist($app);
        $this->em->persist(new Vault($anna, Vault::KDF_PBKDF2_SHA256, 600000, 'c2FsdA==', 'a2V5'));
        $this->em->flush();

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        foreach (['/admin', '/admin/user', '/admin/family', '/admin/device', '/admin/google-account', '/admin/installed-app', '/admin/vault'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }

        $this->client->request('GET', '/admin/installed-app');
        self::assertSelectorTextContains('table', 'Заметки');
        self::assertSelectorTextContains('table', 'ожидает загрузки');
        self::assertSelectorTextContains('table', '5');

        $this->client->request('GET', '/admin/vault');
        self::assertSelectorTextContains('table', 'anna@example.com');

        $this->client->request('GET', '/admin/contact');
        self::assertSelectorTextContains('table', 'Борис');
        self::assertSelectorTextNotContains('table', 'Null');
        self::assertSelectorTextNotContains('table', 'Бабушка');

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
        $this->em->flush();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        $crawler = $this->client->request('GET', '/admin/family-contact/new');
        $form = $crawler->filter('form[name="Contact"]')->form();
        $form['Contact[name]'] = 'Бабушка';
        $form['Contact[family]']->select((string) $family->getId());
        $values = $form->getPhpValues();
        $values['Contact']['phones'] = ['+7 900 111-11-11'];
        $this->client->request('POST', $form->getUri(), $values);

        self::assertResponseRedirects();
        $contact = $this->em->getRepository(Contact::class)->findOneBy(['name' => 'Бабушка']);
        self::assertSame($family->getId(), $contact->getFamily()->getId());
        self::assertNull($contact->getUser());
        self::assertSame(['+7 900 111-11-11'], $contact->getPhones());
    }

    public function testDeletingContactInAdminLeavesTombstone(): void
    {
        $anna = $this->createUser('anna@example.com');
        $contact = Contact::personal($anna)->setName('Борис');
        $this->em->persist($contact);
        $this->em->flush();
        $uuid = $contact->getUuid();
        $this->client->loginUser($this->createUser('admin@example.com', admin: true));

        // Кнопка «Удалить» в EasyAdmin отправляет общую форму подтверждения с CSRF-токеном ea-delete.
        $crawler = $this->client->request('GET', '/admin/contact');
        $token = $crawler->filter('#action-confirmation-modal form input[name="token"], form[method="post"] input[name="token"]')->first()->attr('value');
        $this->client->request('POST', '/admin/contact/'.$contact->getId().'/delete', ['token' => $token]);

        self::assertResponseRedirects();
        $this->em->clear();
        self::assertNull($this->em->getRepository(Contact::class)->findOneBy(['uuid' => $uuid]));
        $tombstone = $this->em->getRepository(ContactTombstone::class)->findOneBy(['uuid' => $uuid]);
        self::assertNotNull($tombstone);
        self::assertSame('anna@example.com', $tombstone->getUser()->getEmail());
    }
}
