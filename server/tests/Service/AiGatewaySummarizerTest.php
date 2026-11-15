<?php

namespace App\Tests\Service;

use App\Entity\AiProvider;
use App\Entity\User;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use App\Service\AgyNewsSummarizer;
use App\Service\AiAgentManager;
use App\Service\AiGatewaySummarizer;
use App\Service\CliProcessExecutor;
use App\Service\NewsEmbedder;
use App\Service\SecretBox;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AiGatewaySummarizerTest extends DatabaseWebTestCase
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

    public function testPriorityRoutingDisabledSkipAndFailover(): void
    {
        $c = static::getContainer();
        /** @var AiAgentManager $manager */
        $manager = $c->get(AiAgentManager::class);
        /** @var AiProviderRepository $providers */
        $providers = $c->get(AiProviderRepository::class);
        /** @var AiAgentAccountRepository $accounts */
        $accounts = $c->get(AiAgentAccountRepository::class);
        /** @var AgyNewsSummarizer $agy */
        $agy = $c->get(AgyNewsSummarizer::class);

        // Выключенный провайдер с более высоким приоритетом должен пропускаться.
        $manager->createProvider(
            name: 'Выключенный OpenAI',
            type: AiProvider::TYPE_OPENAI,
            baseUrl: 'https://api.openai.com/v1',
            apiKey: 'sk-disabled',
            priority: 5,
            defaultModel: 'gpt-4.1-mini',
            enabled: false,
        );
        // Первый активный провайдер (ollama) вернёт HTTP 500 — должен сработать откат на OpenAI-compatible.
        $manager->createProvider(
            name: 'Локальная Ollama',
            type: AiProvider::TYPE_OLLAMA,
            baseUrl: 'http://127.0.0.1:11434',
            apiKey: null,
            priority: 10,
            isLocal: true,
            defaultModel: 'gpt-oss:20b',
            enabled: true,
        );
        $manager->createProvider(
            name: 'Резервный OpenAI-совместимый',
            type: AiProvider::TYPE_OPENAI_COMPATIBLE,
            baseUrl: 'https://llm.example/v1',
            apiKey: 'sk-fallback-9999',
            priority: 20,
            defaultModel: 'qwen-plus',
            enabled: true,
        );

        $seenRequests = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$seenRequests) {
            $seenRequests[] = [$method, $url, $options['headers'] ?? []];
            if (str_contains($url, '127.0.0.1:11434')) {
                return new MockResponse('ollama down', ['http_code' => 500]);
            }

            return new MockResponse((string) json_encode([
                'choices' => [['message' => ['content' => 'Ответ резервного провайдера']]],
            ]));
        });

        $gateway = new AiGatewaySummarizer(
            $providers,
            $accounts,
            $manager,
            $agy,
            new CliProcessExecutor(),
            $http,
            $this->em,
        );

        $answer = $gateway->complete('Сделай краткую сводку', null);
        self::assertSame('Ответ резервного провайдера', $answer);
        self::assertCount(2, $seenRequests);
        self::assertSame('http://127.0.0.1:11434/api/chat', $seenRequests[0][1]);
        self::assertSame('https://llm.example/v1/chat/completions', $seenRequests[1][1]);
    }

    public function testCliAgentExecutionWithStubExecutorAndTokenEnv(): void
    {
        $c = static::getContainer();
        /** @var AiAgentManager $manager */
        $manager = $c->get(AiAgentManager::class);
        /** @var AiProviderRepository $providers */
        $providers = $c->get(AiProviderRepository::class);
        /** @var AiAgentAccountRepository $accounts */
        $accounts = $c->get(AiAgentAccountRepository::class);
        /** @var AgyNewsSummarizer $agy */
        $agy = $c->get(AgyNewsSummarizer::class);

        $accDisabled = $manager->createAgentAccount(
            agentType: 'claude',
            slot: 'disabled_slot',
            label: 'Отключённый Claude',
            token: 'sk-ant-disabled',
            priority: 1,
            enabled: false,
        );
        $accActive = $manager->createAgentAccount(
            agentType: 'claude',
            slot: 'active_slot',
            label: 'Рабочий Claude',
            token: 'sk-ant-oat-working',
            priority: 10,
            enabled: true,
        );
        $this->cleanupDirs[] = $accDisabled->getHomeDir();
        $this->cleanupDirs[] = $accActive->getHomeDir();

        $capturedCalls = [];
        $fakeExecutor = new class ($capturedCalls) extends CliProcessExecutor {
            public function __construct(private array &$calls)
            {
            }

            public function run(array $command, string $cwd, array $env, ?string $input = null, int $timeout = 120): array
            {
                $this->calls[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env, 'input' => $input];

                return ['exitCode' => 0, 'stdout' => "  Готово от Claude CLI  \n", 'stderr' => '', 'timedOut' => false];
            }
        };

        $gateway = new AiGatewaySummarizer(
            $providers,
            $accounts,
            $manager,
            $agy,
            $fakeExecutor,
            new MockHttpClient(),
            $this->em,
        );

        $answer = $gateway->complete('Промпт', 'sonnet');
        self::assertSame('Готово от Claude CLI', $answer);
        self::assertCount(1, $capturedCalls);
        self::assertSame($accActive->getHomeDir(), $capturedCalls[0]['env']['HOME']);
        self::assertSame('sk-ant-oat-working', $capturedCalls[0]['env']['CLAUDE_CODE_OAUTH_TOKEN']);
        self::assertSame('sonnet', $capturedCalls[0]['command'][array_search('--model', $capturedCalls[0]['command'], true) + 1]);
    }

    public function testNewsEmbedderRespectsDbEmbeddingsProviderAndDisabledState(): void
    {
        $c = static::getContainer();
        /** @var AiAgentManager $manager */
        $manager = $c->get(AiAgentManager::class);
        /** @var AiProviderRepository $providers */
        $providers = $c->get(AiProviderRepository::class);
        /** @var SecretBox $secretBox */
        $secretBox = $c->get(SecretBox::class);

        $p = $manager->createProvider(
            name: 'Gemini Эмбеддинги',
            type: AiProvider::TYPE_GEMINI,
            baseUrl: 'https://generativelanguage.googleapis.com',
            apiKey: 'gemini-key-abc123',
            priority: 10,
            defaultModel: 'gemini-embedding-001',
            useForSummarization: false,
            useForEmbeddings: true,
        );

        $embedder = new NewsEmbedder(
            new MockHttpClient(),
            url: 'http://127.0.0.1:11434',
            model: 'qwen3-embedding:4b',
            provider: NewsEmbedder::PROVIDER_OLLAMA,
            key: '',
            providers: $providers,
            secretBox: $secretBox,
        );

        self::assertTrue($embedder->isEnabled());
        self::assertSame(NewsEmbedder::PROVIDER_GEMINI, $embedder->provider());
        self::assertSame('gemini-embedding-001', $embedder->model());

        // При выключении провайдера эмбеддингов в БД сервис больше не считает эмбеддинги доступными.
        $manager->toggleProvider($p, false);
        self::assertFalse($embedder->isEnabled());
    }
}
