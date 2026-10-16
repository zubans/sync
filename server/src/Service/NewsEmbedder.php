<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Векторы текстов для сюжетов. Два провайдера (`NEWS_EMBEDDINGS_PROVIDER`):
 * - `gemini` — Gemini API, модель `gemini-embedding-001`, режим `CLUSTERING` (под группировку похожих текстов),
 *   пачками до 100 текстов; ключ — `NEWS_EMBEDDINGS_KEY` из Google AI Studio. Одно событие на русском и английском —
 *   сходство ~0,90 (проверено с VPS 16.10.2026), стоимость — меньше доллара в месяц;
 * - `ollama` — `/api/embed` на машине KB (`NEWS_EMBEDDINGS_URL`), модель qwen3-embedding на процессоре, чтобы
 *   не вытеснять чат-модель KB из видеопамяти: медленно, сходство ru/en ~0,73.
 * Не настроено — векторов нет, сюжетные линии не строятся, остальное работает.
 */
final class NewsEmbedder
{
    public const DIMS = 1024;
    public const PROVIDER_GEMINI = 'gemini';
    public const PROVIDER_OLLAMA = 'ollama';
    private const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:batchEmbedContents';
    /** Ollama на процессоре — пакет из 16 заголовков десятки секунд; Gemini принимает до 100 текстов за запрос. */
    private const OLLAMA_BATCH = 16;
    private const GEMINI_BATCH = 100;

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
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->provider === self::PROVIDER_GEMINI ? $this->key !== '' : $this->url !== '';
    }

    public function provider(): string
    {
        return $this->provider === self::PROVIDER_GEMINI ? self::PROVIDER_GEMINI : self::PROVIDER_OLLAMA;
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Что не настроено — для текста ошибки. */
    public function missing(): string
    {
        return $this->provider === self::PROVIDER_GEMINI ? 'NEWS_EMBEDDINGS_KEY не задан' : 'NEWS_EMBEDDINGS_URL не задан';
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> векторы по [DIMS] значений, в порядке текстов
     *
     * @throws \RuntimeException провайдер не ответил
     */
    public function embed(array $texts): array
    {
        return $this->provider() === self::PROVIDER_GEMINI ? $this->gemini($texts) : $this->ollama($texts);
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function gemini(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, self::GEMINI_BATCH) as $batch) {
            $response = $this->http->request('POST', sprintf(self::GEMINI_URL, $this->model), [
                'headers' => ['x-goog-api-key' => $this->key],
                'json' => ['requests' => array_map(fn (string $text) => [
                    'model' => 'models/'.$this->model,
                    'content' => ['parts' => [['text' => $text]]],
                    'taskType' => 'CLUSTERING',
                    'outputDimensionality' => self::DIMS,
                ], $batch)],
                'timeout' => 60,
                'max_duration' => 120,
            ]);
            $data = $response->toArray(false);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('Gemini: HTTP %d %s', $response->getStatusCode(), mb_substr((string) ($data['error']['message'] ?? ''), 0, 200)));
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
     * @param list<string> $texts
     *
     * @return list<list<float>>
     */
    private function ollama(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, self::OLLAMA_BATCH) as $batch) {
            $response = $this->http->request('POST', rtrim($this->url, '/').'/api/embed', [
                // Как в KB: модель на процессоре и с контекстом 2048 — на видеокарте она вытесняет из 8 ГБ
                // чат-модель KB, и ответы KB замедляются в разы; с другими параметрами Ollama перезагрузила бы модель.
                'json' => [
                    'model' => $this->model,
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
}
