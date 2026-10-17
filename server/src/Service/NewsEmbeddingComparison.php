<?php

namespace App\Service;

/**
 * Сравнение моделей векторов на разметке из часовых сводок: две новости одного сюжета сводки — одно событие,
 * новости разных сюжетов одной сводки — разные события (тот же час и часто та же тема — трудные примеры).
 * Хорошая модель даёт первым парам заметно большее сходство, чем вторым.
 */
final class NewsEmbeddingComparison
{
    /**
     * Пары id новостей по сюжетам сводок; новости вне [known] (нет вектора) пропускаются.
     *
     * @param list<list<list<int>>> $digests сводки → сюжеты → id новостей
     * @param array<int, true>      $known
     *
     * @return array{same: list<array{int, int}>, different: list<array{int, int}>}
     */
    public static function pairs(array $digests, array $known): array
    {
        $same = [];
        $different = [];
        foreach ($digests as $stories) {
            $stories = array_values(array_filter(array_map(
                static fn (array $ids) => array_values(array_unique(array_filter($ids, static fn (int $id) => isset($known[$id])))),
                $stories,
            ), static fn (array $ids) => $ids !== []));
            foreach ($stories as $s => $ids) {
                foreach ($ids as $i => $a) {
                    foreach (\array_slice($ids, $i + 1) as $b) {
                        $same[self::key($a, $b)] = [min($a, $b), max($a, $b)];
                    }
                    foreach (\array_slice($stories, $s + 1) as $other) {
                        foreach ($other as $b) {
                            if ($a !== $b) {
                                $different[self::key($a, $b)] = [min($a, $b), max($a, $b)];
                            }
                        }
                    }
                }
            }
        }

        // Одна и та же пара в разных сводках может попасть в оба списка — тогда она «одно событие».
        return ['same' => array_values($same), 'different' => array_values(array_diff_key($different, $same))];
    }

    /**
     * @param list<float> $same      сходство пар одного события
     * @param list<float> $different сходство пар разных событий
     *
     * @return array{same: float, different: float, auc: float, threshold: float, accuracy: float} средние сходства;
     *         auc — вероятность, что пара одного события ближе пары разных (0,5 — наугад, 1 — идеально);
     *         threshold — порог, лучше всего делящий пары; accuracy — средняя доля верных по обоим видам пар при нём
     */
    public static function score(array $same, array $different): array
    {
        if ($same === [] || $different === []) {
            return ['same' => 0.0, 'different' => 0.0, 'auc' => 0.0, 'threshold' => 0.0, 'accuracy' => 0.0];
        }
        $all = [];
        foreach ($same as $s) {
            $all[] = [$s, 1];
        }
        foreach ($different as $s) {
            $all[] = [$s, 0];
        }
        usort($all, static fn (array $a, array $b) => $a[0] <=> $b[0]);

        // AUC по рангам (Манна — Уитни), равные значения — средний ранг.
        $rankSum = 0.0;
        for ($i = 0, $n = \count($all); $i < $n;) {
            $j = $i;
            while ($j + 1 < $n && $all[$j + 1][0] === $all[$i][0]) {
                ++$j;
            }
            $rank = ($i + $j) / 2 + 1;
            for ($k = $i; $k <= $j; ++$k) {
                $rankSum += $all[$k][1] * $rank;
            }
            $i = $j + 1;
        }
        $p = \count($same);
        $q = \count($different);
        $auc = ($rankSum - $p * ($p + 1) / 2) / ($p * $q);

        // Порог «не ниже — одно событие»: перебор по значениям снизу вверх.
        $best = ['threshold' => $all[0][0], 'accuracy' => 0.0];
        $sameBelow = 0;
        $differentBelow = 0;
        foreach ($all as $i => [$value, $isSame]) {
            if ($i === 0 || $value !== $all[$i - 1][0]) {
                $accuracy = (($p - $sameBelow) / $p + $differentBelow / $q) / 2;
                if ($accuracy > $best['accuracy']) {
                    $best = ['threshold' => $value, 'accuracy' => $accuracy];
                }
            }
            $isSame ? ++$sameBelow : ++$differentBelow;
        }

        return ['same' => array_sum($same) / $p, 'different' => array_sum($different) / $q, 'auc' => $auc] + $best;
    }

    private static function key(int $a, int $b): string
    {
        return min($a, $b).'-'.max($a, $b);
    }
}
