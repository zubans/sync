<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Данные Мосбиржи из ISS (без подписки — с задержкой 15 минут): инструменты, с которыми связываются
 * новости, десятиминутные свечи для замера реакции цены, а для анализа инструментов виджета — дневные свечи,
 * сведения о бумаге и дивиденды. Время ISS — московское, наружу — UTC.
 */
final class MoexIss
{
    private const BASE = 'https://iss.moex.com/iss';
    /** ISS отдаёт свечи страницами по 500. */
    private const PAGE = 500;
    public const CANDLE_MINUTES = 10;

    /**
     * Индексы и валюты, с которыми новости связываются всегда: рынок целиком, отрасли, госдолг, рубль.
     * Акции — состав индекса МосБиржи, его ISS отдаёт по последнему дню торгов.
     */
    private const FIXED = [
        'IMOEX' => ['Индекс МосБиржи', 'stock', 'index'],
        'RGBI' => ['Индекс гособлигаций', 'stock', 'index'],
        'MOEXOG' => ['Индекс нефти и газа', 'stock', 'index'],
        'MOEXFN' => ['Индекс финансов', 'stock', 'index'],
        'MOEXMM' => ['Индекс металлов и добычи', 'stock', 'index'],
        'MOEXEU' => ['Индекс электроэнергетики', 'stock', 'index'],
        'MOEXTL' => ['Индекс телекоммуникаций', 'stock', 'index'],
        'MOEXCN' => ['Индекс потребительского сектора', 'stock', 'index'],
        'MOEXCH' => ['Индекс химии и нефтехимии', 'stock', 'index'],
        'MOEXTN' => ['Индекс транспорта', 'stock', 'index'],
        'MOEXIT' => ['Индекс IT', 'stock', 'index'],
        'MOEXRE' => ['Индекс недвижимости', 'stock', 'index'],
        'CNYRUB_TOM' => ['Юань', 'currency', 'selt'],
        'USD000UTSTOM' => ['Доллар', 'currency', 'selt'],
    ];

    public function __construct(private readonly HttpClientInterface $http)
    {
    }

    /**
     * @return array<string, array{name: string, engine: string, market: string}> по коду ISS
     *
     * @throws \RuntimeException ISS не ответила
     */
    public function instruments(): array
    {
        $list = [];
        foreach (self::FIXED as $secid => [$name, $engine, $market]) {
            $list[$secid] = ['name' => $name, 'engine' => $engine, 'market' => $market];
        }
        $root = $this->get('/statistics/engines/stock/markets/index/analytics/IMOEX.json', ['limit' => 100]);
        foreach (self::rows($root, 'analytics') as $row) {
            $secid = (string) ($row['secids'] ?? $row['ticker'] ?? '');
            if ($secid !== '' && !isset($list[$secid])) {
                // «ГАЗПРОМ ао», «ИнтерРАОао» — тип бумаги в подписи не нужен.
                $name = trim((string) preg_replace('/[\s-]*ао$/u', '', (string) ($row['shortnames'] ?? $secid)));
                $list[$secid] = ['name' => $name !== '' ? $name : $secid, 'engine' => 'stock', 'market' => 'shares'];
            }
        }

        return $list;
    }

    /**
     * Десятиминутные свечи основного режима торгов за дни с $from по $till включительно, по порядку времени.
     *
     * @return list<array{begin: \DateTimeImmutable, close: float}> begin — UTC
     *
     * @throws \RuntimeException ISS не ответила
     */
    public function candles(string $secid, string $engine, string $market, \DateTimeImmutable $from, \DateTimeImmutable $till): array
    {
        $moscow = new \DateTimeZone('Europe/Moscow');
        $utc = new \DateTimeZone('UTC');
        $path = sprintf('/engines/%s/markets/%s/securities/%s/candles.json', rawurlencode($engine), rawurlencode($market), rawurlencode($secid));
        $candles = [];
        for ($start = 0; ; $start += self::PAGE) {
            $rows = self::rows($this->get($path, [
                'from' => $from->setTimezone($moscow)->format('Y-m-d'),
                'till' => $till->setTimezone($moscow)->format('Y-m-d'),
                'interval' => self::CANDLE_MINUTES,
                'start' => $start,
            ]), 'candles');
            foreach ($rows as $row) {
                if (!is_numeric($row['close'] ?? null) || !\is_string($row['begin'] ?? null)) {
                    continue;
                }
                $begin = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['begin'], $moscow);
                if ($begin !== false) {
                    $candles[] = ['begin' => $begin->setTimezone($utc), 'close' => (float) $row['close']];
                }
            }
            if (\count($rows) < self::PAGE) {
                return $candles;
            }
        }
    }

    /**
     * Дневные свечи основного режима торгов с $from по $till включительно — для индикаторов и графика.
     *
     * @return list<array{date: string, open: float, high: float, low: float, close: float, value: float}> date — Y-m-d, value — оборот, ₽
     *
     * @throws \RuntimeException ISS не ответила
     */
    public function dailyCandles(string $secid, string $engine, string $market, \DateTimeImmutable $from, \DateTimeImmutable $till): array
    {
        $moscow = new \DateTimeZone('Europe/Moscow');
        $path = sprintf('/engines/%s/markets/%s/securities/%s/candles.json', rawurlencode($engine), rawurlencode($market), rawurlencode($secid));
        $candles = [];
        for ($start = 0; ; $start += self::PAGE) {
            $rows = self::rows($this->get($path, [
                'from' => $from->setTimezone($moscow)->format('Y-m-d'),
                'till' => $till->setTimezone($moscow)->format('Y-m-d'),
                'interval' => 24,
                'start' => $start,
            ]), 'candles');
            foreach ($rows as $row) {
                if (!is_numeric($row['close'] ?? null) || !\is_string($row['begin'] ?? null)) {
                    continue;
                }
                $close = (float) $row['close'];
                $candles[] = [
                    'date' => substr($row['begin'], 0, 10),
                    'open' => is_numeric($row['open'] ?? null) ? (float) $row['open'] : $close,
                    'high' => is_numeric($row['high'] ?? null) ? (float) $row['high'] : $close,
                    'low' => is_numeric($row['low'] ?? null) ? (float) $row['low'] : $close,
                    'close' => $close,
                    'value' => is_numeric($row['value'] ?? null) ? (float) $row['value'] : 0.0,
                ];
            }
            if (\count($rows) < self::PAGE) {
                return $candles;
            }
        }
    }

    /**
     * Сведения о бумаге из /securities/{secid}.json: полное название, тип, ISIN, уровень листинга,
     * у облигаций — погашение, купон, номинал.
     *
     * @return array<string, array{title: string, value: string}> по коду поля ISS (NAME, ISIN, MATDATE…)
     *
     * @throws \RuntimeException ISS не ответила
     */
    public function description(string $secid): array
    {
        $fields = [];
        foreach (self::rows($this->get('/securities/'.rawurlencode($secid).'.json', ['iss.only' => 'description']), 'description') as $row) {
            if (\is_string($row['name'] ?? null) && ($row['value'] ?? null) !== null && (string) $row['value'] !== '') {
                $fields[$row['name']] = ['title' => (string) ($row['title'] ?? $row['name']), 'value' => (string) $row['value']];
            }
        }

        return $fields;
    }

    /**
     * Дивиденды акции: дата закрытия реестра и сумма на акцию, по порядку дат.
     *
     * @return list<array{date: string, value: float, currency: string}>
     *
     * @throws \RuntimeException ISS не ответила
     */
    public function dividends(string $secid): array
    {
        $list = [];
        foreach (self::rows($this->get('/securities/'.rawurlencode($secid).'/dividends.json', []), 'dividends') as $row) {
            if (\is_string($row['registryclosedate'] ?? null) && is_numeric($row['value'] ?? null)) {
                $list[] = ['date' => $row['registryclosedate'], 'value' => (float) $row['value'], 'currency' => (string) ($row['currencyid'] ?? 'RUB')];
            }
        }
        usort($list, static fn (array $a, array $b) => $a['date'] <=> $b['date']);

        return $list;
    }

    /**
     * @param array<string, scalar> $query
     *
     * @return array<string, mixed>
     */
    private function get(string $path, array $query): array
    {
        try {
            return $this->http->request('GET', self::BASE.$path, ['query' => ['iss.meta' => 'off'] + $query, 'timeout' => 20])->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException('ISS Мосбиржи: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Таблица ISS {columns, data} — строками «колонка → значение».
     *
     * @param array<string, mixed> $root
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(array $root, string $name): array
    {
        $columns = $root[$name]['columns'] ?? null;
        $data = $root[$name]['data'] ?? null;
        if (!\is_array($columns) || !\is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $row) {
            if (\is_array($row) && \count($row) === \count($columns)) {
                $rows[] = array_combine($columns, $row);
            }
        }

        return $rows;
    }
}
