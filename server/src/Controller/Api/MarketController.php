<?php

namespace App\Controller\Api;

use App\Dto\LotInput;
use App\Dto\WatchlistInput;
use App\Entity\MarketAnalysis;
use App\Entity\MarketInstrument;
use App\Entity\MarketLot;
use App\Entity\NewsItem;
use App\Entity\NewsMarketImpact;
use App\Entity\User;
use App\Repository\MarketAnalysisRepository;
use App\Repository\MarketInstrumentRepository;
use App\Repository\MarketLotRepository;
use App\Service\EventTime;
use App\Service\MarketAnalysisService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Виджет Мосбиржи на планшете: список инструментов (ведёт планшет), покупки пользователя и анализ
 * инструментов моделью (`app:market:analyze` в контейнере `news`). Котировки планшет берёт из ISS сам.
 */
#[Route('/api/market')]
final class MarketController extends AbstractController
{
    /** Точек на графике экрана инструмента — около года торговых дней. */
    private const CHART_BARS = 260;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MarketInstrumentRepository $instruments,
        private readonly MarketLotRepository $lots,
        private readonly MarketAnalysisRepository $analyses,
        private readonly MarketAnalysisService $service,
    ) {
    }

    /**
     * Список инструментов целиком, в порядке виджета. Убранные из списка инструменты удаляются, их покупки
     * остаются: вернул инструмент на экран — вернулась и позиция.
     */
    #[Route('/watchlist', name: 'api_market_watchlist', methods: ['PUT'])]
    public function watchlist(#[CurrentUser] User $user, #[MapRequestPayload] WatchlistInput $input): JsonResponse
    {
        $existing = [];
        foreach ($this->instruments->findByUser($user) as $instrument) {
            $existing[$instrument->getSecid()] = $instrument;
        }
        $kept = [];
        foreach (array_values($input->instruments) as $position => $item) {
            $secid = strtoupper($item->secid);
            if (isset($kept[$secid])) {
                continue;
            }
            $name = trim($item->name) !== '' ? trim($item->name) : $secid;
            if (isset($existing[$secid])) {
                $existing[$secid]->update($item->engine, $item->market, $item->board, $name, $position);
            } else {
                $this->em->persist(new MarketInstrument($user, $secid, $item->engine, $item->market, $item->board, $name, $position));
            }
            $kept[$secid] = true;
        }
        foreach (array_diff_key($existing, $kept) as $removed) {
            $this->em->remove($removed);
        }
        $this->em->flush();

        return $this->json(['instruments' => \count($kept)]);
    }

    /** Для строк виджета: краткий анализ и покупки каждого инструмента. Доходность планшет считает по живой цене. */
    #[Route('/overview', name: 'api_market_overview', methods: ['GET'])]
    public function overview(#[CurrentUser] User $user): JsonResponse
    {
        $analyses = $this->analyses->findByUser($user);
        $lots = [];
        foreach ($this->lots->findByUser($user) as $lot) {
            $lots[$lot->getSecid()][] = self::lotView($lot);
        }

        return $this->json([
            'instruments' => array_map(static fn (MarketInstrument $i) => [
                'secid' => $i->getSecid(),
                'analysis' => isset($analyses[$i->getSecid()]) ? self::analysisView($analyses[$i->getSecid()]) : null,
                'lots' => $lots[$i->getSecid()] ?? [],
            ], $this->instruments->findByUser($user)),
        ]);
    }

    /**
     * Всё об инструменте для экрана на планшете: сведения о бумаге, дивиденды, индикаторы и график
     * по дневным свечам, новости с последствиями, покупки и анализ. ISS не ответила — биржевых данных нет,
     * остальное есть, в `error` — причина.
     */
    #[Route('/instruments/{secid}', name: 'api_market_instrument', methods: ['GET'])]
    public function instrument(#[CurrentUser] User $user, string $secid): JsonResponse
    {
        $instrument = $this->own($user, $secid);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $error = null;
        try {
            $market = $this->service->snapshot($instrument, $now);
        } catch (\RuntimeException $e) {
            $market = ['candles' => [], 'indicators' => [], 'description' => [], 'dividends' => []];
            $error = $e->getMessage();
        }
        $news = $this->service->relatedNews($instrument, $now);
        $views = [
            ...array_map(static fn (array $t) => self::newsView($t['item'], $t['impacts']), $news['triggers']),
            ...array_map(static fn (NewsItem $i) => self::newsView($i, []), $news['mentions']),
        ];
        usort($views, static fn (array $a, array $b) => $b['time'] <=> $a['time']);
        $analysis = $this->analyses->findOneBy(['user' => $user, 'secid' => $instrument->getSecid()]);

        return $this->json([
            'secid' => $instrument->getSecid(),
            'name' => $instrument->getName(),
            'engine' => $instrument->getEngine(),
            'market' => $instrument->getMarket(),
            'board' => $instrument->getBoard(),
            'description' => array_map(static fn (string $name, array $f) => ['name' => $name] + $f, array_keys($market['description']), $market['description']),
            'dividends' => \array_slice($market['dividends'], -8),
            'indicators' => $market['indicators'] === [] ? null : $market['indicators'],
            'chart' => array_map(static fn (array $c) => ['date' => $c['date'], 'close' => $c['close']], \array_slice($market['candles'], -self::CHART_BARS)),
            'news' => $views,
            'lots' => array_map(self::lotView(...), $this->lots->findByUser($user, $instrument->getSecid())),
            'analysis' => $analysis !== null ? self::analysisView($analysis) : null,
            'error' => $error,
        ]);
    }

    /** Пересчитать анализ: следующий проход контейнера `news` (до 5 минут) возьмёт инструмент первым. */
    #[Route('/instruments/{secid}/analysis', name: 'api_market_analysis_request', methods: ['POST'])]
    public function requestAnalysis(#[CurrentUser] User $user, string $secid): JsonResponse
    {
        $instrument = $this->own($user, $secid);
        $analysis = $this->analyses->findOrCreate($user, $instrument->getSecid());
        $analysis->request(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();

        return $this->json(self::analysisView($analysis), Response::HTTP_ACCEPTED);
    }

    /** Покупка. Совет зависит от позиции — анализ инструмента пересчитывается. */
    #[Route('/instruments/{secid}/lots', name: 'api_market_lot_create', methods: ['POST'])]
    public function addLot(#[CurrentUser] User $user, string $secid, #[MapRequestPayload] LotInput $input): JsonResponse
    {
        $instrument = $this->own($user, $secid);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $boughtOn = new \DateTimeImmutable($input->boughtOn, new \DateTimeZone('UTC'));
        if ($boughtOn > $now) {
            return $this->json(['error' => 'Дата покупки — в будущем.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $lot = new MarketLot($user, $instrument->getSecid(), $boughtOn, $input->quantity, $input->price, $input->amount ?? round($input->quantity * $input->price, 2), $now);
        $this->em->persist($lot);
        $this->analyses->findOrCreate($user, $instrument->getSecid())->request($now);
        $this->em->flush();

        return $this->json(self::lotView($lot), Response::HTTP_CREATED);
    }

    #[Route('/lots/{id}', name: 'api_market_lot_delete', requirements: ['id' => '\d+'], methods: ['DELETE'])]
    public function deleteLot(#[CurrentUser] User $user, int $id): Response
    {
        $lot = $this->lots->find($id);
        if ($lot === null || $lot->getUser() !== $user) {
            throw $this->createNotFoundException('Покупка не найдена.');
        }
        $this->em->remove($lot);
        $this->analyses->findOrCreate($user, $lot->getSecid())->request(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->flush();

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    private function own(User $user, string $secid): MarketInstrument
    {
        return $this->instruments->findOneBy(['user' => $user, 'secid' => strtoupper($secid)])
            ?? throw $this->createNotFoundException('Инструмента нет в виджете.');
    }

    /** @return array<string, mixed> */
    private static function lotView(MarketLot $lot): array
    {
        return [
            'id' => $lot->getId(),
            'secid' => $lot->getSecid(),
            'boughtOn' => $lot->getBoughtOn()->format('Y-m-d'),
            'quantity' => $lot->getQuantity(),
            'price' => $lot->getPrice(),
            'amount' => $lot->getAmount(),
        ];
    }

    /**
     * Анализ: `pending` — пересчёт запрошен и ещё не сделан; `analyzedAt: null` — анализа пока не было;
     * `error` — последняя попытка не удалась (прежний вывод остаётся).
     *
     * @return array<string, mixed>
     */
    private static function analysisView(MarketAnalysis $a): array
    {
        $ready = $a->getRecommendation() !== null;

        return [
            'recommendation' => $a->getRecommendation(),
            'outlook' => $a->getOutlook(),
            'confidence' => $a->getConfidence(),
            'urgency' => $a->getUrgency(),
            'horizon' => $a->getHorizon(),
            'summary' => $a->getSummary(),
            'news' => $a->getNews(),
            'technical' => $a->getTechnical(),
            'position' => $a->getPosition(),
            'risks' => $a->getRisks(),
            'triggers' => $a->getTriggers(),
            'price' => $a->getPrice(),
            'newsCount' => $a->getNewsCount(),
            'model' => $a->getModel(),
            'analyzedAt' => $ready && $a->getAnalyzedAt() !== null ? EventTime::fromDb($a->getAnalyzedAt())->format(\DATE_ATOM) : null,
            'pending' => $a->getRequestedAt() !== null,
            'error' => $a->getError(),
        ];
    }

    /**
     * Новость инструмента: с последствиями (прогноз модели и факт) или упоминание без них.
     *
     * @param list<NewsMarketImpact> $impacts
     *
     * @return array<string, mixed>
     */
    private static function newsView(NewsItem $item, array $impacts): array
    {
        return [
            'id' => $item->getId(),
            'source' => $item->getSource()->getCode(),
            'sourceTitle' => $item->getSource()->getTitle(),
            'title' => $item->getTranslatedTitle() ?? $item->getTitle(),
            'link' => $item->getLink(),
            'time' => EventTime::fromDb($item->getSortAt())->format(\DATE_ATOM),
            'market' => array_map(static fn (NewsMarketImpact $m) => [
                'secid' => $m->getSecid(),
                'name' => $m->getName(),
                'forecast' => $m->getForecast(),
                'reason' => $m->getReason(),
                'hourChange' => NewsMarketImpact::change($m->getBasePrice(), $m->getHourPrice()),
                'hourFinal' => $m->isHourFinal(),
                'dayChange' => NewsMarketImpact::change($m->getBasePrice(), $m->getDayPrice()),
                'final' => $m->isSettled(),
            ], $impacts),
        ];
    }
}
