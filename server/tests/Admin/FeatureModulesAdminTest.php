<?php

namespace App\Tests\Admin;

use App\Service\FeatureManager;
use App\Service\NodeRuntimeExporter;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class FeatureModulesAdminTest extends DatabaseWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $runtimeDir = static::getContainer()->get(NodeRuntimeExporter::class)->getRuntimeDir();
        (new Filesystem())->remove($runtimeDir);
    }

    public function testNonAdminForbiddenAndAdminCanToggleBlocksAndAiGranularity(): void
    {
        $regularUser = $this->createUser('user@example.com');
        $adminUser = $this->createUser('admin@example.com', admin: true);

        $this->client->loginUser($regularUser);
        $this->client->request('GET', '/admin/modules');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($adminUser);
        $crawler = $this->client->request('GET', '/admin/modules');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Данные устройства');
        self::assertSelectorTextContains('body', 'Обработка новостей с помощью ИИ');
        self::assertSelectorTextContains('body', 'ИИ: оценка влияния новостей на рынок');

        // Выключаем contacts, news (при этом market остаётся) и vpn; также выключаем ai_market_analysis.
        $form = $crawler->filter('form[action$="/modules/save"]')->form();
        $form['contacts']->untick();
        $form['news']->untick();
        $form['vpn']->untick();
        $form['ai_market_analysis']->untick();
        $this->client->submit($form);
        self::assertResponseRedirects('/admin/modules');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        // Сайдбар скрывает выключенные модули и оставляет открытыми Система/Пользователи/Приложения.
        $menuText = $this->client->getCrawler()->filter('.main-sidebar, #sidebar, aside')->text();
        self::assertStringContainsString('Модули и функции ИИ', $menuText);
        self::assertStringContainsString('Провайдеры ИИ и агенты', $menuText);
        self::assertStringNotContainsString('Узел и роль', $menuText);
        self::assertStringNotContainsString('Лента', $menuText);
        self::assertStringContainsString('Приложения', $menuText);

        // Прямой доступ к выключенным разделам админки возвращает HTTP 503.
        foreach (['/admin/vpn-node', '/admin/vpn-connection', '/admin/contact', '/admin/news-item'] as $blocked) {
            $this->client->request('GET', $blocked);
            self::assertResponseStatusCodeSame(503, 'Ожидался HTTP 503 для '.$blocked);
        }

        // Core и невыключенные модули продолжают отвечать 200 / 204.
        foreach (['/admin', '/admin/modules', '/admin/ai-providers', '/admin/user', '/admin/installed-app', '/generate_204'] as $allowed) {
            $this->client->request('GET', $allowed);
            $code = $this->client->getResponse()->getStatusCode();
            self::assertTrue(\in_array($code, [200, 204], true), sprintf('Маршрут %s вернул %d', $allowed, $code));
        }

        // Runtime экспортёр фиксирует отключение VPN.
        $syncResult = static::getContainer()->get(NodeRuntimeExporter::class)->sync();
        self::assertSame('disabled', $syncResult['status']);

        // Проверка API (отключённые -> 503 JSON, включённые -> 200).
        $token = $this->login('user@example.com');
        $contactsResp = $this->api('GET', '/api/contacts', token: $token);
        self::assertResponseStatusCodeSame(503);
        self::assertSame(FeatureManager::CONTACTS, $contactsResp['feature']);

        $this->api('GET', '/api/news', token: $token);
        self::assertResponseStatusCodeSame(503);

        $this->api('GET', '/api/apps', token: $token);
        self::assertResponseIsSuccessful();

        $this->api('GET', '/api/market/overview', token: $token);
        self::assertResponseIsSuccessful();

        // Проверка консольных команд при выключенных флагах: пропускаются с кодом 0 без запуска ИИ.
        $app = new Application(self::$kernel);
        foreach ([
            ['app:news:collect', 'отключён'],
            ['app:news:translate', 'отключён'],
            ['app:news:analyze', 'отключён'],
            ['app:news:digest', 'отключён'],
            ['app:news:market', 'отключён'],
            ['app:market:analyze', 'отключён'],
        ] as [$cmdName, $expectedWord]) {
            $tester = new CommandTester($app->find($cmdName));
            $code = $tester->execute([]);
            self::assertSame(Command::SUCCESS, $code);
            self::assertStringContainsString($expectedWord, $tester->getDisplay());
        }
    }
}
