<?php

namespace App\Command;

use App\Entity\NewsItem;
use App\Repository\NewsItemRepository;
use App\Service\NewsEmbedder;
use App\Service\NewsStorylineService;
use App\Service\NewsVector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Подбор порога сюжетов под модель векторов: по готовым векторам свежих новостей раскладывает их по линиям
 * так же жадно, как `app:news:analyze`, при нескольких порогах и печатает, сколько линий вышло, самые
 * крупные (слиплись ли разные события) и разноязычные (собралось ли одно событие на разных языках).
 * Ничего не меняет в базе.
 */
#[AsCommand(name: 'app:news:storyline-tune', description: 'Подобрать порог сюжетов по готовым векторам')]
final class NewsStorylineTuneCommand extends Command
{
    private const GEMINI_THRESHOLDS = '0.80,0.83,0.86,0.89,0.92';
    private const OLLAMA_THRESHOLDS = '0.68,0.71,0.74,0.77,0.80';

    public function __construct(
        private readonly NewsItemRepository $items,
        private readonly NewsEmbedder $embedder,
        private readonly NewsStorylineService $storylines,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'За сколько последних дней новости', '1')
            ->addOption('thresholds', null, InputOption::VALUE_REQUIRED, 'Пороги через запятую; по умолчанию — вокруг порога провайдера');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1G');
        $since = new \DateTimeImmutable('-'.max(1, (int) $input->getOption('days')).' days', new \DateTimeZone('UTC'));
        $items = array_values(array_filter($this->items->findEmbeddedSince($since), static fn (NewsItem $i) => $i->getEmbedding() !== null));
        if ($items === []) {
            $output->writeln('Новостей с векторами нет — сначала make analyze');

            return Command::FAILURE;
        }
        $vectors = array_map(static fn (NewsItem $i) => (array) $i->getEmbedding(), $items);
        $thresholds = (string) ($input->getOption('thresholds')
            ?? ($this->embedder->provider() === NewsEmbedder::PROVIDER_GEMINI ? self::GEMINI_THRESHOLDS : self::OLLAMA_THRESHOLDS));
        $output->writeln(sprintf('модель %s (%s), новостей %d, текущий порог %.2f', $this->embedder->model(), $this->embedder->provider(), \count($items), $this->storylines->threshold()));

        foreach (array_map('floatval', explode(',', $thresholds)) as $threshold) {
            $groups = NewsStorylineService::group($vectors, $threshold);
            $multi = array_values(array_filter($groups, static fn (array $g) => \count($g) > 1));
            usort($multi, static fn (array $a, array $b) => \count($b) <=> \count($a));
            $mixed = array_values(array_filter($multi, fn (array $g) => \count(array_unique(array_map(fn (int $i) => $items[$i]->getSource()->getLanguage(), $g))) > 1));
            $output->writeln(sprintf("\nпорог %.2f: линий %d, из 2+ новостей %d (в них новостей %d), крупнейшая %d, разноязычных %d",
                $threshold, \count($groups), \count($multi), array_sum(array_map('count', $multi)), \count($multi[0] ?? []), \count($mixed)));
            $output->writeln('  крупнейшие:');
            foreach (\array_slice($multi, 0, 3) as $group) {
                $this->printGroup($output, $items, $group);
            }
            $output->writeln('  разноязычные:');
            foreach (\array_slice($mixed, 0, 3) as $group) {
                $this->printGroup($output, $items, $group);
            }
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<NewsItem> $items
     * @param list<int>      $group
     */
    private function printGroup(OutputInterface $output, array $items, array $group): void
    {
        $output->writeln(sprintf('    [%d]', \count($group)));
        foreach (\array_slice($group, 0, 4) as $i) {
            $item = $items[$i];
            $output->writeln(sprintf('      %s: %s', $item->getSource()->getCode(), mb_substr($item->getTranslatedTitle() ?? $item->getTitle(), 0, 110)));
        }
    }
}
