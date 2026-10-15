<?php

namespace App\Tests\Service;

use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Service\NewsSummarizer;
use App\Service\NewsTranslationPrompt;
use App\Service\NewsTranslator;
use App\Tests\DatabaseWebTestCase;

final class NewsTranslationTest extends DatabaseWebTestCase
{
    private NewsSource $bbc;
    /** Заглушка модели: ответы по очереди, исключение в очереди — сбой agy; промпты запоминаются. */
    private object $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bbc = (new NewsSource('bbc', 'BBC', 'https://bbc.example/sitemap', NewsSource::FORMAT_NEWS_SITEMAP))->setLanguage(NewsSource::LANGUAGE_EN);
        $this->em->persist($this->bbc);
        $this->em->flush();
        $this->model = new class implements NewsSummarizer {
            /** @var list<string|\Throwable> */
            public array $answers = [];
            /** @var list<string> */
            public array $prompts = [];

            public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
            {
                $this->prompts[] = $prompt;
                $answer = array_shift($this->answers) ?? throw new \LogicException('Лишний запрос к модели');
                if ($answer instanceof \Throwable) {
                    throw $answer;
                }

                return $answer;
            }
        };
    }

    private function news(NewsSource $source, string $title, ?string $summary = null, string $at = '2026-10-12 06:00:00'): NewsItem
    {
        $time = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
        $item = new NewsItem($source, sha1($title.$at), $time);
        $item->apply('https://example.com/'.sha1($title.$at), $title, $summary, null, $time, $time);
        $this->em->persist($item);
        $this->em->flush();

        return $item;
    }

    /** @param list<string> $paragraphs */
    private function fetched(NewsItem $item, array $paragraphs): void
    {
        $item->setFetchedContent(implode("\n\n", $paragraphs), new \DateTimeImmutable('2026-10-12 06:10:00', new \DateTimeZone('UTC')));
        $this->em->flush();
    }

    /** @param list<array{int, string, string}> $rows id, заголовок, анонс */
    private static function headlines(array $rows): string
    {
        return json_encode(['items' => array_map(static fn (array $r) => ['id' => $r[0], 'title' => $r[1], 'summary' => $r[2]], $rows)], \JSON_UNESCAPED_UNICODE);
    }

    /** @param list<string> $paragraphs */
    private static function article(string $title, array $paragraphs): string
    {
        return json_encode(['title' => $title, 'paragraphs' => $paragraphs], \JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<string|\Throwable> $answers
     *
     * @return array{headlines: int, articles: int, failed: int, error: ?string}
     */
    private function translate(array $answers, int $articles = NewsTranslator::ARTICLE_LIMIT): array
    {
        $this->model->answers = $answers;

        return (new NewsTranslator($this->em, static::getContainer()->get(NewsItemRepository::class), $this->model))->run(NewsTranslator::HEADLINE_BATCH, $articles);
    }

    public function testForeignHeadlinesAreTranslatedInOneRequest(): void
    {
        $a = $this->news($this->bbc, 'Riyadh airport hit', 'Houthis strike again.');
        $b = $this->news($this->bbc, 'Markets fall', null, '2026-10-12 07:00:00');

        $result = $this->translate([self::headlines([
            [$a->getId(), 'Удар по аэропорту Эр-Рияда', 'Хуситы снова атакуют.'],
            [$b->getId(), 'Рынки падают', 'Сочинённый анонс'],
        ])]);

        self::assertSame(['headlines' => 2, 'articles' => 0, 'failed' => 0, 'error' => null], $result);
        self::assertCount(1, $this->model->prompts);
        self::assertStringContainsString("[{$a->getId()}] en — Riyadh airport hit. Houthis strike again.", $this->model->prompts[0]);
        self::assertStringContainsString('не вызывай инструменты', $this->model->prompts[0]);
        self::assertSame('Удар по аэропорту Эр-Рияда', $a->getTranslatedTitle());
        self::assertSame('Хуситы снова атакуют.', $a->getTranslatedSummary());
        self::assertSame('Riyadh airport hit', $a->getTitle(), 'оригинал не меняется');
        self::assertNull($b->getTranslatedSummary(), 'анонса у оригинала нет — и перевода нет');
    }

    public function testRussianSourcesAndDigestAreNotTranslated(): void
    {
        $rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $digest = (new NewsSource('digest', 'Сводка', '', NewsSource::FORMAT_DIGEST))->setLanguage(NewsSource::LANGUAGE_EN);
        $this->em->persist($rbc);
        $this->em->persist($digest);
        $this->news($rbc, 'Сбербанк отчитался о прибыли');
        $this->news($digest, 'Главное за час');

        $result = $this->translate([]);

        self::assertSame(['headlines' => 0, 'articles' => 0, 'failed' => 0, 'error' => null], $result);
        self::assertSame([], $this->model->prompts);
        self::assertFalse($digest->needsTranslation());
        self::assertFalse($rbc->needsTranslation());
        self::assertTrue($this->bbc->needsTranslation());
    }

    public function testArticleTranslatedAfterFullTextFetched(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit', 'Houthis strike again.');
        $this->translate([self::headlines([[$item->getId(), 'Удар по аэропорту Эр-Рияда', 'Хуситы снова атакуют.']])]);
        self::assertNull($item->getTranslatedContent(), 'полного текста ещё нет — переводить нечего');

        $this->fetched($item, ['Houthis strike again.', 'The airport was closed.', 'Flights resumed in the evening.']);
        $result = $this->translate([self::article('Удар по аэропорту Эр-Рияда', ['Хуситы снова атакуют.', 'Аэропорт закрыт.', 'Вечером рейсы возобновились.'])]);

        self::assertSame(['headlines' => 0, 'articles' => 1, 'failed' => 0, 'error' => null], $result);
        self::assertStringContainsString('[2] The airport was closed.', $this->model->prompts[1]);
        self::assertStringContainsString('с английского', $this->model->prompts[1]);
        self::assertSame("Хуситы снова атакуют.\n\nАэропорт закрыт.\n\nВечером рейсы возобновились.", $item->getTranslatedContent());
    }

    public function testEditedTitleResetsTranslation(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit', 'Houthis strike again.');
        $this->fetched($item, ['One.', 'Two.']);
        $item->setHeadlineTranslation('Удар по аэропорту', 'Хуситы атакуют.');
        $item->setContentTranslation(['Раз.', 'Два.']);
        $item->markHeadlineTranslationAttempt();
        $item->markContentTranslationAttempt();
        $now = new \DateTimeImmutable('2026-10-12 08:00:00', new \DateTimeZone('UTC'));

        $item->apply($item->getLink(), 'Riyadh airport hit twice', 'Houthis strike again.', null, $item->getPublishedAt(), $now);

        self::assertNull($item->getTranslatedTitle());
        self::assertNull($item->getTranslatedSummary());
        self::assertSame("Раз.\n\nДва.", $item->getTranslatedContent(), 'скачанный текст не изменился — его перевод остаётся');
        self::assertSame(0, $item->getHeadlineTranslationAttempts(), 'правка — новые попытки');
        self::assertSame(1, $item->getContentTranslationAttempts(), 'текст не менялся — его попытки остаются');

        $this->fetched($item, ['One.', 'Two.', 'Three.']);
        self::assertNull($item->getTranslatedContent(), 'новый текст — перевод заново');
        self::assertSame(0, $item->getContentTranslationAttempts());
    }

    public function testUnknownIdsAndEmptyAnswersAreIgnored(): void
    {
        $a = $this->news($this->bbc, 'First', null, '2026-10-12 06:00:00');
        $b = $this->news($this->bbc, 'Second', null, '2026-10-12 06:10:00');
        $c = $this->news($this->bbc, 'Third', null, '2026-10-12 06:20:00');

        $result = $this->translate([self::headlines([[$a->getId(), 'Первая', ''], [$b->getId(), '  ', ''], [999999, 'Чужая', '']])]);

        self::assertSame(1, $result['headlines']);
        self::assertSame(2, $result['failed']);
        self::assertSame('Первая', $a->getTranslatedTitle());
        self::assertNull($b->getTranslatedTitle());
        self::assertNull($c->getTranslatedTitle());
        self::assertSame(1, $b->getHeadlineTranslationAttempts());
        self::assertSame(1, $c->getHeadlineTranslationAttempts());
    }

    public function testGivesUpAfterThreeFailedAttempts(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit');

        for ($i = 0; $i < NewsTranslator::MAX_ATTEMPTS; ++$i) {
            $result = $this->translate(['не JSON']);
            self::assertStringContainsString('не JSON', (string) $result['error'], 'негодный ответ не роняет проход');
        }
        $result = $this->translate([]);

        self::assertSame(NewsTranslator::MAX_ATTEMPTS, $item->getHeadlineTranslationAttempts());
        self::assertCount(NewsTranslator::MAX_ATTEMPTS, $this->model->prompts, 'после трёх неудач новость больше не запрашивается');
        self::assertSame(['headlines' => 0, 'articles' => 0, 'failed' => 0, 'error' => null], $result);
    }

    public function testUnavailableAgyDoesNotCountAttempts(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit');
        $this->fetched($item, ['One.', 'Two.']);

        for ($i = 0; $i < NewsTranslator::MAX_ATTEMPTS + 1; ++$i) {
            $result = $this->translate([new \RuntimeException('agy: квота')]);
            self::assertSame(['headlines' => 0, 'articles' => 0, 'failed' => 0, 'error' => 'agy: квота'], $result, 'проход останавливается на первом сбое agy');
        }

        self::assertSame(0, $item->getHeadlineTranslationAttempts(), 'новость не виновата в недоступности agy');
        self::assertSame(0, $item->getContentTranslationAttempts());

        $result = $this->translate([
            self::headlines([[$item->getId(), 'Удар по аэропорту Эр-Рияда', '']]),
            self::article('Удар по аэропорту Эр-Рияда', ['Раз.', 'Два.']),
        ]);
        self::assertSame(['headlines' => 1, 'articles' => 1, 'failed' => 0, 'error' => null], $result, 'agy вернулся — новость переводится');
    }

    public function testRejectedArticleDoesNotBlockHeadlineAndViceVersa(): void
    {
        $item = $this->news($this->bbc, 'Ten paragraphs', 'Short summary.');
        $this->fetched($item, array_map(static fn (int $i) => "Paragraph $i.", range(1, 10)));
        $item->setHeadlineTranslation('Десять абзацев', 'Кратко.');
        $this->em->flush();

        for ($i = 0; $i < NewsTranslator::MAX_ATTEMPTS; ++$i) {
            $this->translate([self::article('Десять абзацев', ['Пересказ.'])]);
        }
        self::assertSame(NewsTranslator::MAX_ATTEMPTS, $item->getContentTranslationAttempts());

        // Редакция поправила заголовок — его перевод нужен заново, несмотря на исчерпанные попытки текста.
        $item->apply($item->getLink(), 'Ten paragraphs, updated', 'Short summary.', null, $item->getPublishedAt(), new \DateTimeImmutable('2026-10-12 09:00:00', new \DateTimeZone('UTC')));
        $this->em->flush();
        $result = $this->translate([self::headlines([[$item->getId(), 'Десять абзацев, обновлено', 'Кратко.']])]);

        self::assertSame(['headlines' => 1, 'articles' => 0, 'failed' => 0, 'error' => null], $result);
        self::assertSame('Десять абзацев, обновлено', $item->getTranslatedTitle());

        $other = $this->news($this->bbc, 'Unreachable headline', null, '2026-10-12 05:00:00');
        $this->fetched($other, ['One.', 'Two.']);
        for ($i = 0; $i < NewsTranslator::MAX_ATTEMPTS; ++$i) {
            $this->translate(['не JSON'], 0);
        }
        $result = $this->translate([self::article('', ['Раз.', 'Два.'])]);

        self::assertSame(1, $result['articles'], 'исчерпанные попытки заголовка не блокируют перевод текста');
        self::assertSame("Раз.\n\nДва.", $other->getTranslatedContent());
    }

    public function testLongArticleSplitIntoChunks(): void
    {
        $item = $this->news($this->bbc, 'Long read');
        $item->setHeadlineTranslation('Лонгрид', null);
        $paragraphs = array_map(static fn (string $p) => $p.str_repeat(' word', 1000), ['Alpha', 'Beta', 'Gamma', 'Delta']);
        $this->fetched($item, $paragraphs);

        $result = $this->translate([
            self::article('Лонгрид', ['Альфа.', 'Бета.']),
            self::article('', ['Гамма.', 'Дельта.']),
        ]);

        self::assertSame(1, $result['articles']);
        self::assertCount(2, $this->model->prompts, 'около 20 000 символов — две части по границе абзаца');
        self::assertStringContainsString('Заголовок: Long read', $this->model->prompts[0]);
        self::assertStringContainsString('продолжение статьи', $this->model->prompts[1]);
        self::assertStringContainsString('[1] Gamma', $this->model->prompts[1], 'абзацы части нумеруются заново');
        self::assertSame("Альфа.\n\nБета.\n\nГамма.\n\nДельта.", $item->getTranslatedContent());
        self::assertSame([['a', 'b'], ['c']], NewsTranslator::chunks(['a', 'b', 'c'], 2));
    }

    public function testParagraphCountMismatchRejected(): void
    {
        $item = $this->news($this->bbc, 'Ten paragraphs');
        $item->setHeadlineTranslation('Десять абзацев', null);
        $this->fetched($item, array_map(static fn (int $i) => "Paragraph $i.", range(1, 10)));

        $result = $this->translate([self::article('Десять абзацев', ['Кратко.', 'Пересказ.', 'Всего.', 'Пять.', 'Абзацев.'])]);

        self::assertSame(0, $result['articles']);
        self::assertSame(1, $result['failed']);
        self::assertStringContainsString('абзацев 5 вместо 10', (string) $result['error']);
        self::assertNull($item->getTranslatedContent());
        self::assertSame(1, $item->getContentTranslationAttempts());
        self::assertSame(0, $item->getHeadlineTranslationAttempts(), 'отвергнутый текст не трогает попытки заголовка');
    }

    public function testArticlesAreTranslatedOnlyOnRequest(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit', 'Houthis strike again.');
        $this->fetched($item, ['Houthis strike again.', 'The airport was closed.']);
        $translator = new NewsTranslator($this->em, static::getContainer()->get(NewsItemRepository::class), $this->model);

        $this->model->answers = [self::headlines([[$item->getId(), 'Удар по аэропорту', 'Хуситы снова атакуют.']])];
        $translator->run(NewsTranslator::HEADLINE_BATCH, NewsTranslator::AUTO_ARTICLES);
        self::assertNull($item->getTranslatedContent(), 'без просьбы статья не переводится');
        self::assertSame('available', NewsTranslator::contentState($item));
        self::assertSame(['articles' => 0, 'failed' => 0, 'error' => null], $translator->runRequested(), 'просьб нет — к модели не ходим');

        $item->requestContentTranslation(new \DateTimeImmutable('2026-10-15 10:00:00'));
        $this->em->flush();
        self::assertSame('queued', NewsTranslator::contentState($item));
        $this->model->answers = [self::article('Удар по аэропорту', ['Хуситы снова атакуют.', 'Аэропорт закрыт.'])];

        self::assertSame(['articles' => 1, 'failed' => 0, 'error' => null], $translator->runRequested());
        self::assertSame("Хуситы снова атакуют.\n\nАэропорт закрыт.", $item->getTranslatedContent());
        self::assertNull($item->getTranslationRequestedAt(), 'просьба выполнена');
        self::assertSame('done', NewsTranslator::contentState($item));
    }

    public function testRequestedTranslationGivesUpAfterThreeFailuresAndWaitsForAgy(): void
    {
        $item = $this->news($this->bbc, 'Riyadh airport hit');
        $this->fetched($item, ['Houthis strike again.', 'The airport was closed.']);
        $item->requestContentTranslation(new \DateTimeImmutable('2026-10-15 10:00:00'));
        $this->em->flush();
        $translator = new NewsTranslator($this->em, static::getContainer()->get(NewsItemRepository::class), $this->model);

        $this->model->answers = [new \RuntimeException('agy: Individual quota reached')];
        self::assertSame('agy: Individual quota reached', $translator->runRequested()['error']);
        self::assertSame('queued', NewsTranslator::contentState($item), 'agy недоступен — просьба остаётся, попытка не засчитана');

        foreach (range(1, 3) as $n) {
            $this->model->answers = ['не JSON'];
            $translator->runRequested();
        }
        self::assertSame('failed', NewsTranslator::contentState($item), 'после трёх неудач просьба снята');

        $item->requestContentTranslation(new \DateTimeImmutable('2026-10-15 11:00:00'));
        self::assertSame('queued', NewsTranslator::contentState($item), 'можно попросить снова — счётчик сброшен');
    }

    public function testParagraphsGluedIntoOneElementAreSplit(): void
    {
        $blank = NewsTranslationPrompt::parseArticle(json_encode(['paragraphs' => ["Первый.\n\nВторой.\n\nТретий."]], \JSON_UNESCAPED_UNICODE), 3);
        self::assertSame(['Первый.', 'Второй.', 'Третий.'], $blank['paragraphs']);

        $numbered = NewsTranslationPrompt::parseArticle(json_encode(['paragraphs' => ['[1] Первый. [2] Второй. [3] Третий.']], \JSON_UNESCAPED_UNICODE), 3);
        self::assertSame(['Первый.', 'Второй.', 'Третий.'], $numbered['paragraphs']);

        $this->expectException(\UnexpectedValueException::class);
        NewsTranslationPrompt::parseArticle(json_encode(['paragraphs' => ['Короткий пересказ всей статьи.']], \JSON_UNESCAPED_UNICODE), 14);
    }
}
