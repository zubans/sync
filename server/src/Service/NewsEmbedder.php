<?php

namespace App\Service;

use App\Entity\AiProvider;
use App\Repository\AiProviderRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Векторы текстов для сюжетов. Провайдер берётся из включённого в админке ИИ-провайдера для эмбеддингов
 * (если настроен в БД), иначе из `.env` (`NEWS_EMBEDDINGS_PROVIDER`):
 * - `gemini` — Gemini API, модель `gemini-embedding-001`, режим `CLUSTERING` (под группировку похожих текстов),
 *   пачками до 100 текстов; ключ — `NEWS_EMBEDDINGS_KEY` из Google AI Studio. Одно событие на русском и английском —
 *   сходство ~0,90 (проверено с VPS 16.10.2026), стоимость — меньше доллара в месяц;
 * - `ollama` — `/api/embed` на машине KB (`NEWS_EMBEDDINGS_URL`), модель qwen3-embedding на процессоре, чтобы
 *   не вытеснять чат-модель KB из видеопамяти: медленно, сходство ru/en ~0,73;
 * - `openai` / `openai_compatible` — `/embeddings` (через админку ИИ-провайдеров).
 * Не настроено или провайдер отключён — векторов нет, сюжетные линии не строятся, остальное работает.
 */
final class NewsEmbedder
{
    public const DIMS = 1024;
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OLLAMA = 'ollama';
    public const PROVIDER_OPENAI = 'openai';
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';
    /** Ollama на процессоре — пакет из 16 заголовков десятки секунд; Gemini принимает до 100 текстов за запрос. */
    private const OLLAMA_BATCH = 16;
    private const GEMINI_BATCH = 100;
    private const OPENAI_BATCH = 64;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(NEWS_EMBEDDINGS_URL)%')]
        private readonly string $url = '',
        #[Autowire('%env(NEWS_EMBEDDINGS_MODEL)%')]
        private readonly string $model = 'qwen3-embedding:4b',
        #[Autowire('%env(NEWS_EMBEDDINGS_PROVIDER)%')]
        private readonly string $provider = self::PROVIDER_OLLAMA,
        #[Autowire('%env(NEWS_EMBEDDINGS_KEY)%')]
        private readonly string $key = '',
        private readonly ?AiProviderRepository $providers = null,
        private readonly ?SecretBox $secretBox = null,
    ) {
    }

    public function isEnabled(): bool
    {
        $target = $this->resolveTarget();

        return match ($target['provider']) {
            self::PROVIDER_GEMINI => $target['key'] !== '',
            self::PROVIDER_OPENAI => $target['url'] !== '' && $target['key'] !== '',
            default => $target['url'] !== '',
        };
    }

    public function provider(): string
    {
        return $this->resolveTarget()['provider'];
    }

    public function model(): string
    {
        return $this->resolveTarget()['model'];
    }

    /** Сколько текстов за запрос: столько новостей сервис сохраняет за раз, чтобы отказ не терял уже посчитанное. */
    public function batchSize(): int
    {
        return match ($this->provider()) {
            self::PROVIDER_GEMINI => self::GEMINI_BATCH,
            self::PROVIDER_OPENAI => self::OPENAI_BATCH,
            default => self::OLLAMA_BATCH,
        };
    }

    /** Что не настроено — для текста ошибки. */
    public function missing(): string
    {
        $target = $this->resolveTarget();

        return match ($target['provider']) {
            self::PROVIDER_GEMINI => 'NEWS_EMBEDDINGS_KEY не задан или провайдер отключён',
            self::PROVIDER_OPENAI => 'URL/API-ключ OpenAI-эмбеддингов не задан или провайдер отключён',
            default => 'NEWS_EMBEDDINGS_URL не задан или провайдер отключён',
        };
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> векторы по [DIMS] значений, в порядке текстов
     *
     * @throws NewsEmbeddingRateLimit отказ по лимиту провайдера
     * @throws \RuntimeException       провайдер не ответил
     */
    public function embed(array $texts): array
    {
        $target = $this->resolveTarget();

        return match ($target['provider']) {
            self::PROVIDER_GEMINI => $this->gemini($texts, $target['model'], $target['key']),
            self::PROVIDER_OPENAI => $this->openai($texts, $target['url'], $target['model'], $target['key'], $target['headers']),
            default => $this->ollama($texts, $target['url'], $target['model']),
        };
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function gemini(array $texts, string $model, string $key): array
    {
        $vectors = [];
        foreach (array_chunk($texts, self::GEMINI_BATCH) as $batch) {
            $response = $this->http->request('POST', sprintf(self::GEMINI_URL, $model), [
                'headers' => ['x-goog-api-key' => $key],
                'json' => ['requests' => array_map(fn (string $text) => [
                    'model' => 'models/'.$model,
                    'content' => ['parts' => [['text' => $text]]],
                    'taskType' => 'CLUSTERING',
                    'outputDimensionality' => self::DIMS,
                ], $batch)],
                'timeout' => 60,
                'max_duration' => 120,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                $message = (string) ($data['error']['message'] ?? '');
                $error = sprintf('Gemini: HTTP %d %s', $response->getStatusCode(), self::geminiError($message));
                if ($response->getStatusCode() === 429) {
                    throw new NewsEmbeddingRateLimit($error, self::retryAfter($message));
                }
                throw new \RuntimeException($error);
            }
            $embeddings = $data['embeddings'] ?? null;
            if (!\is_array($embeddings) || \count($embeddings) !== \count($batch)) {
                throw new \RuntimeException('Gemini: в ответе нет векторов');
            }
            foreach ($embeddings as $embedding) {
                // Урезанный (outputDimensionality) вектор Gemini не нормирован — нормируем, как у Ollama.
                $vectors[] = NewsVector::truncate(array_map('floatval', (array) ($embedding['values'] ?? [])), self::DIMS);
            }
        }

        return $vectors;
    }

    /**
     * Суть ошибки Gemini: у отказа по лимиту (429) начало сообщения — общая отсылка к тарифу, а какой лимит
     * исчерпан и когда повторить — в строках «Quota exceeded for metric: …, limit: …» и «Please retry in …».
     */
    private static function geminiError(string $message): string
    {
        preg_match_all('/Quota exceeded for metric: [^\n]+|Please retry in [^\n]+/', $message, $m);

        return $m[0] !== [] ? 'лимит: '.implode('; ', array_map('trim', $m[0])) : mb_substr($message, 0, 300);
    }

    /** Секунды из «Please retry in 618.95259ms.» / «Please retry in 34.5s.»; null — срока нет. */
    private static function retryAfter(string $message): ?float
    {
        if (!preg_match('/retry in ([\d.]+)(ms|s)\b/', $message, $m)) {
            return null;
        }

        return (float) $m[1] / ($m[2] === 'ms' ? 1000 : 1);
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function ollama(array $texts, string $url, string $model): array
    {
        $vectors = [];
        foreach (array_chunk($texts, self::OLLAMA_BATCH) as $batch) {
            $response = $this->http->request('POST', rtrim($url, '/').'/api/embed', [
                // Как в KB: модель на процессоре и с контекстом 2048 — на видеокарте она вытесняет из 8 ГБ
                // чат-модель KB, и ответы KB замедляются в разы; с другими параметрами Ollama перезагрузила бы модель.
                'json' => [
                    'model' => $model,
                    'input' => $batch,
                    'keep_alive' => '30m',
                    'options' => ['num_gpu' => 0, 'num_ctx' => 2048],
                ],
                'timeout' => 180,
                'max_duration' => 600,
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Ollama: HTTP '.$response->getStatusCode());
            }
            $embeddings = $response->toArray()['embeddings'] ?? null;
            if (!\is_array($embeddings) || \count($embeddings) !== \count($batch)) {
                throw new \RuntimeException('Ollama: в ответе нет векторов');
            }
            foreach ($embeddings as $embedding) {
                $vectors[] = NewsVector::truncate(array_map('floatval', $embedding), self::DIMS);
            }
        }

        return $vectors;
    }

    /**
     * @param list<string>          $texts
     * @param array<string, string> $extraHeaders
     *
     * @return list<list<float>>
     */
    private function openai(array $texts, string $url, string $model, string $key, array $extraHeaders): array
    {
        $endpoint = rtrim($url, '/');
        if (!str_ends_with($endpoint, '/v1')) {
            $endpoint .= '/v1';
        }
        $headers = array_merge($extraHeaders, [
            'Authorization' => 'Bearer '.$key,
        ]);

        $vectors = [];
        foreach (array_chunk($texts, self::OPENAI_BATCH) as $batch) {
            $response = $this->http->request('POST', $endpoint.'/embeddings', [
                'headers' => $headers,
                'json' => [
                    'model' => $model,
                    'input' => $batch,
                    'dimensions' => self::DIMS,
                ],
                'timeout' => 60,
                'max_duration' => 120,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                $message = (string) ($data['error']['message'] ?? '');
                if ($response->getStatusCode() === 429) {
                    throw new NewsEmbeddingRateLimit('OpenAI embeddings: HTTP 429 '.$message, self::retryAfter($message));
                }
                throw new \RuntimeException('OpenAI embeddings: HTTP '.$response->getStatusCode().' '.$message);
            }
            $items = $data['data'] ?? null;
            if (!\is_array($items) || \count($items) !== \count($batch)) {
                throw new \RuntimeException('OpenAI embeddings: в ответе нет векторов');
            }
            usort($items, static fn (array $a, array $b) => ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0)));
            foreach ($items as $item) {
                $vectors[] = NewsVector::truncate(array_map('floatval', (array) ($item['embedding'] ?? [])), self::DIMS);
            }
        }

        return $vectors;
    }

    /**
     * @return array{provider: string, url: string, model: string, key: string, headers: array<string, string>}
     */
    private function resolveTarget(): array
    {
        if ($this->providers !== null) {
            try {
                $configured = $this->providers->findFirstEnabledForEmbeddings();
                if ($configured !== null) {
                    $key = '';
                    if ($this->secretBox !== null && $configured->getApiKeyEncrypted() !== null) {
                        $key = $this->secretBox->decrypt($configured->getApiKeyEncrypted()) ?? '';
                    }
                    $providerType = match ($configured->getType()) {
                        AiProvider::TYPE_GEMINI => self::PROVIDER_GEMINI,
                        AiProvider::TYPE_OPENAI, AiProvider::TYPE_OPENAI_COMPATIBLE => self::PROVIDER_OPENAI,
                        default => self::PROVIDER_OLLAMA,
                    };

                    return [
                        'provider' => $providerType,
                        'url' => $configured->getBaseUrl() ?? '',
                        'model' => $configured->getDefaultModel() ?: match ($providerType) {
                            self::PROVIDER_GEMINI => 'gemini-embedding-001',
                            self::PROVIDER_OPENAI => 'text-embedding-3-small',
                            default => $this->model,
                        },
                        'key' => $key,
                        'headers' => $configured->getHeaders(),
                    ];
                }

                // Если в БД явно заведены провайдеры эмбеддингов и все они отключены — уважаем отключение.
                if ($this->providers->findOneBy(['useForEmbeddings' => true]) !== null) {
                    return [
                        'provider' => self::PROVIDER_OLLAMA,
                        'url' => '',
                        'model' => $this->model,
                        'key' => '',
                        'headers' => [],
                    ];
                }
            } catch (\Throwable) {
                // Свежая БД до миграций или изолированный тест — откат к переменным окружения.
            }
        }

        return [
            'provider' => $this->provider === self::PROVIDER_GEMINI ? self::PROVIDER_GEMINI : self::PROVIDER_OLLAMA,
            'url' => $this->url,
            'model' => $this->model,
            'key' => $this->key,
            'headers' => [],
        ];
    }
}
