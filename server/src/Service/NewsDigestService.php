<?php

namespace App\Service;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Сводки новостей: раз в час — за прошедший час, раз в сутки — за 24 часа к 07:00 по Москве.
 * Сводка — новость издания «Сводка» (формат digest) с ключом периода, поэтому повторный запуск
 * её не дублирует; подробности — в [NewsDigest]. Ошибка модели записывается в издание «Сводка»,
 * и виджет помечает вкладку, как у издания, которое не удалось собрать.
 */
final class NewsDigestService
{
    /** Меньше новостей за час — сводка не нужна, хватит самих новостей. */
    public const MIN_HOURLY_NEWS = 5;
    /** Столько новостей за сутки модель получает самое большее (обычно их около тысячи). */
    private const MAX_NEWS = 1500;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsSourceRepository $sources,
        private readonly NewsItemRepository $items,
        private readonly NewsSummarizer $summarizer,
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $hourModel = '',
        #[Autowire('%env(NEWS_DIGEST_MODEL_DAY)%')]
        private readonly string $dayModel = '',
    ) {
    }

    /**
     * Пишет сводки за периоды, которые уже наступили и ещё не написаны.
     *
     * @return array<string, string> итог по ключу периода: «готово», «пропущено…» или текст ошибки
     */
    public function run(\DateTimeImmutable $now, bool $rebuild = false): array
    {
        $source = $this->sources->findDigest();
        if ($source === null || !$source->isEnabled()) {
            return [];
        }
        $report = [];
        foreach (NewsDigestPeriod::due($now) as $period) {
            $existing = $this->items->findByKeys($source, [sha1($period->key())]);
            if ($existing !== [] && !$rebuild) {
                continue;
            }
            $report[$period->key()] = $this->build($source, $period, $existing[sha1($period->key())] ?? null);
        }

        return $report;
    }

    private function build(NewsSource $source, NewsDigestPeriod $period, ?NewsItem $item): string
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $news = $this->items->findSeenBetween($period->start, $period->end, self::MAX_NEWS);
        if ($period->period === NewsDigest::PERIOD_HOUR && \count($news) < self::MIN_HOURLY_NEWS) {
            $source->markSuccess($now, null, null);
            $this->em->flush();

            return sprintf('пропущено: новостей %d', \count($news));
        }
        if ($news === []) {
            $source->markSuccess($now, null, null);
            $this->em->flush();

            return 'пропущено: новостей нет';
        }

        $model = ($period->period === NewsDigest::PERIOD_HOUR ? $this->hourModel : $this->dayModel) ?: null;
        try {
            $digest = $this->summarize($period, $news, $model);
        } catch (\Throwable $e) {
            $source->markFailure($now, $e->getMessage());
            $this->em->flush();

            return 'ошибка: '.$e->getMessage();
        }

        $this->save($source, $period, $item, $digest, \count($news), $model, $now);
        $source->markSuccess($now, null, null);
        $this->em->flush();

        return sprintf('готово: новостей %d, сюжетов %d', \count($news), \count($digest['stories']));
    }

    /**
     * Ответ модели, который не разобрался, запрашивается ещё раз — модели иногда дописывают пояснения
     * или обрывают JSON. Вторая неудача — ошибка сводки.
     *
     * @param list<NewsItem> $news
     *
     * @return array{headline: string, stories: list<array{title: string, text: string, items: list<int>}>}
     */
    private function summarize(NewsDigestPeriod $period, array $news, ?string $model): array
    {
        $prompt = NewsDigestPrompt::build($period, $news);
        $ids = array_map(static fn (NewsItem $n) => (int) $n->getId(), $news);
        try {
            return NewsDigestPrompt::parse($this->summarizer->complete($prompt, $model), $ids);
        } catch (\UnexpectedValueException) {
            return NewsDigestPrompt::parse(
                $this->summarizer->complete($prompt."\n\nВажно: ответ — строго один JSON-объект указанного вида, без текста вокруг.", $model),
                $ids,
            );
        }
    }

    /** @param array{headline: string, stories: list<array{title: string, text: string, items: list<int>}>} $digest */
    private function save(NewsSource $source, NewsDigestPeriod $period, ?NewsItem $item, array $digest, int $newsCount, ?string $model, \DateTimeImmutable $now): void
    {
        $isNew = $item === null;
        $item ??= new NewsItem($source, sha1($period->key()), $now);
        $label = $period->period === NewsDigest::PERIOD_HOUR ? 'За час' : 'За сутки';
        $content = implode("\n\n", array_map(static fn (array $s) => $s['title']."\n".$s['text'], $digest['stories']));
        // Время сводки — конец периода: по нему она встаёт в ленту и подписывается в виджете.
        $item->apply('', "$label: {$digest['headline']}", implode(' · ', array_column($digest['stories'], 'title')), $content, $period->end, $now);
        if ($isNew) {
            $this->em->persist($item);
            $details = new NewsDigest($item, $period->period, $period->start, $period->end);
            $this->em->persist($details);
        } else {
            $details = $this->em->getRepository(NewsDigest::class)->findOneBy(['item' => $item])
                ?? new NewsDigest($item, $period->period, $period->start, $period->end);
            $this->em->persist($details);
        }
        $details->update($digest['stories'], $newsCount, $model);
    }
}
