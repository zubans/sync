<?php

namespace App\Tests\Admin;

use App\Entity\Contact;
use App\Entity\ContactLink;
use App\Entity\Device;
use App\Entity\Family;
use App\Tests\DatabaseWebTestCase;

final class PhonebookAdminTest extends DatabaseWebTestCase
{
    public function testNonAdminForbiddenAndEmptyPhonebookRenders(): void
    {
        $regular = $this->createUser('user@example.com');
        $admin = $this->createUser('admin@example.com', admin: true);

        $this->client->loginUser($regular);
        $this->client->request('GET', '/admin/phonebook');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/phonebook');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Телефонные книги пользователей');
        self::assertSelectorTextContains('body', 'пока нет личных контактов');
    }

    public function testAdminCanBrowseAndFilterAnyUsersPhonebookAndExportVcf(): void
    {
        $admin = $this->createUser('admin@example.com', admin: true);
        $anna = $this->createUser('anna@example.com');
        $boris = $this->createUser('boris@example.com');
        $olga = $this->createUser('olga@example.com');

        $family = (new Family())->setName('Ивановы');
        $this->em->persist($family);
        $family->addMember($anna);
        $family->addMember($boris);

        $pixel = (new Device($anna, 'install-pixel-01'))->setModel('Pixel 8');
        $iphone = (new Device($anna, 'install-iphone-02'))->setModel('iPhone 15');
        $this->em->persist($pixel);
        $this->em->persist($iphone);

        $annaWork = Contact::personal($anna)
            ->setName('Иван Петров')
            ->setPhones(['+7 (900) 111-22-33'])
            ->setEmails(['ivan@example.com'])
            ->setBirthday('1990-05-14');
        $annaShared = Contact::personal($anna)
            ->setName('Мама АннаОбщая')
            ->setPhones(['+7 900 444-55-66'])
            ->setFamily($family);
        $annaTrashed = Contact::personal($anna)
            ->setName('Удалённый Вася')
            ->setPhones(['+7 900 999-00-00']);
        $annaTrashed->moveToTrash();

        $borisPrivate = Contact::personal($boris)
            ->setName('Скрытый Борис')
            ->setPhones(['+7 911 000-00-01']);
        $borisShared = Contact::personal($boris)
            ->setName('Дедушка Борис')
            ->setPhones(['+7 911 222-33-44'])
            ->setFamily($family);

        $olgaPrivate = Contact::personal($olga)
            ->setName('Коллега Ольга')
            ->setPhones(['+7 922 777-88-99']);

        foreach ([$annaWork, $annaShared, $annaTrashed, $borisPrivate, $borisShared, $olgaPrivate] as $c) {
            $this->em->persist($c);
        }
        $this->em->persist(new ContactLink($pixel, $annaWork, 'pix-1'));
        $this->em->persist(new ContactLink($pixel, $annaShared, 'pix-2'));
        $this->em->persist(new ContactLink($iphone, $annaWork, 'iph-1'));
        $this->em->flush();

        $this->client->loginUser($admin);

        // Действие «Телефонная книга» доступно в списке пользователей.
        $usersCrawler = $this->client->request('GET', '/admin/user');
        self::assertResponseIsSuccessful();
        $userPageHtml = $usersCrawler->html();
        self::assertStringContainsString('/admin/phonebook/'.$anna->getId(), $userPageHtml);
        self::assertStringContainsString('/admin/phonebook/'.$olga->getId(), $userPageHtml);

        // Открытие книги Анны через query ?user=.
        $crawler = $this->client->request('GET', '/admin/phonebook?user='.$anna->getId());
        self::assertResponseIsSuccessful();
        $body = $crawler->filter('main, .content-wrapper, body')->text();
        self::assertStringContainsString('anna@example.com', $body);
        self::assertStringContainsString('Иван Петров', $body);
        self::assertStringContainsString('Мама АннаОбщая', $body);
        self::assertStringContainsString('Pixel 8', $body);
        self::assertStringContainsString('iPhone 15', $body);
        self::assertStringNotContainsString('Скрытый Борис', $body);
        self::assertStringNotContainsString('Коллега Ольга', $body);

        // Фильтрация по устройству iPhone 15 оставляет только привязанный к нему контакт.
        $deviceText = $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'?device='.$iphone->getId())->text();
        self::assertStringContainsString('Иван Петров', $deviceText);
        self::assertStringNotContainsString('Мама АннаОбщая', $deviceText);

        // Поиск по цифрам телефона без скобок и пробелов.
        $searchText = $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'?q=9001112233')->text();
        self::assertStringContainsString('Иван Петров', $searchText);
        self::assertStringNotContainsString('Мама АннаОбщая', $searchText);

        // Вкладка «Доступны от семьи» показывает общие контакты других участников, но не личные Бориса.
        $familyText = $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'?tab=family')->text();
        self::assertStringContainsString('Дедушка Борис', $familyText);
        self::assertStringNotContainsString('Скрытый Борис', $familyText);
        self::assertStringNotContainsString('Мама АннаОбщая', $familyText);

        // Вкладка «Корзина» показывает удалённые контакты Анны.
        $trashText = $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'?tab=trash')->text();
        self::assertStringContainsString('Удалённый Вася', $trashText);
        self::assertStringContainsString('удалён в админке', $trashText);

        // Переключение на Бориса и Ольгу.
        $olgaText = $this->client->request('GET', '/admin/phonebook/'.$olga->getId())->text();
        self::assertStringContainsString('Коллега Ольга', $olgaText);
        self::assertStringNotContainsString('Иван Петров', $olgaText);

        $olgaFamilyText = $this->client->request('GET', '/admin/phonebook/'.$olga->getId().'?tab=family')->text();
        self::assertStringContainsString('не состоит в семье', $olgaFamilyText);

        // Экспорт .vcf (личные vs вся эффективная книга с семейными).
        $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'/export.vcf');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/vcard', (string) $this->client->getResponse()->headers->get('Content-Type'));
        $personalVcf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('FN:Иван Петров', $personalVcf);
        self::assertStringContainsString('TEL;TYPE=CELL:+7 (900) 111-22-33', $personalVcf);
        self::assertStringContainsString('EMAIL;TYPE=INTERNET:ivan@example.com', $personalVcf);
        self::assertStringContainsString('BDAY:1990-05-14', $personalVcf);
        self::assertStringNotContainsString('Удалённый Вася', $personalVcf);
        self::assertStringNotContainsString('Дедушка Борис', $personalVcf);

        $this->client->request('GET', '/admin/phonebook/'.$anna->getId().'/export.vcf?family=1');
        self::assertResponseIsSuccessful();
        $allVcf = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('FN:Иван Петров', $allVcf);
        self::assertStringContainsString('FN:Дедушка Борис', $allVcf);

        // Несуществующий пользователь -> 404.
        $this->client->request('GET', '/admin/phonebook/999999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDisablingContactsModuleHidesAndBlocksPhonebook(): void
    {
        $admin = $this->createUser('admin@example.com', admin: true);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/modules');
        self::assertResponseIsSuccessful();
        $menuBefore = $crawler->filter('.main-sidebar, #sidebar, aside')->text();
        self::assertStringContainsString('Телефонные книги', $menuBefore);

        $form = $crawler->filter('form[action$="/modules/save"]')->form();
        $form['contacts']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/modules');
        $crawlerAfter = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $menuAfter = $crawlerAfter->filter('.main-sidebar, #sidebar, aside')->text();
        self::assertStringNotContainsString('Телефонные книги', $menuAfter);

        $this->client->request('GET', '/admin/phonebook');
        self::assertResponseStatusCodeSame(503);
    }
}
