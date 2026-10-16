<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Entity\NewsStoryline;
use App\Repository\NewsItemRepository;
use App\Repository\NewsStorylineRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Сюжетные линии: связывает новости в истории во времени. Три шага, каждый догоняет своё:
 * 1. векторы новостей (заголовок с анонсом) — [NewsEmbedder];
 * 2. привязка: новость идёт в активную линию с ближайшим центром, если сходство не ниже порога,
 *    иначе открывает новую линию;
 * 3. названия линий — модель по заголовкам новостей линии, когда линия выросла. В том же запросе модель
 *    решает, склеить ли линии с близкими центрами: одно событие на разных языках векторы сближают слабее,
 *    чем несвязанные новости на одном языке (Эр-Рияд по-русски и по-английски — 0,71, разные новости
 *    о Трампе по-английски — 0,80), поэтому порог тут не помогает, а модель различает.
 */
final class NewsStorylineService
{
    /**
     * Порог сходства новости с центром линии и порог кандидатов на склейку — свои у каждой модели векторов
     * (`NEWS_STORYLINE_THRESHOLD`, `NEWS_STORYLINE_MERGE`; пусто — по умолчанию для провайдера).
     * qwen3-embedding (Ollama): 0,74 — подобран на 956 новостях 11–12.10.2026: при 0,72 в одну линию слипаются
     * разные новости о Трампе, при 0,75 русские новости об Эр-Рияде дробятся на две линии.
     * gemini-embedding-001: шкала выше (одно событие ru/en — 0,90), значения — временные, до подбора
     * командой `app:news:storyline-tune` на реальных новостях.
     */
    public const THRESHOLD = 0.74;
    public const MERGE_CANDIDATE = 0.65;
    public const GEMINI_THRESHOLD = 0.86;
    public const GEMINI_MERGE_CANDIDATE = 0.80;
    /** Пар на склейку за запрос: вместе с названиями ответ должен уложиться в лимит длины ответа модели. */
    private const MERGE_PAIRS = 8;
    /** Линия, в которой не было новостей столько дней, закрыта: новость о том же через неделю — новая линия. */
    public const ACTIVE_DAYS = 3;
    /** За проход цикла (5 минут): Ollama на процессоре — 100 заголовков за пару минут; Gemini API — 1000 за секунды. */
    private const EMBED_LIMIT = 100;
    private const GEMINI_EMBED_LIMIT = 1000;
    /** Линий на название за проход: при 40 ответ модели обрезался по лимиту длины (14.10.2026), остальные — следующими проходами. */
    private const NAME_LIMIT = 12;
    private const HEADLINES_PER_LINE = 8;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsItemRepository $items,
        private readonly NewsStorylineRepository $lines,
        private readonly NewsEmbedder $embedder,
        private readonly NewsSummarizer $summarizer,
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $model = '',
        #[Autowire('%env(NEWS_STORYLINE_THRESHOLD)%')]
        private readonly string $threshold = '',
        #[Autowire('%env(NEWS_STORYLINE_MERGE)%')]
        private readonly string $mergeCandidate = '',
    ) {
    }

    /** Порог привязки к линии для текущей модели векторов. */
    public function threshold(): float
    {
        return $this->threshold !== '' ? (float) $this->threshold
            : ($this->embedder->provider() === NewsEmbedder::PROVIDER_GEMINI ? self::GEMINI_THRESHOLD : self::THRESHOLD);
    }

    /**
     * Жадная раскладка векторов по линиям, как в {@see assign()}, но без базы и окна активности — для подбора
     * порога (`app:news:storyline-tune`) на новостях за день-два.
     *
     * @param list<list<float>> $vectors в порядке времени
     *
     * @return list<list<int>> номера векторов по линиям
     */
    public static function group(array $vectors, float $threshold): array
    {
        $groups = [];
        $centroids = [];
        foreach ($vectors as $n => $vector) {
            $best = null;
            $bestScore = $threshold;
            foreach ($centroids as $i => $centroid) {
                $score = NewsVector::similarity($vector, $centroid);
                if ($score >= $bestScore) {
                    [$best, $bestScore] = [$i, $score];
                }
            }
            if ($best === null) {
                $groups[] = [$n];
                $centroids[] = $vector;
            } else {
                $centroids[$best] = NewsVector::addToCentroid($centroids[$best], \count($groups[$best]), $vector);
                $groups[$best][] = $n;
            }
        }

        return $groups;
    }

    private function mergeCandidate(): float
    {
        return $this->mergeCandidate !== '' ? (float) $this->mergeCandidate
            : ($this->embedder->provider() === NewsEmbedder::PROVIDER_GEMINI ? self::GEMINI_MERGE_CANDIDATE : self::MERGE_CANDIDATE);
    }

    /**
     * Пересобрать сюжеты: [embeddings] — заново посчитать и векторы (смена модели векторов), иначе только
     * привязку и линии (смена порога). Названия линий модель даст заново следующими проходами.
     */
    public function reset(bool $embeddings): void
    {
        $db = $this->em->getConnection();
        $db->executeStatement('UPDATE news_item SET storyline_id = NULL'.($embeddings ? ', embedding = NULL' : ''));
        $db->executeStatement('DELETE FROM news_storyline');
        $this->em->clear();
    }

    /** @return array{embedded: int, assigned: int, created: int, named: int, merged: int, error: ?string} */
    public function run(\DateTimeImmutable $now): array
    {
        $report = ['embedded' => 0, 'assigned' => 0, 'created' => 0, 'named' => 0, 'merged' => 0, 'error' => null];
        if (!$this->embedder->isEnabled()) {
            $report['error'] = $this->embedder->missing().' — векторов и сюжетов нет';

            return $report;
        }
        try {
            $report['embedded'] = $this->embedMissing();
            [$report['assigned'], $report['created']] = $this->assign();
            [$report['named'], $report['merged']] = $this->name($now);
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }

        return $report;
    }

    private function embedMissing(): int
    {
        $items = $this->items->findNeedingEmbedding($this->embedder->provider() === NewsEmbedder::PROVIDER_GEMINI ? self::GEMINI_EMBED_LIMIT : self::EMBED_LIMIT);
        if ($items === []) {
            return 0;
        }
        $vectors = $this->embedder->embed(array_map(self::text(...), $items));
        foreach ($items as $i => $item) {
            $item->setEmbedding($vectors[$i]);
        }
        $this->em->flush();

        return \count($items);
    }

    /** @return array{int, int} сколько новостей привязано и сколько линий открыто */
    private function assign(): array
    {
        $items = $this->items->findUnassigned(2000);
        if ($items === []) {
            return [0, 0];
        }
        // Активные на момент самой ранней новости линии; центры держим в памяти — они меняются по ходу.
        $since = EventTime::fromDb($items[0]->getSortAt())->modify('-'.self::ACTIVE_DAYS.' days');
        $active = [];
        foreach ($this->lines->findActiveSince($since) as $line) {
            $active[] = ['line' => $line, 'centroid' => $line->getCentroid()];
        }

        $created = 0;
        foreach ($items as $item) {
            $vector = (array) $item->getEmbedding();
            $at = EventTime::fromDb($item->getSortAt());
            $best = null;
            $bestScore = $this->threshold();
            foreach ($active as $i => $candidate) {
                if (EventTime::fromDb($candidate['line']->getLastSeenAt()) < $at->modify('-'.self::ACTIVE_DAYS.' days')) {
                    continue;
                }
                $score = NewsVector::similarity($vector, $candidate['centroid']);
                if ($score >= $bestScore) {
                    [$best, $bestScore] = [$i, $score];
                }
            }
            if ($best === null) {
                $line = new NewsStoryline($vector, $item->getSortAt());
                $this->em->persist($line);
                $active[] = ['line' => $line, 'centroid' => $vector];
                ++$created;
            } else {
                $line = $active[$best]['line'];
                $line->add($vector, $item->getSortAt());
                $active[$best]['centroid'] = $line->getCentroid();
            }
            $item->setStoryline($line);
        }
        $this->em->flush();

        return [\count($items), $created];
    }

    /**
     * Названия выросшим линиям и склейка: один запрос к модели на все линии прохода.
     *
     * @return array{int, int} сколько линий названо и сколько склеено
     */
    private function name(\DateTimeImmutable $now): array
    {
        $since = $now->modify('-'.self::ACTIVE_DAYS.' days');
        $lines = $this->lines->findNeedingName($since, self::NAME_LIMIT);
        if ($lines === []) {
            return [0, 0];
        }
        $pairs = $this->mergeCandidates($lines, $this->lines->findActiveSince($since));
        $byId = [];
        foreach ($lines as $line) {
            $byId[$line->getId()] = $line;
        }
        foreach ($pairs as [$a, $b]) {
            $byId[$a->getId()] ??= $a;
            $byId[$b->getId()] ??= $b;
        }
        $blocks = [];
        foreach ($byId as $line) {
            $headlines = array_map(
                static fn (NewsItem $n) => '- '.$n->getSource()->getTitle().': '.$n->getTitle(),
                $this->items->findByStoryline($line, self::HEADLINES_PER_LINE),
            );
            $blocks[] = "[{$line->getId()}] новостей {$line->getNewsCount()}\n".implode("\n", $headlines);
        }
        $answer = $this->summarizer->complete(
            NewsStorylinePrompt::build($blocks, array_map(static fn (array $p) => [$p[0]->getId(), $p[1]->getId()], $pairs)),
            $this->model ?: null,
            'low',
            NewsStorylinePrompt::schema(),
        );
        $parsed = NewsStorylinePrompt::parse($answer);

        $named = 0;
        foreach ($byId as $id => $line) {
            if (isset($parsed['names'][$id])) {
                $line->name($parsed['names'][$id]['title'], $parsed['names'][$id]['summary']);
                ++$named;
            }
        }
        $merged = $this->merge($parsed['merge'], $byId, $pairs);
        $this->em->flush();

        return [$named, $merged];
    }

    /**
     * Пары «линия прохода — активная линия» с близкими центрами, самые близкие первыми.
     *
     * @param list<NewsStoryline> $lines
     * @param list<NewsStoryline> $active
     *
     * @return list<array{NewsStoryline, NewsStoryline}>
     */
    private function mergeCandidates(array $lines, array $active): array
    {
        $scored = [];
        $seen = [];
        foreach ($lines as $line) {
            $centroid = $line->getCentroid();
            foreach ($active as $other) {
                $key = min($line->getId(), $other->getId()).'-'.max($line->getId(), $other->getId());
                if ($other === $line || $other->getNewsCount() < 2 || isset($seen[$key])) {
                    continue;
                }
                $score = NewsVector::similarity($centroid, $other->getCentroid());
                if ($score >= $this->mergeCandidate()) {
                    $seen[$key] = true;
                    $scored[] = [$score, $line, $other];
                }
            }
        }
        usort($scored, static fn (array $a, array $b) => $b[0] <=> $a[0]);

        return array_map(static fn (array $s) => [$s[1], $s[2]], \array_slice($scored, 0, self::MERGE_PAIRS));
    }

    /**
     * Склеивает пары, подтверждённые моделью: в линию с большим числом новостей. Пары не из списка
     * кандидатов не трогаем — модель могла ошибиться в id.
     *
     * @param list<array{int, int}>                      $answer
     * @param array<int, NewsStoryline>                  $byId
     * @param list<array{NewsStoryline, NewsStoryline}>  $pairs
     */
    private function merge(array $answer, array $byId, array $pairs): int
    {
        $allowed = [];
        foreach ($pairs as [$a, $b]) {
            $allowed[min($a->getId(), $b->getId()).'-'.max($a->getId(), $b->getId())] = true;
        }
        /** @var array<int, int> $into куда ушла линия — цепочки «a в b, b в c» */
        $into = [];
        $resolve = static function (int $id) use (&$into): int {
            while (isset($into[$id])) {
                $id = $into[$id];
            }

            return $id;
        };
        $merged = 0;
        foreach ($answer as [$a, $b]) {
            if (!isset($allowed[min($a, $b).'-'.max($a, $b)])) {
                continue;
            }
            [$a, $b] = [$resolve($a), $resolve($b)];
            if ($a === $b) {
                continue;
            }
            [$target, $source] = $byId[$a]->getNewsCount() >= $byId[$b]->getNewsCount() ? [$byId[$a], $byId[$b]] : [$byId[$b], $byId[$a]];
            foreach ($this->items->findByStoryline($source, 100000) as $item) {
                $item->setStoryline($target);
            }
            $target->absorb($source);
            $this->em->remove($source);
            $into[$source->getId()] = $target->getId();
            ++$merged;
        }

        return $merged;
    }

    /** Текст для вектора — заголовок и анонс: этого хватает, чтобы узнать событие; полный текст размывает вектор. */
    private static function text(NewsItem $item): string
    {
        return trim($item->getTitle().'. '.mb_substr((string) $item->getSummary(), 0, 300));
    }
}
