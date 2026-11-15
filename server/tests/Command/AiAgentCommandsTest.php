<?php

namespace App\Tests\Command;

use App\Entity\AiProvider;
use App\Repository\AiAgentAccountRepository;
use App\Service\SecretBox;
use App\Tests\DatabaseWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class AiAgentCommandsTest extends DatabaseWebTestCase
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

    public function testConsoleLifecycleForMultipleCliAgentVariants(): void
    {
        $app = new Application(self::$kernel);
        $accounts = static::getContainer()->get(AiAgentAccountRepository::class);
        $secretBox = static::getContainer()->get(SecretBox::class);

        $addCmd = new CommandTester($app->find('app:ai:agent-add'));
        $toggleCmd = new CommandTester($app->find('app:ai:agent-toggle'));
        $removeCmd = new CommandTester($app->find('app:ai:agent-remove'));
        $statusCmd = new CommandTester($app->find('app:ai:status'));

        // 1. Добавляем agy и разные варианты других CLI-агентов через консоль.
        $variants = [
            ['agentType' => 'agy', '--slot' => 'account99', '--label' => 'Тест Agy'],
            ['agentType' => 'claude', '--slot' => 'work', '--label' => 'Claude Work', '--token' => 'sk-ant-oat-test-1234'],
            ['agentType' => 'codex', '--slot' => 'main', '--label' => 'Codex Main'],
            ['agentType' => 'gemini-cli', '--slot' => 'g2', '--label' => 'Gemini CLI #2'],
            ['agentType' => 'kimi', '--slot' => 'k1', '--label' => 'Kimi Moonshot'],
        ];

        $created = [];
        foreach ($variants as $input) {
            $exit = $addCmd->execute($input + ['--json' => true]);
            self::assertSame(Command::SUCCESS, $exit, $addCmd->getDisplay());
            $data = json_decode(trim($addCmd->getDisplay()), true, 512, \JSON_THROW_ON_ERROR);
            self::assertTrue($data['ok']);
            $this->cleanupDirs[] = $data['homeDir'];
            self::assertDirectoryExists($data['homeDir']);
            $created[$data['agentType']] = $data;
        }

        // Проверяем изоляцию и шифрование setup-token у Claude CLI.
        $claudeAcc = $accounts->find($created[AiProvider::TYPE_CLAUDE_CLI]['id']);
        self::assertNotNull($claudeAcc);
        self::assertStringStartsWith('sbox:v1:', (string) $claudeAcc->getAuthTokenEncrypted());
        self::assertStringNotContainsString('sk-ant-oat-test', (string) $claudeAcc->getAuthTokenEncrypted());
        self::assertSame('sk-ant-oat-test-1234', $secretBox->decrypt($claudeAcc->getAuthTokenEncrypted()));
        self::assertSame('ready', $claudeAcc->getStatus());

        // 2. Выключаем и снова включаем слот Codex через консольную команду.
        $codexId = (string) $created[AiProvider::TYPE_CODEX_CLI]['id'];
        self::assertSame(Command::SUCCESS, $toggleCmd->execute(['identifier' => $codexId, '--disable' => true, '--json' => true]));
        $this->em->clear();
        self::assertFalse($accounts->find((int) $codexId)?->isEnabled());

        self::assertSame(Command::SUCCESS, $toggleCmd->execute(['identifier' => $codexId, '--enable' => true, '--json' => true]));
        $this->em->clear();
        self::assertTrue($accounts->find((int) $codexId)?->isEnabled());

        // 3. Проверяем сводный статус `app:ai:status` (псевдоним `app:agy:status`).
        self::assertSame(Command::SUCCESS, $statusCmd->execute([]));
        $statusDisplay = $statusCmd->getDisplay();
        self::assertStringContainsString('Подключённые провайдеры ИИ (БД)', $statusDisplay);
        self::assertStringContainsString('Аккаунты CLI-агентов (БД)', $statusDisplay);
        self::assertStringContainsString('Claude Work', $statusDisplay);
        self::assertStringContainsString('Kimi Moonshot', $statusDisplay);

        // 4. Удаляем созданные слоты и убеждаемся, что их каталоги удалены с диска.
        foreach ($created as $item) {
            $exit = $removeCmd->execute(['identifier' => (string) $item['id'], '--json' => true]);
            self::assertSame(Command::SUCCESS, $exit);
            if ($item['agentType'] !== AiProvider::TYPE_ANTIGRAVITY_CLI || $item['slot'] !== 'main') {
                self::assertDirectoryDoesNotExist($item['homeDir']);
            }
        }
        $this->em->clear();
        self::assertSame(0, $accounts->countAll());
    }
}
