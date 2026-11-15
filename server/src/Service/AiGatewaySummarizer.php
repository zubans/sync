<?php

namespace App\Service;

use App\Entity\AiAgentAccount;
use App\Entity\AiProvider;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Шлюз выполнения текстовых/JSON-запросов к ИИ (`NewsSummarizer`) поверх подключённых
 * провайдеров (`AiProvider`) и пула отдельных аккаунтов CLI-агентов (`AiAgentAccount`).
 *
 * Если таблица `ai_provider` пуста (свежая база / изолированные тесты), прозрачно
 * делегирует стандартному [AgyNewsSummarizer] без необходимости настройки БД.
 */
final class AiGatewaySummarizer implements NewsSummarizer
{
    public function __construct(
        private readonly AiProviderRepository $providers,
        private readonly AiAgentAccountRepository $accounts,
        private readonly AiAgentManager $agentManager,
        private readonly AgyNewsSummarizer $agySummarizer,
        private readonly CliProcessExecutor $executor,
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
    {
        if ($this->providers->countAll() === 0) {
            return $this->agySummarizer->complete($prompt, $model, $effort, $schema);
        }

        $enabled = $this->providers->findEnabledForSummarization();
        if ($enabled === []) {
            throw new \RuntimeException('Все подключённые провайдеры ИИ отключены в админке.');
        }

        $errors = [];
        foreach ($enabled as $provider) {
            $chosenModel = $model !== null && $model !== '' ? $model : $provider->getDefaultModel();
            try {
                if ($provider->isCliAgent()) {
                    return $this->completeViaCliProvider($provider, $prompt, $chosenModel, $effort, $schema);
                }

                return $this->completeViaHttpProvider($provider, $prompt, $chosenModel, $schema);
            } catch (\Throwable $e) {
                $errors[] = $provider->getName().': '.$e->getMessage();
            }
        }

        throw new \RuntimeException('Ни один активный провайдер ИИ не смог выполнить запрос: '.implode(' | ', $errors));
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private function completeViaCliProvider(
        AiProvider $provider,
        string $prompt,
        ?string $model,
        ?string $effort,
        ?array $schema,
    ): string {
        $enabledAccounts = $this->accounts->findEnabledForProvider($provider);
        $now = new \DateTimeImmutable();
        $readyAccounts = array_values(array_filter(
            $enabledAccounts,
            static fn (AiAgentAccount $acc) => !$acc->isCoolingNow($now),
        ));

        if ($provider->getType() === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            if ($this->accounts->count(['provider' => $provider]) > 0) {
                if ($readyAccounts === []) {
                    throw new \RuntimeException('У провайдера Antigravity CLI (agy) нет включённых и готовых аккаунтов.');
                }
                $homes = array_map(static fn (AiAgentAccount $acc) => $acc->getHomeDir(), $readyAccounts);
                $answer = $this->agySummarizer->completeWithAllowedHomes($homes, $prompt, $model, $effort, $schema);
                $readyAccounts[0]->markUsed();
                $this->em->flush();

                return $answer;
            }

            return $this->agySummarizer->complete($prompt, $model, $effort, $schema);
        }

        if ($readyAccounts === []) {
            throw new \RuntimeException(\sprintf('Нет активных аккаунтов для CLI-агента «%s».', $provider->getName()));
        }

        $lastError = null;
        foreach ($readyAccounts as $account) {
            if (!$this->agentManager->hasLoginArtifact($account)) {
                continue;
            }
            $this->agentManager->prepareIsolatedHome($account);
            $meta = AiAgentCatalog::get($account->getAgentType());
            $binary = (string) $meta['binary'];
            $env = $this->agentManager->buildAgentProcessEnv($account);
            $effectivePrompt = $this->appendSchemaInstruction($prompt, $schema);

            [$cmd, $stdin] = $this->buildCliCommand($account->getAgentType(), $binary, $effectivePrompt, $model);
            $res = $this->executor->run($cmd, $account->getHomeDir(), $env, $stdin, $provider->getTimeoutSeconds());
            $account->markUsed();

            if ($res['exitCode'] !== 0) {
                $err = trim($res['stderr'] ?: $res['stdout']) ?: 'Код выхода '.$res['exitCode'];
                $account->recordCheck(AiAgentAccount::STATUS_ERROR, $err);
                $this->em->flush();
                $lastError = new \RuntimeException($err);
                continue;
            }

            $answer = $this->extractCliAnswer($account->getAgentType(), $res['stdout']);
            if ($answer === '') {
                $lastError = new \RuntimeException(\sprintf('Агент %s вернул пустой ответ.', $binary));
                continue;
            }

            $account->recordCheck(AiAgentAccount::STATUS_READY);
            $this->em->flush();

            return $answer;
        }

        throw $lastError ?? new \RuntimeException(\sprintf('Нет авторизованных слотов для «%s».', $provider->getName()));
    }

    /**
     * @param array<string, mixed>|null $schema
     */
    private function completeViaHttpProvider(
        AiProvider $provider,
        string $prompt,
        ?string $model,
        ?array $schema,
    ): string {
        $baseUrl = $provider->getBaseUrl();
        if ($baseUrl === null || $baseUrl === '') {
            throw new \RuntimeException('Не задан URL провайдера.');
        }
        $effectivePrompt = $this->appendSchemaInstruction($prompt, $schema);
        $resolvedModel = $model !== null && $model !== '' ? $model : ($provider->getAvailableModels()[0] ?? '');
        if ($resolvedModel === '') {
            throw new \RuntimeException('Не выбрана модель для HTTP-провайдера.');
        }

        $headers = $provider->getHeaders();
        $apiKey = $this->agentManager->getDecryptedProviderKey($provider);

        if ($provider->getType() === AiProvider::TYPE_OLLAMA) {
            $payload = [
                'model' => $resolvedModel,
                'messages' => [['role' => 'user', 'content' => $effectivePrompt]],
                'stream' => false,
            ];
            if ($schema !== null) {
                $payload['format'] = $schema;
            }
            $response = $this->http->request('POST', rtrim($baseUrl, '/').'/api/chat', [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $provider->getTimeoutSeconds(),
            ]);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP '.$response->getStatusCode().': '.$response->getContent(false));
            }
            $data = $response->toArray(false);

            return trim((string) ($data['message']['content'] ?? $data['response'] ?? ''));
        }

        if ($provider->getType() === AiProvider::TYPE_ANTHROPIC) {
            if ($apiKey === null || $apiKey === '') {
                throw new \RuntimeException('Не задан API-ключ Anthropic.');
            }
            $response = $this->http->request('POST', rtrim($baseUrl, '/').'/v1/messages', [
                'headers' => $headers + [
                    'x-api-key' => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ],
                'json' => [
                    'model' => $resolvedModel,
                    'max_tokens' => 4096,
                    'messages' => [['role' => 'user', 'content' => $effectivePrompt]],
                ],
                'timeout' => $provider->getTimeoutSeconds(),
            ]);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP '.$response->getStatusCode().': '.$response->getContent(false));
            }
            $data = $response->toArray(false);
            $text = '';
            foreach ((array) ($data['content'] ?? []) as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= (string) ($block['text'] ?? '');
                }
            }

            return trim($text);
        }

        if ($provider->getType() === AiProvider::TYPE_GEMINI) {
            if ($apiKey === null || $apiKey === '') {
                throw new \RuntimeException('Не задан API-ключ Gemini.');
            }
            $body = [
                'contents' => [['parts' => [['text' => $effectivePrompt]]]],
            ];
            if ($schema !== null) {
                $body['generationConfig'] = ['responseMimeType' => 'application/json'];
            }
            $response = $this->http->request('POST', rtrim($baseUrl, '/').'/v1beta/models/'.$resolvedModel.':generateContent', [
                'query' => ['key' => $apiKey],
                'headers' => $headers,
                'json' => $body,
                'timeout' => $provider->getTimeoutSeconds(),
            ]);
            if ($response->getStatusCode() >= 400) {
                throw new \RuntimeException('HTTP '.$response->getStatusCode().': '.$response->getContent(false));
            }
            $data = $response->toArray(false);

            return trim((string) ($data['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        }

        // OpenAI и OpenAI-совместимые эндпоинты
        if ($apiKey !== null && $apiKey !== '') {
            $headers['Authorization'] = 'Bearer '.$apiKey;
        }
        $payload = [
            'model' => $resolvedModel,
            'messages' => [['role' => 'user', 'content' => $effectivePrompt]],
        ];
        if ($schema !== null) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $response = $this->http->request('POST', rtrim($baseUrl, '/').'/chat/completions', [
            'headers' => $headers,
            'json' => $payload,
            'timeout' => $provider->getTimeoutSeconds(),
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('HTTP '.$response->getStatusCode().': '.$response->getContent(false));
        }
        $data = $response->toArray(false);

        return trim((string) ($data['choices'][0]['message']['content'] ?? ''));
    }

    /**
     * @return array{0: list<string>, 1: ?string}
     */
    private function buildCliCommand(string $agentType, string $binary, string $prompt, ?string $model): array
    {
        return match ($agentType) {
            AiProvider::TYPE_CLAUDE_CLI => [
                array_values(array_filter([
                    $binary,
                    '-p',
                    '--output-format',
                    'json',
                    '--tools',
                    '',
                    '--strict-mcp-config',
                    $model !== null && $model !== '' ? '--model' : null,
                    $model !== null && $model !== '' ? $model : null,
                ], static fn ($v) => $v !== null)),
                $prompt,
            ],
            AiProvider::TYPE_CODEX_CLI => [
                array_values(array_filter([
                    $binary,
                    'exec',
                    '--json',
                    $model !== null && $model !== '' ? '-m' : null,
                    $model !== null && $model !== '' ? $model : null,
                    '-',
                ], static fn ($v) => $v !== null)),
                $prompt,
            ],
            AiProvider::TYPE_GEMINI_CLI => [
                array_values(array_filter([
                    $binary,
                    '-p',
                    $prompt,
                    '--output-format',
                    'json',
                    $model !== null && $model !== '' ? '--model' : null,
                    $model !== null && $model !== '' ? $model : null,
                ], static fn ($v) => $v !== null)),
                null,
            ],
            AiProvider::TYPE_KIMI_CLI => [
                array_values(array_filter([
                    $binary,
                    '--print',
                    '--output-format',
                    'stream-json',
                    $model !== null && $model !== '' ? '--model' : null,
                    $model !== null && $model !== '' ? $model : null,
                    '-p',
                    $prompt,
                ], static fn ($v) => $v !== null)),
                null,
            ],
            default => throw new \RuntimeException('Неподдерживаемый CLI-агент: '.$agentType),
        };
    }

    private function extractCliAnswer(string $agentType, string $stdout): string
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return '';
        }

        if ($agentType === AiProvider::TYPE_CLAUDE_CLI || $agentType === AiProvider::TYPE_GEMINI_CLI) {
            $decoded = json_decode($trimmed, true);
            if (\is_array($decoded)) {
                return trim((string) ($decoded['result'] ?? $decoded['response'] ?? $decoded['text'] ?? $trimmed));
            }
        }

        if ($agentType === AiProvider::TYPE_CODEX_CLI || $agentType === AiProvider::TYPE_KIMI_CLI) {
            $collected = [];
            foreach (explode("\n", $trimmed) as $line) {
                $ev = json_decode(trim($line), true);
                if (!\is_array($ev)) {
                    continue;
                }
                if (isset($ev['item']['text']) && \is_string($ev['item']['text'])) {
                    $collected[] = $ev['item']['text'];
                } elseif (isset($ev['content']) && \is_string($ev['content'])) {
                    $collected[] = $ev['content'];
                } elseif (isset($ev['result']['response']) && \is_string($ev['result']['response'])) {
                    $collected[] = $ev['result']['response'];
                }
            }
            if ($collected !== []) {
                return trim(implode("\n", $collected));
            }
        }

        return $trimmed;
    }

    /** @param array<string, mixed>|null $schema */
    private function appendSchemaInstruction(string $prompt, ?array $schema): string
    {
        if ($schema === null) {
            return $prompt;
        }

        return $prompt."\n\nОтветь строго валидным JSON по схеме (без пояснений вокруг JSON): "
            .json_encode($schema, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
    }
}
