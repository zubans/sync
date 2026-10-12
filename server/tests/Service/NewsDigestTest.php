<?php

namespace App\Tests\Service;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Entity\NewsSource;
use App\Repository\NewsItemRepository;
use App\Repository\NewsSourceRepository;
use App\Service\AgyNewsSummarizer;
use App\Service\NewsDigestPeriod;
use App\Service\NewsDigestPrompt;
use App\Service\NewsDigestService;
use App\Service\NewsSummarizer;
use App\Tests\DatabaseWebTestCase;

final class NewsDigestTest extends DatabaseWebTestCase
{
    private const NOW = '2026-10-12 14:03:00';

    private NewsSource $rbc;
    private NewsSource $digest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rbc = new NewsSource('rbc', 'РБК', 'https://rbc.example/feed');
        $this->digest = new NewsSource('digest', 'Сводка', '', NewsSource::FORMAT_DIGEST);
        $this->em->persist($this->rbc);
        $this->em->persist($this->digest);
        $this->em->flush();
    }

    private function news(int $count, string $seenAt): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $at = new \DateTimeImmutable($seenAt, new \DateTimeZone('UTC'));
            $item = new NewsItem($this->rbc, sha1("$seenAt-$i"), $at);
            $item->apply("https://rbc.example/$i", "Новость $i", "Анонс $i", null, $at, $at);
            $this->em->persist($item);
        }
        $this->em->flush();
    }

    /** @param list<string|\Throwable> $answers ответы заглушки модели по очереди */
    private function service(array $answers, ?array &$prompts = null): NewsDigestService
    {
        $prompts = [];
        $summarizer = new class($answers, $prompts) implements NewsSummarizer {
            /** @param list<string|\Throwable> $answers */
            public function __construct(private array $answers, private array &$prompts)
            {
            }

            public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
            {
                $this->prompts[] = $prompt;
                $answer = array_shift($this->answers) ?? throw new \LogicException('Лишний запрос к модели');

                return $answer instanceof \Throwable ? throw $answer : $answer;
            }
        };

        return new NewsDigestService(
            $this->em,
            static::getContainer()->get(NewsSourceRepository::class),
            static::getContainer()->get(NewsItemRepository::class),
            $summarizer,
        );
    }

    /** Ответ модели на все новости, которые есть в базе. */
    private function answer(string $headline = 'Главное'): string
    {
        $ids = array_map(static fn (NewsItem $n) => $n->getId(), $this->em->getRepository(NewsItem::class)->findBy(['source' => $this->rbc]));

        return 'Вот сводка: '.json_encode(['headline' => $headline, 'stories' => [
            ['title' => 'Сюжет', 'text' => 'Суть.', 'items' => [...$ids, 999999]],
            ['title' => '', 'text' => 'без заголовка'],
        ], 'article' => ['title' => "Статья: $headline", 'text' => "Первый абзац.\r\n\n\n\nВторой абзац."]], \JSON_UNESCAPED_UNICODE);
    }

    public function testDuePeriodsAreLastFullHourAndDayTo0700Moscow(): void
    {
        $morning = NewsDigestPeriod::due(new \DateTimeImmutable('2026-10-12 03:59:00', new \DateTimeZone('UTC')));
        self::assertSame('hour:2026-10-12T03:00Z', $morning[0]->key());
        self::assertSame('2026-10-12 02:00', $morning[0]->start->format('Y-m-d H:i'));
        self::assertSame('day:2026-10-11T04:00Z', $morning[1]->key(), 'до 07:00 по Москве — сводка за прошлые сутки');

        $day = NewsDigestPeriod::due(new \DateTimeImmutable('2026-10-12 04:00:00', new \DateTimeZone('UTC')));
        self::assertSame('day:2026-10-12T04:00Z', $day[1]->key());
        self::assertSame('2026-10-11 04:00', $day[1]->start->format('Y-m-d H:i'));
    }

    public function testHourlyDigestIsWrittenOnceFromNewsOfThePeriod(): void
    {
        $this->news(6, '2026-10-12 13:20:00');
        $this->news(2, '2026-10-12 12:50:00'); // прошлый час — не в часовой сводке
        $this->news(3, '2026-10-12 01:00:00'); // сутки к 07:00 по Москве
        $service = $this->service([$this->answer('Главное за час'), $this->answer('Главное за сутки')], $prompts);

        $report = $service->run(new \DateTimeImmutable(self::NOW));

        self::assertSame('готово: новостей 6, сюжетов 1', $report['hour:2026-10-12T14:00Z']);
        self::assertSame('готово: новостей 3, сюжетов 1', $report['day:2026-10-12T04:00Z']);
        self::assertStringContainsString('за час (12.10 16:00–12.10 17:00, время московское)', $prompts[0]);
        self::assertStringContainsString('] РБК, 12.10 16:20 — Новость 0. Анонс 0', $prompts[0]);
        self::assertStringContainsString('одну связную статью — обзор за час', $prompts[0]);

        $item = $this->em->getRepository(NewsItem::class)->findOneBy(['externalKey' => sha1('hour:2026-10-12T14:00Z')]);
        self::assertSame('За час: Главное за час', $item->getTitle());
        self::assertSame('2026-10-12 14:00', $item->getSortAt()->format('Y-m-d H:i'));
        $details = $this->em->getRepository(NewsDigest::class)->findOneBy(['item' => $item]);
        self::assertCount(1, $details->getStories(), 'сюжет без заголовка выброшен');
        self::assertCount(6, $details->getStories()[0]['items'], 'чужой id из ответа выброшен');
        self::assertSame(['title' => 'Статья: Главное за час', 'text' => "Первый абзац.\n\nВторой абзац."], $details->getArticle());
        self::assertSame("Первый абзац.\n\nВторой абзац.", $item->getContent(), 'полный текст сводки — статья');
        self::assertNull($this->digest->getLastError());

        self::assertSame([], $service->run(new \DateTimeImmutable(self::NOW)), 'повторный запуск ничего не пишет');
    }

    public function testQuietHourIsSkippedWithoutModel(): void
    {
        $this->news(2, '2026-10-12 13:20:00');

        $report = $this->service([])->run(new \DateTimeImmutable(self::NOW));

        self::assertSame('пропущено: новостей 2', $report['hour:2026-10-12T14:00Z']);
        self::assertSame('пропущено: новостей нет', $report['day:2026-10-12T04:00Z']);
        self::assertNull($this->digest->getLastError());
    }

    public function testUnparsableAnswerIsRetriedOnceThenRecordedAsError(): void
    {
        $this->news(6, '2026-10-12 13:20:00');
        $this->news(1, '2026-10-12 01:00:00');
        $retried = $this->service(['Не могу.', $this->answer(), $this->answer()], $prompts)->run(new \DateTimeImmutable(self::NOW));
        self::assertStringStartsWith('готово', $retried['hour:2026-10-12T14:00Z']);
        self::assertStringContainsString('строго один JSON-объект', $prompts[1]);

        $this->em->getRepository(NewsItem::class)->createQueryBuilder('n')->delete()->where('n.source = :d')->setParameter('d', $this->digest)->getQuery()->execute();
        $failed = $this->service(['нет', 'опять нет', new \RuntimeException('agy: квота исчерпана')])->run(new \DateTimeImmutable(self::NOW));

        self::assertSame('ошибка: Ответ модели — не JSON: «опять нет»', $failed['hour:2026-10-12T14:00Z'], 'в ошибке — начало ответа');
        self::assertSame('ошибка: agy: квота исчерпана', $failed['day:2026-10-12T04:00Z']);
        self::assertSame('agy: квота исчерпана', $this->digest->getLastError());
    }

    public function testRebuildRewritesExistingDigest(): void
    {
        $this->news(6, '2026-10-12 13:20:00');
        $this->news(1, '2026-10-12 01:00:00');
        $this->service([$this->answer('Первая'), $this->answer()])->run(new \DateTimeImmutable(self::NOW));

        $this->service([$this->answer('Вторая'), $this->answer()])->run(new \DateTimeImmutable(self::NOW), rebuild: true);

        $items = $this->em->getRepository(NewsItem::class)->findBy(['externalKey' => sha1('hour:2026-10-12T14:00Z')]);
        self::assertCount(1, $items);
        self::assertSame('За час: Вторая', $items[0]->getTitle());
        self::assertCount(2, $this->em->getRepository(NewsDigest::class)->findAll());
    }

    public function testPreviewSummarizesAnyWindowWithoutSaving(): void
    {
        $this->news(2, '2026-10-12 13:20:00');
        $period = new NewsDigestPeriod(NewsDigest::PERIOD_DAY,
            new \DateTimeImmutable('2026-10-12 11:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC')));

        $result = $this->service([$this->answer('Предпросмотр')])->preview($period);

        self::assertSame(2, $result['news'], 'в предпросмотре нет порога в 5 новостей');
        self::assertSame('Предпросмотр', $result['digest']['headline']);
        self::assertSame([], $this->em->getRepository(NewsDigest::class)->findAll());
        self::assertNull($this->digest->getLastAttemptAt());
    }

    public function testDisabledDigestSourceWritesNothing(): void
    {
        $this->digest->setEnabled(false);
        $this->em->flush();

        self::assertSame([], $this->service([])->run(new \DateTimeImmutable(self::NOW)));
    }

    public function testPromptParserTakesJsonFromWrappedAnswer(): void
    {
        $parsed = NewsDigestPrompt::parse("```json\n{\"stories\": [{\"title\": \"Сюжет\", \"text\": \"Суть\", \"items\": [1, \"2\", 7]}]}\n```", [1, 2]);

        self::assertSame('Сюжет', $parsed['headline'], 'без заголовка сводки — заголовок первого сюжета');
        self::assertSame([1, 2], $parsed['stories'][0]['items']);
        self::assertNull($parsed['article'], 'без статьи сводка остаётся списком сюжетов');
    }

    public function testAgyOutputGivesAnswerConversationAndTrace(): void
    {
        $ok = AgyNewsSummarizer::parse(implode("\n", [
            '{"event":"init","conversation_id":"c1","init":{"model":"Gemini"}}',
            '{"event":"step_update","step_update":{"step_type":"agent_response","text_delta":"{"}}',
            'не JSON',
            '{"event":"result","result":{"status":"SUCCESS","response":"{\\"stories\\":[]}","conversation_id":"c1"}}',
        ]));
        self::assertSame('{"stories":[]}', $ok['answer']);
        self::assertSame('c1', $ok['conversation']);
        self::assertNull($ok['error']);

        // Ход ушёл на собственные инструменты агента, итогового события нет — ответа нет, но есть разговор для повтора.
        $tools = AgyNewsSummarizer::parse(implode("\n", [
            '{"event":"init","conversation_id":"c2","init":{}}',
            '{"event":"step_update","step_update":{"step_type":"tool","tool_name":"ask_question"}}',
            '{"event":"step_update","step_update":{"step_type":"tool","tool_name":"ask_question"}}',
        ]));
        self::assertSame('', $tools['answer']);
        self::assertSame('c2', $tools['conversation']);
        self::assertSame(['ask_question'], $tools['tools']);
        self::assertSame('события: init, step_update:tool×2; инструменты: ask_question; итогового события нет', $tools['trace']);

        // Текст напечатан, итогового события нет — берём напечатанное.
        $streamed = AgyNewsSummarizer::parse('{"event":"step_update","step_update":{"step_type":"agent_response","text_delta":"{\\"a\\":1}"}}');
        self::assertSame('{"a":1}', $streamed['answer']);

        $structured = AgyNewsSummarizer::parse('{"event":"result","result":{"status":"SUCCESS","response":"{\\"a\\":1}{\\"toolAction\\":\\"x\\"}","structured_output":{"headline":"Главное"}}}');
        self::assertSame('{"headline":"Главное"}', $structured['answer'], 'с --json-schema ответ — structured_output');

        // «х» в UTF-8 — D1 85: строка не должна рваться на байте 0x85.
        $cyrillic = AgyNewsSummarizer::parse('{"event":"result","result":{"status":"SUCCESS","response":"{\\"headline\\":\\"хуситы\\"}"}}');
        self::assertSame('{"headline":"хуситы"}', $cyrillic['answer']);

        $denied = AgyNewsSummarizer::parse('{"event":"result","result":{"status":"ERROR","error":"authentication failed or timed out"}}');
        self::assertSame('authentication failed or timed out', $denied['error']);
    }
}
