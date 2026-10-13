<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Данные Мосбиржи из ISS (без подписки — с задержкой 15 минут): инструменты, с которыми связываются
 * новости, и десятиминутные свечи для замера реакции цены. Время ISS — московское, наружу — UTC.
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
