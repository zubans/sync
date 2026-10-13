<?php

namespace App\Service;

use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use App\Repository\NewsItemRepository;
use App\Repository\NewsMarketImpactRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Последствия новостей для Мосбиржи. Два шага, каждый догоняет своё:
 * 1. связь: модель (agy, модель часовой сводки) по заголовкам свежих новостей решает, какие инструменты
 *    новость может сдвинуть, куда и почему ([NewsMarketPrompt]). Инструменты — индекс МосБиржи с составом,
 *    отраслевые индексы и валюты ([MoexIss::instruments]). Проверенная новость помечается, даже если
 *    последствий нет: второй раз её модели не отдаём;
 * 2. факт: по свечам ISS — как цена пошла через час торгов после новости и к закрытию дня
 *    ([NewsMarketReaction]); промежуточные цены обновляются, пока замер не окончательный.
 *
 * Не ответила модель или ISS — проход заканчивает этот шаг, следующий проход пробует снова.
 */
final class NewsMarketService
{
    /** Модели отдаются новости не старше этого: последствие интересно, пока новость свежая. */
    public const WINDOW_HOURS = 24;
    /**
     * Новостей в одном запросе к модели и запросов за проход (раз в 5 минут) — из-за квоты подписки agy:
     * ~1500 новостей в сутки — около 30 запросов.
     */
    public const BATCH = 50;
    public const BATCHES = 2;
    /** Замеров за проход: свечи запрашиваются по одному инструменту на все его новости. */
    private const MEASURE_LIMIT = 300;
    /** Свечи берутся с запасом до новости — чтобы найти цену до неё через выходные и праздники. */
    private const BASE_LOOKBACK_DAYS = 5;
    /** Замер, которому не хватило свечей за неделю (бумагу не торговали), закрывается как есть. */
    private const GIVE_UP_DAYS = 7;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly NewsItemRepository $items,
        private readonly NewsMarketImpactRepository $impacts,
        private readonly NewsSummarizer $summarizer,
        private readonly MoexIss $iss,
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $model = '',
    ) {
    }

    /** @return array{checked: int, linked: int, measured: int, settled: int, error: ?string} */
    public function run(\DateTimeImmutable $now): array
    {
        $report = ['checked' => 0, 'linked' => 0, 'measured' => 0, 'settled' => 0, 'error' => null];
        try {
            [$report['checked'], $report['linked'], $report['error']] = $this->link($now);
        } catch (\Throwable $e) {
            $report['error'] = $e->getMessage();
        }
        try {
            [$report['measured'], $report['settled']] = $this->measure($now);
        } catch (\Throwable $e) {
            $report['error'] ??= $e->getMessage();
        }

        return $report;
    }

    /** @return array{int, int, ?string} сколько новостей проверено, сколько последствий найдено и ошибка ответа модели */
    private function link(\DateTimeImmutable $now): array
    {
        $items = $this->items->findNeedingMarketCheck($now->modify('-'.self::WINDOW_HOURS.' hours'), self::BATCH * self::BATCHES);
        if ($items === []) {
            return [0, 0, null];
        }
        $instruments = $this->iss->instruments();
        $names = array_map(static fn (array $i) => $i['name'], $instruments);
        [$checked, $linked, $error] = [0, 0, null];
        foreach (array_chunk($items, self::BATCH) as $batch) {
            $answer = $this->summarizer->complete(
                NewsMarketPrompt::build(array_map(self::line(...), $batch), $names),
                $this->model ?: null,
                'low',
                NewsMarketPrompt::schema(),
            );
            try {
                $parsed = NewsMarketPrompt::parse($answer, array_map(static fn (NewsItem $n) => (int) $n->getId(), $batch), array_keys($instruments));
            } catch (\UnexpectedValueException $e) {
                // Новости пачки остаются непроверенными — следующий проход спросит снова, пока они свежие.
                $error ??= $e->getMessage().': '.mb_substr($answer, 0, 200);
                continue;
            }
            foreach ($batch as $item) {
                foreach ($parsed[$item->getId()] ?? [] as $impact) {
                    $instrument = $instruments[$impact['secid']];
                    $this->em->persist(new NewsMarketImpact(
                        $item, $impact['secid'], $instrument['name'], $instrument['engine'], $instrument['market'],
                        $impact['direction'], $impact['reason'], $now,
                    ));
                    ++$linked;
                }
                $item->markMarketChecked($now);
                ++$checked;
            }
            $this->em->flush();
        }

        return [$checked, $linked, $error];
    }

    /** @return array{int, int} сколько замеров обновлено и сколько из них окончательные */
    private function measure(\DateTimeImmutable $now): array
    {
        $bySecid = [];
        foreach ($this->impacts->findUnsettled(self::MEASURE_LIMIT) as $impact) {
            $bySecid[$impact->getSecid()][] = $impact;
        }
        [$measured, $settled] = [0, 0];
        foreach ($bySecid as $secid => $impacts) {
            $first = min(array_map(static fn (NewsMarketImpact $m) => EventTime::fromDb($m->getItem()->getSortAt()), $impacts));
            $candles = $this->iss->candles($secid, $impacts[0]->getEngine(), $impacts[0]->getMarket(), $first->modify('-'.self::BASE_LOOKBACK_DAYS.' days'), $now);
            foreach ($impacts as $impact) {
                $at = EventTime::fromDb($impact->getItem()->getSortAt());
                $r = NewsMarketReaction::measure($candles, $at, $now);
                $done = ($r['hourFinal'] && $r['dayFinal']) || $at < $now->modify('-'.self::GIVE_UP_DAYS.' days');
                $impact->measure($r['base'], $r['hour'], $r['hourFinal'], $r['day'], $done);
                ++$measured;
                $settled += $done ? 1 : 0;
            }
            $this->em->flush();
        }

        return [$measured, $settled];
    }

    /** Строка промпта: «[id] издание, 13.10 14:05 — заголовок. Анонс» — по-русски, если новость переведена. */
    private static function line(NewsItem $item): string
    {
        $time = EventTime::fromDb($item->getSortAt())->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('d.m H:i');
        $summary = mb_substr(trim((string) ($item->getTranslatedTitle() !== null ? $item->getTranslatedSummary() : $item->getSummary())), 0, 200);

        return sprintf('[%d] %s, %s — %s%s', $item->getId(), $item->getSource()->getTitle(), $time,
            $item->getTranslatedTitle() ?? $item->getTitle(), $summary !== '' ? '. '.$summary : '');
    }
}
