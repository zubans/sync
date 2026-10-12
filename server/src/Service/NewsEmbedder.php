<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Векторы текстов — Ollama (`/api/embed`) с моделью qwen3-embedding, той же, что у поиска в KB.
 * Модель многоязычная: одно событие на русском, английском и французском даёт близкие векторы
 * (сходство ~0,73 против ~0,27 у несвязанных новостей), поэтому сюжеты собираются поверх языков.
 * Адрес не задан — векторов нет, сюжетные линии не строятся, остальное работает.
 */
final class NewsEmbedder
{
    public const DIMS = 1024;
    /** Небольшими пакетами: на процессоре пакет из 16 заголовков — десятки секунд. */
    private const BATCH = 16;

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire('%env(NEWS_EMBEDDINGS_URL)%')]
        private readonly string $url = '',
        #[Autowire('%env(NEWS_EMBEDDINGS_MODEL)%')]
        private readonly string $model = 'qwen3-embedding:4b',
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->url !== '';
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * @param list<string> $texts
     *
     * @return list<list<float>> нормированные векторы по [DIMS] значений, в порядке текстов
     *
     * @throws \RuntimeException Ollama не ответила
     */
    public function embed(array $texts): array
    {
        $vectors = [];
        foreach (array_chunk($texts, self::BATCH) as $batch) {
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
