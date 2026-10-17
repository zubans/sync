<?php

namespace App\Tests\Service;

use App\Service\NewsEmbeddingComparison;
use PHPUnit\Framework\TestCase;

final class NewsEmbeddingComparisonTest extends TestCase
{
    public function testPairsFromDigestStoriesSkipNewsWithoutVector(): void
    {
        $pairs = NewsEmbeddingComparison::pairs([[[1, 2, 9], [3]], [[3, 1]]], [1 => true, 2 => true, 3 => true]);

        self::assertSame([[1, 2], [1, 3]], $pairs['same'], 'пара одной сводки в другой — одно событие');
        self::assertSame([[2, 3]], $pairs['different']);
    }

    public function testScoreFindsSeparatingThreshold(): void
    {
        $s = NewsEmbeddingComparison::score([0.9, 0.8, 0.85], [0.3, 0.5, 0.82]);

        self::assertEqualsWithDelta(8 / 9, $s['auc'], 1e-9);
        self::assertSame(0.8, $s['threshold'], 'при равной точности — нижний порог');
        self::assertEqualsWithDelta((2 / 3 + 1) / 2, $s['accuracy'], 1e-9);
        self::assertEqualsWithDelta(0.85, $s['same'], 1e-9);
    }
}
