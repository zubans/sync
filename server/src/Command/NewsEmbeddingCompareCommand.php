<?php

namespace App\Command;

use App\Entity\NewsDigest;
use App\Entity\NewsItem;
use App\Service\NewsEmbedder;
use App\Service\NewsEmbeddingComparison;
use App\Service\NewsStorylineService;
use App\Service\NewsVector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Сравнение векторов Gemini и Qwen (Ollama) на разметке из часовых сводок ({@see NewsEmbeddingComparison}).
 * Векторы Gemini берутся из базы (лимит API не тратится), Qwen считается по тем же новостям и кэшируется
 * в `var/embedding-compare/` — повторный запуск мгновенный. Сюжеты и векторы в базе не меняются.
 */
#[AsCommand(name: 'app:news:embedding-compare', description: 'Сравнить векторы Gemini и Qwen на сюжетах сводок')]
final class NewsEmbeddingCompareCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly HttpClientInterface $http,
        #[Autowire('%env(NEWS_EMBEDDINGS_URL)%')]
        private readonly string $ollamaUrl,
        #[Autowire('%kernel.project_dir%/var/embedding-compare')]
        private readonly string $cacheDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Сводки за сколько последних часов', '48')
            ->addOption('ollama-model', null, InputOption::VALUE_REQUIRED, 'Модель Ollama', 'qwen3-embedding:4b');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1G');
        $since = new \DateTimeImmutable('-'.max(1, (int) $input->getOption('hours')).' hours', new \DateTimeZone('UTC'));
        $digests = $this->em->createQueryBuilder()->select('d')->from(NewsDigest::class, 'd')
            ->where('d.period = :hour AND d.periodEnd >= :since')
            ->setParameter('hour', NewsDigest::PERIOD_HOUR)->setParameter('since', $since)
            ->getQuery()->getResult();
        $stories = array_map(static fn (NewsDigest $d) => array_map(static fn (array $s) => array_map('intval', $s['items']), $d->getStories()), $digests);
        $ids = array_values(array_unique(array_merge([], ...array_merge([], ...$stories))));

        /** @var array<int, NewsItem> $items новости сводок с вектором Gemini */
        $items = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->em->getRepository(NewsItem::class)->findBy(['id' => $chunk]) as $item) {
                if ($item->getEmbedding() !== null) {
                    $items[(int) $item->getId()] = $item;
                }
            }
        }
        $pairs = NewsEmbeddingComparison::pairs($stories, array_fill_keys(array_keys($items), true));
        $output->writeln(sprintf('сводок %d, новостей с вектором Gemini %d из %d; пар: одно событие %d, разные %d',
            \count($digests), \count($items), \count($ids), \count($pairs['same']), \count($pairs['different'])));
        if ($pairs['same'] === [] || $pairs['different'] === []) {
            $output->writeln('Мало пар — нужны часовые сводки по новостям с векторами Gemini');

            return Command::FAILURE;
        }

        $model = (string) $input->getOption('ollama-model');
        $started = microtime(true);
        [$qwen, $computed] = $this->ollamaVectors($items, $model, $output);
        $models = [
            'gemini-embedding-001' => array_map(static fn (NewsItem $i) => (array) $i->getEmbedding(), $items),
            $model => $qwen,
        ];
        if ($computed > 0) {
            $output->writeln(sprintf('%s: посчитано %d векторов, %.1f с на 100 новостей', $model, $computed, (microtime(true) - $started) / $computed * 100));
        }

        $language = static fn (int $id) => $items[$id]->getSource()->getLanguage();
        $crossLanguage = static fn (array $list) => array_values(array_filter($list, static fn (array $p) => $language($p[0]) !== $language($p[1])));
        $sets = ['все пары' => $pairs, 'на разных языках' => ['same' => $crossLanguage($pairs['same']), 'different' => $crossLanguage($pairs['different'])]];
        foreach ($sets as $title => $set) {
            $output->writeln(sprintf("\n%s (одно событие %d, разные %d):", $title, \count($set['same']), \count($set['different'])));
            $output->writeln(sprintf('  %-22s %8s %8s %7s %6s %6s %9s', 'модель', 'одно', 'разные', 'разрыв', 'AUC', 'порог', 'точность'));
            foreach ($models as $name => $vectors) {
                $similarity = static fn (array $p) => NewsVector::similarity($vectors[$p[0]], $vectors[$p[1]]);
                $s = NewsEmbeddingComparison::score(array_map($similarity, $set['same']), array_map($similarity, $set['different']));
                $output->writeln(sprintf('  %-22s %8.3f %8.3f %7.3f %6.3f %6.2f %8.0f%%', $name, $s['same'], $s['different'], $s['same'] - $s['different'], $s['auc'], $s['threshold'], $s['accuracy'] * 100));
            }
        }
        $output->writeln("\nAUC — вероятность, что пара одного события ближе пары разных (0,5 — наугад, 1 — идеально); порог и точность — лучшее деление пар.");

        return Command::SUCCESS;
    }

    /**
     * @param array<int, NewsItem> $items
     *
     * @return array{array<int, list<float>>, int} векторы по id и сколько посчитано заново
     */
    private function ollamaVectors(array $items, string $model, OutputInterface $output): array
    {
        $file = $this->cacheDir.'/'.preg_replace('/[^a-z0-9.]+/i', '-', $model).'.json';
        $cache = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
        $missing = array_values(array_filter($items, static fn (NewsItem $i) => !isset($cache[(int) $i->getId()])));
        $embedder = new NewsEmbedder($this->http, $this->ollamaUrl, $model, NewsEmbedder::PROVIDER_OLLAMA);
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
        foreach (array_chunk($missing, 64) as $n => $batch) {
            $output->writeln(sprintf('%s: векторы %d–%d из %d', $model, $n * 64 + 1, $n * 64 + \count($batch), \count($missing)));
            foreach ($embedder->embed(array_map(NewsStorylineService::embeddingText(...), $batch)) as $i => $vector) {
                $cache[(int) $batch[$i]->getId()] = NewsVector::encode($vector);
            }
            file_put_contents($file, json_encode($cache));
        }
        $vectors = [];
        foreach ($items as $id => $item) {
            $vectors[$id] = NewsVector::decode((string) $cache[$id]);
        }

        return [$vectors, \count($missing)];
    }
}
