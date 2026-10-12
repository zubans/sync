<?php

namespace App\Service;

/**
 * Векторы новостей и сюжетов: хранятся в базе строкой (float32 подряд, base64) — так одинаково
 * на SQLite и PostgreSQL без расширений. Векторы нормированы, поэтому сходство — скалярное произведение.
 * Новостей за неделю — тысячи, сравнение перебором в PHP занимает миллисекунды; pgvector понадобится,
 * когда поиск пойдёт по архиву за годы.
 */
final class NewsVector
{
    /** @param list<float> $vector */
    public static function encode(array $vector): string
    {
        return base64_encode(pack('g*', ...$vector));
    }

    /** @return list<float> */
    public static function decode(string $stored): array
    {
        $unpacked = unpack('g*', (string) base64_decode($stored, true));

        return $unpacked === false ? [] : array_values(array_map('floatval', $unpacked));
    }

    /**
     * Первые $dims значений и нормировка. Qwen3-embedding обучен так, что начало вектора само по себе
     * осмысленно (Matryoshka): 1024 из 2560 почти не теряют точности, а места в базе в 2,5 раза меньше.
     *
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function truncate(array $vector, int $dims): array
    {
        return self::normalize(\array_slice($vector, 0, $dims));
    }

    /**
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(static fn (float $x) => $x * $x, $vector)));

        return $norm > 0 ? array_map(static fn (float $x) => $x / $norm, $vector) : $vector;
    }

    /**
     * Сходство нормированных векторов: 1 — одно и то же, около 0 — не связаны.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function similarity(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $x) {
            $sum += $x * ($b[$i] ?? 0.0);
        }

        return $sum;
    }

    /**
     * Центр сюжета после новой новости: среднее всех векторов сюжета, снова нормированное.
     *
     * @param list<float> $centroid
     * @param list<float> $vector
     *
     * @return list<float>
     */
    public static function addToCentroid(array $centroid, int $count, array $vector): array
    {
        $sum = [];
        foreach ($centroid as $i => $x) {
            $sum[] = $x * $count + ($vector[$i] ?? 0.0);
        }

        return self::normalize($sum);
    }
}
