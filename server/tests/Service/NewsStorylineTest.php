<?php

namespace App\Tests\Service;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Entity\NewsStoryline;
use App\Repository\NewsItemRepository;
use App\Repository\NewsStorylineRepository;
use App\Service\NewsDigestPeriod;
use App\Service\NewsDigestPrompt;
use App\Service\NewsEmbedder;
use App\Service\NewsStorylineService;
use App\Service\NewsSummarizer;
use App\Service\NewsVector;
use App\Tests\DatabaseWebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class NewsStorylineTest extends DatabaseWebTestCase
{
    /**
     * Векторы по первому слову заголовка: «Эр-Рияд» близки между собой, «Сбербанк» — далеко,
     * «Saudi» — то же событие, но сходство (~0,69) ниже порога привязки: склеить может только модель.
     */
    private const VECTORS = [
        'Эр-Рияд' => [1.0, 0.1, 0.0],
        'Riyadh' => [0.95, 0.2, 0.05],
        'Сбербанк' => [0.0, 0.1, 1.0],
        'Saudi' => [0.7, -0.1, 0.7],
    ];

    private NewsSource $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $this->em->persist($this->source);
        $this->em->flush();
    }

    private function news(string $title, string $at): NewsItem
    {
        $time = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
        $item = new NewsItem($this->source, sha1($title.$at), $time);
        $item->apply('https://example.com/'.sha1($title.$at), $title, null, null, $time, $time);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** @param list<string> $names ответы модели с названиями */
    private function service(array $names = []): NewsStorylineService
    {
        $ollama = new MockHttpClient(static function (string $method, string $url, array $options) {
            $input = json_decode($options['body'], true)['input'];
            $vectors = array_map(static fn (string $text) => self::VECTORS[strtok($text, ' .')] ?? [0.0, 1.0, 0.0], $input);

            return new MockResponse(json_encode(['embeddings' => $vectors]));
        });
        $summarizer = new class($names) implements NewsSummarizer {
            /** @param list<string> $names */
            public function __construct(private array $names)
            {
            }

            public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
            {
                return array_shift($this->names) ?? throw new \LogicException('Лишний запрос к модели');
            }
        };

        return new NewsStorylineService(
            $this->em,
            static::getContainer()->get(NewsItemRepository::class),
            static::getContainer()->get(NewsStorylineRepository::class),
            new NewsEmbedder($ollama, 'http://ollama.test:11434'),
            $summarizer,
        );
    }

    public function testSimilarNewsJoinOneStorylineAcrossLanguagesAndGetName(): void
    {
        $first = $this->news('Эр-Рияд удар по аэропорту', '2026-10-12 06:00:00');
        $english = $this->news('Riyadh airport hit again', '2026-10-12 07:00:00');
        $other = $this->news('Сбербанк отчитался о прибыли', '2026-10-12 07:30:00');

        $result = $this->runWithName();

        self::assertSame(3, $result['embedded']);
        self::assertSame(3, $result['assigned']);
        self::assertSame(2, $result['created']);
        self::assertSame($first->getStoryline(), $english->getStoryline(), 'одно событие на разных языках — одна линия');
        self::assertNotSame($first->getStoryline(), $other->getStoryline());
        self::assertSame(2, $first->getStoryline()->getNewsCount());
        self::assertSame('Удары хуситов по аэропорту Эр-Рияда', $first->getStoryline()->getTitle());
        self::assertNull($other->getStoryline()->getTitle(), 'линия из одной новости без названия');
    }

    public function testModelMergesStorylinesFoundSeparately(): void
    {
        $ru = [$this->news('Эр-Рияд удар по аэропорту', '2026-10-12 06:00:00'), $this->news('Эр-Рияд снова удар', '2026-10-12 06:30:00')];
        $en = [$this->news('Saudi airport hit', '2026-10-12 07:00:00'), $this->news('Saudi airport hit again', '2026-10-12 07:30:00')];
        $first = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM news_storyline') + 1;
        $answer = json_encode([
            'lines' => [['id' => $first, 'title' => 'Удары по аэропорту Эр-Рияда', 'summary' => ''], ['id' => $first + 1, 'title' => 'Удар по аэропорту Саудовской Аравии', 'summary' => '']],
            'merge' => [[$first, $first + 1], [$first, 999]],
        ], \JSON_UNESCAPED_UNICODE);

        $result = $this->service([$answer])->run(new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC')));

        self::assertNull($result['error']);
        self::assertSame(2, $result['created'], 'по векторам — две линии');
        self::assertSame(1, $result['merged'], 'пара не из кандидатов (999) не склеивается');
        $line = $ru[0]->getStoryline();
        foreach ([...$ru, ...$en] as $item) {
            self::assertSame($line, $item->getStoryline());
        }
        self::assertSame(4, $line->getNewsCount());
        self::assertSame('Удары по аэропорту Эр-Рияда', $line->getTitle());
        self::assertTrue($line->needsName(), 'после склейки название обновится');
        $this->em->clear();
        self::assertSame(1, (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM news_storyline'));
    }

    public function testOldStorylineIsClosedAfterQuietDays(): void
    {
        $old = $this->news('Эр-Рияд удар по аэропорту', '2026-10-01 06:00:00');
        $new = $this->news('Эр-Рияд снова удар', '2026-10-12 06:00:00');

        $this->service([])->run(new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC')));

        self::assertNotSame($old->getStoryline(), $new->getStoryline(), 'через неделю тишины — новая линия');
    }

    public function testDigestPromptMentionsEarlierStoryline(): void
    {
        $line = new NewsStoryline([1.0, 0.0, 0.0], new \DateTimeImmutable('2026-10-10 06:00:00'));
        $line->name('Удары хуситов по Эр-Рияду', null);
        $this->em->persist($line);
        $item = $this->news('Эр-Рияд снова удар', '2026-10-12 06:30:00');
        $item->setStoryline($line);
        $period = new NewsDigestPeriod(NewsDigest::PERIOD_HOUR,
            new \DateTimeImmutable('2026-10-12 06:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-10-12 07:00:00', new \DateTimeZone('UTC')));

        self::assertStringContainsString('{сюжет «Удары хуситов по Эр-Рияду», с 10.10, новостей 1}', NewsDigestPrompt::build($period, [$item]));
    }

    public function testVectorsSurviveStorageAndCentroidIsNormalized(): void
    {
        $vector = NewsVector::normalize([3.0, 4.0]);
        self::assertEqualsWithDelta([0.6, 0.8], NewsVector::decode(NewsVector::encode($vector)), 1e-6);
        $centroid = NewsVector::addToCentroid([1.0, 0.0], 1, [0.0, 1.0]);
        self::assertEqualsWithDelta(1.0, NewsVector::similarity($centroid, $centroid), 1e-6);
        self::assertEqualsWithDelta(sqrt(0.5), $centroid[0], 1e-6);
    }

    /**
     * Прогон, где модель называет линию первой новости. Линии создаются по порядку новостей,
     * поэтому id линии первой новости — следующий в таблице.
     *
     * @return array{embedded: int, assigned: int, created: int, named: int, merged: int, error: ?string}
     */
    private function runWithName(): array
    {
        $nextId = (int) $this->em->getConnection()->fetchOne('SELECT COALESCE(MAX(id), 0) FROM news_storyline') + 1;
        $answer = json_encode(['lines' => [['id' => $nextId, 'title' => '«Удары хуситов по аэропорту Эр-Рияда»', 'summary' => 'Хуситы снова атакуют.']]], \JSON_UNESCAPED_UNICODE);

        return $this->service([$answer])->run(new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC')));
    }
}
