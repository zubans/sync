<?php

namespace App\Tests\Admin;

use App\Entity\AiProvider;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class AiProvidersAdminTest extends DatabaseWebTestCase
{
    private array $cleanupDirs = [];

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        foreach ($this->cleanupDirs as $dir) {
            $fs->remove($dir);
        }
        parent::tearDown();
    }

    public function testAdminManagesProvidersAndConsoleBackedCliAgentSlots(): void
    {
        $this->client->loginUser($this->createUser('user@example.com'));
        $this->client->request('GET', '/admin/ai-providers');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('admin@example.com', admin: true));
        $crawler = $this->client->request('GET', '/admin/ai-providers');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Консольные CLI-агенты');
        self::assertSelectorTextContains('body', 'Подключить провайдера ИИ');

        // 1. Создаём HTTP провайдера OpenAI с API-ключом и проверяем, что ключ зашифрован и маскируется в UI.
        $providerForm = $crawler->filter('form[action$="/ai-providers/provider/create"]')->form();
        $providerForm['type']->select(AiProvider::TYPE_OPENAI);
        $providerForm['name'] = 'OpenAI Рабочий';
        $providerForm['api_key'] = 'sk-admin-super-secret-8765';
        $providerForm['default_model'] = 'gpt-4.1-mini';
        $providerForm['priority'] = '15';
        $this->client->submit($providerForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $body = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('OpenAI Рабочий', $body);
        self::assertStringContainsString('…8765', $body);
        self::assertStringNotContainsString('sk-admin-super-secret-8765', $body);

        // 2. Добавляем слот CLI-агента через форму, вызывающую консольную команду `app:ai:agent-add`.
        $agentForm = $crawler->filter('form[action$="/ai-providers/agent/add"]')->form();
        $agentForm['agent_type']->select(AiProvider::TYPE_CODEX_CLI);
        $agentForm['slot'] = 'work_codex';
        $agentForm['label'] = 'Codex рабочий слот';
        $agentForm['priority'] = '5';
        $this->client->submit($agentForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $crawler = $this->client->followRedirect();
        self::assertResponseIsSuccessful();

        $accRepo = static::getContainer()->get(AiAgentAccountRepository::class);
        $acc = $accRepo->findOneByTypeAndSlot(AiProvider::TYPE_CODEX_CLI, 'work_codex');
        self::assertNotNull($acc);
        $this->cleanupDirs[] = $acc->getHomeDir();
        self::assertDirectoryExists($acc->getHomeDir());

        $body = $this->client->getResponse()->getContent() ?: '';
        self::assertStringContainsString('Codex рабочий слот', $body);
        self::assertStringContainsString('make ai-login TYPE=codex ACCOUNT=work_codex', $body);

        // 3. Переключаем (вкл/выкл) слот агента через POST /agent/{id}/toggle (`app:ai:agent-toggle`).
        $toggleForm = $crawler->filter(sprintf('form[action$="/ai-providers/agent/%d/toggle"]', $acc->getId()))->form();
        $this->client->submit($toggleForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('выключен.', $this->client->getResponse()->getContent() ?: '');
        static::getContainer()->get('doctrine')->getManager()->clear();
        $accRepo = static::getContainer()->get(AiAgentAccountRepository::class);
        self::assertFalse($accRepo->find($acc->getId())?->isEnabled());

        // 4. Удаляем слот агента через POST /agent/{id}/delete (`app:ai:agent-remove`).
        $deleteForm = $crawler->filter(sprintf('form[action$="/ai-providers/agent/%d/delete"]', $acc->getId()))->form();
        $this->client->submit($deleteForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('удалила слот', $this->client->getResponse()->getContent() ?: '');
        static::getContainer()->get('doctrine')->getManager()->clear();
        $accRepo = static::getContainer()->get(AiAgentAccountRepository::class);
        self::assertNull($accRepo->find($acc->getId()));
        self::assertDirectoryDoesNotExist($acc->getHomeDir());

        // 5. Выключаем и удаляем HTTP провайдера OpenAI.
        $pRepo = static::getContainer()->get(AiProviderRepository::class);
        $openAi = $pRepo->findFirstByType(AiProvider::TYPE_OPENAI);
        self::assertNotNull($openAi);

        $crawler = $this->client->getCrawler();
        $pToggleForm = $crawler->filter(sprintf('form[action$="/ai-providers/provider/%d/toggle"]', $openAi->getId()))->form();
        $this->client->submit($pToggleForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $crawler = $this->client->followRedirect();
        static::getContainer()->get('doctrine')->getManager()->clear();
        $pRepo = static::getContainer()->get(AiProviderRepository::class);
        self::assertFalse($pRepo->find($openAi->getId())?->isEnabled());

        $pDelForm = $crawler->filter(sprintf('form[action$="/ai-providers/provider/%d/delete"]', $openAi->getId()))->form();
        $this->client->submit($pDelForm);
        self::assertResponseRedirects('/admin/ai-providers');
        $this->client->followRedirect();
        static::getContainer()->get('doctrine')->getManager()->clear();
        $pRepo = static::getContainer()->get(AiProviderRepository::class);
        self::assertNull($pRepo->find($openAi->getId()));
    }
}
