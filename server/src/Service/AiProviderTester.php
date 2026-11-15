<?php

namespace App\Service;

use App\Entity\AiProvider;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Проверка подключения и загрузка списка моделей для HTTP и CLI провайдеров ИИ (по образцу KB `/api/providers/{id}/test`).
 */
final class AiProviderTester
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly AiAgentManager $agentManager,
        private readonly CliProcessExecutor $executor,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{ok: bool, message: string, modelsCount: int}
     */
    public function testConnection(AiProvider $provider): array
    {
        if ($provider->isCliAgent()) {
            return $this->testCliProvider($provider);
        }

        return $this->testHttpProvider($provider);
    }

    /**
     * @return array{added: list<string>, total: int, models: list<string>}
     */
    public function fetchModels(AiProvider $provider): array
    {
        $discovered = match ($provider->getType()) {
            AiProvider::TYPE_ANTIGRAVITY_CLI => $this->fetchAgyModels($provider),
            AiProvider::TYPE_CLAUDE_CLI,
            AiProvider::TYPE_CODEX_CLI,
            AiProvider::TYPE_GEMINI_CLI,
            AiProvider::TYPE_KIMI_CLI => AiAgentCatalog::get($provider->getType())['defaultModels'],
            AiProvider::TYPE_OLLAMA => $this->fetchOllamaModels($provider),
            AiProvider::TYPE_OPENAI, AiProvider::TYPE_OPENAI_COMPATIBLE => $this->fetchOpenAiModels($provider),
            AiProvider::TYPE_ANTHROPIC => $this->fetchAnthropicModels($provider),
            AiProvider::TYPE_GEMINI => $this->fetchGeminiModels($provider),
            default => [],
        };

        $existing = $provider->getAvailableModels();
        $added = array_values(array_diff($discovered, $existing));
        $merged = array_values(array_unique([...$existing, ...$discovered]));

        $provider->setAvailableModels($merged);
        if ($provider->getDefaultModel() === null && $merged !== []) {
            $provider->setDefaultModel($merged[0]);
        }
        $provider->recordCheck(true);
        $this->em->flush();

        return [
            'added' => $added,
            'total' => \count($merged),
            'models' => $merged,
        ];
    }

    /** @return array{ok: bool, message: string, modelsCount: int} */
    private function testCliProvider(AiProvider $provider): array
    {
        $meta = AiAgentCatalog::get($provider->getType());
        $binary = (string) $meta['binary'];
        if (!$this->executor->hasBinary($binary)) {
            $msg = \sprintf('Команда «%s» не установлена в контейнере.', $binary);
            $provider->recordCheck(false, $msg);
            $this->em->flush();

            return ['ok' => false, 'message' => $msg, 'modelsCount' => \count($provider->getAvailableModels())];
        }

        $accounts = $provider->getAccounts()->toArray();
        $enabledAccounts = array_values(array_filter($accounts, static fn ($acc) => $acc->isEnabled()));
        $readyCount = 0;
        foreach ($enabledAccounts as $acc) {
            if ($this->agentManager->hasLoginArtifact($acc)) {
                ++$readyCount;
            }
        }

        $res = $this->executor->run([$binary, '--version'], sys_get_temp_dir(), [
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ], null, 15);
        if ($res['exitCode'] !== 0) {
            $err = trim($res['stderr'] ?: $res['stdout']) ?: 'Код выхода '.$res['exitCode'];
            $provider->recordCheck(false, $err);
            $this->em->flush();

            return ['ok' => false, 'message' => $err, 'modelsCount' => \count($provider->getAvailableModels())];
        }

        if ($enabledAccounts !== [] && $readyCount === 0) {
            $msg = \sprintf('Бинарник «%s» доступен, но среди %d включённых слотов нет ни одного выполненного входа.', $binary, \count($enabledAccounts));
            $provider->recordCheck(false, $msg);
            $this->em->flush();

            return ['ok' => false, 'message' => $msg, 'modelsCount' => \count($provider->getAvailableModels())];
        }

        $ver = trim($res['stdout']);
        $msg = \sprintf('CLI «%s» доступен (%s); готовых аккаунтов: %d.', $binary, $ver !== '' ? $ver : 'OK', $readyCount);
        $provider->recordCheck(true);
        $this->em->flush();

        return ['ok' => true, 'message' => $msg, 'modelsCount' => \count($provider->getAvailableModels())];
    }

    /** @return array{ok: bool, message: string, modelsCount: int} */
    private function testHttpProvider(AiProvider $provider): array
    {
        try {
            $fetched = $this->fetchModels($provider);

            return [
                'ok' => true,
                'message' => \sprintf('Подключение успешно. Доступно моделей: %d.', $fetched['total']),
                'modelsCount' => $fetched['total'],
            ];
        } catch (\Throwable $e) {
            $provider->recordCheck(false, $e->getMessage());
            $this->em->flush();

            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'modelsCount' => \count($provider->getAvailableModels()),
            ];
        }
    }

    /** @return list<string> */
    private function fetchAgyModels(AiProvider $provider): array
    {
        if (!$this->executor->hasBinary('agy')) {
            throw new \RuntimeException('Бинарник «agy» не установлен в этой среде — список моделей взять нельзя.');
        }

        $home = sys_get_temp_dir();
        foreach ($provider->getAccounts() as $acc) {
            if ($acc->isEnabled() && $this->agentManager->hasLoginArtifact($acc)) {
                $home = $acc->getHomeDir();
                break;
            }
        }
        $res = $this->executor->run(['agy', 'models'], $home, [
            'HOME' => $home,
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ], null, 20);
        if ($res['exitCode'] !== 0) {
            throw new \RuntimeException(trim($res['stderr'] ?: $res['stdout']) ?: 'Команда `agy models` завершилась с ошибкой.');
        }

        $models = [];
        foreach (explode("\n", $res['stdout']) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with(mb_strtolower($trim), 'slug')) {
                continue;
            }
            $parts = preg_split("/\t+|\s{2,}/", $trim);
            $name = trim((string) ($parts[1] ?? $parts[0] ?? ''));
            if ($name !== '') {
                $models[] = $name;
            }
        }

        return array_values(array_unique($models)) ?: AiAgentCatalog::get(AiProvider::TYPE_ANTIGRAVITY_CLI)['defaultModels'];
    }

    /** @return list<string> */
    private function fetchOllamaModels(AiProvider $provider): array
    {
        $url = $this->requireBaseUrl($provider).'/api/tags';
        $res = $this->http->request('GET', $url, [
            'headers' => $provider->getHeaders(),
            'timeout' => min(30, $provider->getTimeoutSeconds()),
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException(\sprintf('Ollama: HTTP %d', $res->getStatusCode()));
        }
        $data = $res->toArray(false);
        $models = [];
        foreach ((array) ($data['models'] ?? []) as $item) {
            $name = trim((string) ($item['name'] ?? ''));
            if ($name !== '') {
                $models[] = $name;
            }
        }

        return array_values(array_unique($models));
    }

    /** @return list<string> */
    private function fetchOpenAiModels(AiProvider $provider): array
    {
        $url = $this->requireBaseUrl($provider).'/models';
        $headers = $provider->getHeaders();
        $key = $this->agentManager->getDecryptedProviderKey($provider);
        if ($key !== null && $key !== '') {
            $headers['Authorization'] = 'Bearer '.$key;
        }
        $res = $this->http->request('GET', $url, [
            'headers' => $headers,
            'timeout' => min(30, $provider->getTimeoutSeconds()),
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException(\sprintf('OpenAI-совместимый API: HTTP %d', $res->getStatusCode()));
        }
        $data = $res->toArray(false);
        $models = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return array_values(array_unique($models));
    }

    /** @return list<string> */
    private function fetchAnthropicModels(AiProvider $provider): array
    {
        $base = rtrim($provider->getBaseUrl() ?: 'https://api.anthropic.com', '/');
        $key = $this->agentManager->getDecryptedProviderKey($provider);
        if ($key === null || $key === '') {
            throw new \RuntimeException('Для Anthropic API укажите API-ключ.');
        }
        $res = $this->http->request('GET', $base.'/v1/models', [
            'headers' => $provider->getHeaders() + [
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
            ],
            'timeout' => min(30, $provider->getTimeoutSeconds()),
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException(\sprintf('Anthropic: HTTP %d', $res->getStatusCode()));
        }
        $data = $res->toArray(false);
        $models = [];
        foreach ((array) ($data['data'] ?? []) as $item) {
            $id = trim((string) ($item['id'] ?? ''));
            if ($id !== '') {
                $models[] = $id;
            }
        }

        return array_values(array_unique($models));
    }

    /** @return list<string> */
    private function fetchGeminiModels(AiProvider $provider): array
    {
        $base = rtrim($provider->getBaseUrl() ?: 'https://generativelanguage.googleapis.com', '/');
        $key = $this->agentManager->getDecryptedProviderKey($provider);
        if ($key === null || $key === '') {
            throw new \RuntimeException('Для Gemini API укажите API-ключ.');
        }
        $res = $this->http->request('GET', $base.'/v1beta/models', [
            'query' => ['key' => $key],
            'headers' => $provider->getHeaders(),
            'timeout' => min(30, $provider->getTimeoutSeconds()),
        ]);
        if ($res->getStatusCode() !== 200) {
            throw new \RuntimeException(\sprintf('Gemini API: HTTP %d', $res->getStatusCode()));
        }
        $data = $res->toArray(false);
        $models = [];
        foreach ((array) ($data['models'] ?? []) as $item) {
            $name = preg_replace('/^models\//', '', trim((string) ($item['name'] ?? '')));
            if (\is_string($name) && $name !== '') {
                $models[] = $name;
            }
        }

        return array_values(array_unique($models));
    }

    private function requireBaseUrl(AiProvider $provider): string
    {
        $url = $provider->getBaseUrl();
        if ($url === null || $url === '') {
            throw new \RuntimeException('Для HTTP-провайдера не задан Base URL.');
        }

        return rtrim($url, '/');
    }
}
