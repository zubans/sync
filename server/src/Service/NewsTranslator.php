<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перевод новостей изданий на других языках (CNN, BBC, Le Monde…) на русский — тем же agy, что пишет сводки.
 * - Заголовки с анонсами — автоматически, пачкой в один запрос, свежие первыми: лента и сюжеты нужны быстро.
 * - Полные тексты — только по просьбе читателя (кнопка «Перевести», [runRequested]) в отдельном быстром цикле:
 *   автоматический перевод всех статей тратил квоту agy на тысячи статей, которые никто не открывал.
 *   Длинная статья — частями по границе абзаца.
 *
 * Перевод хранится рядом с оригиналом (оригинал не меняется). Неудача не роняет проход. Модель ответила,
 * но перевода новости в ответе нет или он не годится — попытка засчитывается этому шагу (у заголовка и текста
 * счётчики свои), и после [MAX_ATTEMPTS] новость остаётся без этого перевода — клиент показывает оригинал.
 * agy недоступен (квота, нет входа, таймаут) — проход заканчивается без попыток: виноват не текст новости,
 * и следующий проход переведёт её, когда agy вернётся.
 */
final class NewsTranslator
{
    public const MAX_ATTEMPTS = 3;
    /**
     * Лимиты за проход цикла `news` (раз в 5 минут) — из-за квот подписки agy: иностранных новостей
     * несколько сотен в сутки, заголовки пачками по 40 — это 15–20 вызовов в сутки, а полный текст —
     * вызов на статью (и больше у длинных), поэтому статей за проход немного.
     */
    public const HEADLINE_BATCH = 40;
    public const ARTICLE_LIMIT = 3;
    /** Статей за проход цикла `news` без просьбы читателя: 0 — автоматически статьи не переводятся. */
    public const AUTO_ARTICLES = 0;
    /** Статей по просьбам за один заход быстрого цикла. */
    public const REQUESTED_BATCH = 2;
    /** Часть длинной статьи в одном запросе, символов: ответ модели не обрывается и не сокращается. */
    public const CHUNK_CHARS = 12000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsItemRepository $items,
        private readonly NewsSummarizer $summarizer,
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $model = '',
    ) {
    }

    /** @return array{headlines: int, articles: int, failed: int, error: ?string} */
    public function run(int $headlineLimit = self::HEADLINE_BATCH, int $articleLimit = self::ARTICLE_LIMIT): array
    {
        $report = ['headlines' => 0, 'articles' => 0, 'failed' => 0, 'error' => null];
        try {
            $items = $this->items->findNeedingHeadlineTranslation(max(0, $headlineLimit), self::MAX_ATTEMPTS);
            foreach (array_chunk($items, self::HEADLINE_BATCH) as $batch) {
                [$done, $failed, $error] = $this->translateHeadlines($batch);
                $report['headlines'] += $done;
                $report['failed'] += $failed;
                $report['error'] ??= $error;
                $this->em->flush();
            }

            $articles = $articleLimit > 0
                ? $this->items->findNeedingContentTranslation($articleLimit, NewsArticleFetcher::FULL_TEXT, self::MAX_ATTEMPTS)
                : [];
            foreach ($articles as $item) {
                try {
                    $this->translateArticle($item);
                    ++$report['articles'];
                } catch (\UnexpectedValueException $e) {
                    $item->markContentTranslationAttempt();
                    ++$report['failed'];
                    $report['error'] ??= $e->getMessage();
                }
                $this->em->flush();
            }
        } catch (\RuntimeException $e) {
            // agy недоступен (квота, нет входа, таймаут): новости не виноваты — попытки не считаются,
            // а остальные запросы прохода упали бы так же. Следующий проход цикла `news` повторит.
            $report['error'] ??= $e->getMessage();
            $this->em->flush();
        }

        return $report;
    }

    /**
     * Статьи по просьбам читателей — приоритетная очередь, её проверяет отдельный цикл каждые несколько секунд.
     * Неудача засчитывается попыткой; после [MAX_ATTEMPTS] просьба снимается (клиент покажет «не удалось»
     * и предложит попросить снова). agy недоступен — просьба остаётся до следующего захода.
     *
     * @return array{articles: int, failed: int, error: ?string}
     */
    public function runRequested(int $limit = self::REQUESTED_BATCH): array
    {
        $report = ['articles' => 0, 'failed' => 0, 'error' => null];
        foreach ($this->items->findRequestedContentTranslation($limit) as $item) {
            if (NewsItem::paragraphs($item->getContent()) === []) {
                $item->dropTranslationRequest();
                $this->em->flush();
                continue;
            }
            try {
                $this->translateArticle($item);
                ++$report['articles'];
            } catch (\UnexpectedValueException $e) {
                $item->markContentTranslationAttempt();
                if ($item->getContentTranslationAttempts() >= self::MAX_ATTEMPTS) {
                    $item->dropTranslationRequest();
                }
                ++$report['failed'];
                $report['error'] ??= $e->getMessage();
            } catch (\RuntimeException $e) {
                $report['error'] ??= $e->getMessage();
                $this->em->flush();
                break;
            }
            $this->em->flush();
        }

        return $report;
    }

    /**
     * Состояние перевода текста статьи для клиента: `null` — переводить нечего (русское издание, сводка);
     * `done`, `queued` (попросили, ждёт), `failed` (не удалось — можно попросить снова), `available` (можно попросить),
     * `unavailable` (полного текста нет — переведены только заголовок и анонс).
     */
    public static function contentState(NewsItem $item): ?string
    {
        $source = $item->getSource();
        if ($source->isDigest() || $source->getLanguage() === \App\Entity\NewsSource::LANGUAGE_RU) {
            return null;
        }

        return match (true) {
            $item->getTranslatedContent() !== null => 'done',
            $item->getTranslationRequestedAt() !== null => 'queued',
            NewsItem::paragraphs($item->getContent()) === [] => 'unavailable',
            $item->getContentTranslationAttempts() >= self::MAX_ATTEMPTS => 'failed',
            default => 'available',
        };
    }

    /**
     * @param list<NewsItem> $batch
     *
     * @return array{int, int, ?string} сколько переведено, сколько нет и почему ответ не разобран
     *
     * @throws \RuntimeException agy недоступен — попытки новостям не засчитаны
     */
    private function translateHeadlines(array $batch): array
    {
        $request = array_map(static fn (NewsItem $n) => [
            'id' => (int) $n->getId(),
            'language' => $n->getSource()->getLanguage(),
            'title' => $n->getTitle(),
            'summary' => $n->getSummary(),
        ], $batch);
        $answer = $this->summarizer->complete(NewsTranslationPrompt::buildHeadlines($request), $this->model ?: null, 'low', NewsTranslationPrompt::schemaHeadlines());
        try {
            $parsed = NewsTranslationPrompt::parseHeadlines($answer, array_column($request, 'id'));
            $error = null;
        } catch (\UnexpectedValueException $e) {
            // Модель ответила, но не тем — попытка засчитывается, иначе пачка тратила бы квоту бесконечно.
            [$parsed, $error] = [[], $e->getMessage()];
        }

        $done = 0;
        foreach ($batch as $item) {
            $translation = $parsed[(int) $item->getId()] ?? null;
            if ($translation === null) {
                $item->markHeadlineTranslationAttempt();
                continue;
            }
            // Анонса в оригинале нет — модель могла сочинить его; такой «перевод» не нужен.
            $item->setHeadlineTranslation($translation['title'], trim((string) $item->getSummary()) !== '' ? $translation['summary'] : null);
            ++$done;
        }

        return [$done, \count($batch) - $done, $error];
    }

    /**
     * @throws \UnexpectedValueException ответ модели не годится — попытка засчитывается
     * @throws \RuntimeException          agy недоступен — попытка не засчитывается
     */
    private function translateArticle(NewsItem $item): void
    {
        $language = $item->getSource()->getLanguage();
        $translated = [];
        $title = null;
        foreach (self::chunks(NewsItem::paragraphs($item->getContent())) as $i => $chunk) {
            $answer = $this->summarizer->complete(
                NewsTranslationPrompt::buildArticle($language, $i === 0 ? $item->getTitle() : null, $chunk),
                $this->model ?: null,
                'low',
                NewsTranslationPrompt::schemaArticle(),
            );
            $parsed = NewsTranslationPrompt::parseArticle($answer, \count($chunk));
            $title ??= $parsed['title'];
            array_push($translated, ...$parsed['paragraphs']);
        }
        if ($translated === []) {
            throw new \UnexpectedValueException('Перевод статьи: текста нет');
        }
        $item->setContentTranslation($translated);
        // Заголовок ещё не переведён, а анонса нет — заголовок из перевода статьи.
        if ($item->getTranslatedTitle() === null && $title !== null && trim((string) $item->getSummary()) === '') {
            $item->setHeadlineTranslation($title, null);
        }
    }

    /**
     * Абзацы частями не длиннее [CHUNK_CHARS] по границе абзаца; абзац длиннее — отдельной частью.
     *
     * @param list<string> $paragraphs
     *
     * @return list<list<string>>
     */
    public static function chunks(array $paragraphs, int $limit = self::CHUNK_CHARS): array
    {
        $chunks = [];
        $current = [];
        $length = 0;
        foreach ($paragraphs as $paragraph) {
            $size = mb_strlen($paragraph);
            if ($current !== [] && $length + $size > $limit) {
                $chunks[] = $current;
                [$current, $length] = [[], 0];
            }
            $current[] = $paragraph;
            $length += $size;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
