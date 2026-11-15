<?php

namespace App\Command;

use App\Service\FeatureManager;
use App\Service\NewsArticleFetcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Полные тексты новостей со страниц изданий. Контейнер `news` запускает команду после каждого сбора:
 * 30 статей за проход при сборе раз в 5 минут — это 360 в час, новостей приходит около сотни.
 */
#[AsCommand(name: 'app:news:articles', description: 'Скачать полные тексты новостей со страниц изданий')]
final class NewsArticlesCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsArticleFetcher $fetcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько статей скачать за проход', '30');
    }

    private ?FeatureManager $features = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setFeatures(FeatureManager $features): void
    {
        $this->features = $features;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->features !== null && !$this->features->isEnabled(FeatureManager::NEWS)) {
            $output->writeln('<comment>Блок «Новости» отключён в админке — загрузка статей пропущена.</comment>');

            return Command::SUCCESS;
        }
        if (!$this->lock()) {
            $output->writeln('Статьи уже скачиваются');

            return Command::SUCCESS;
        }
        $result = $this->fetcher->fetchMissing(max(1, (int) $input->getOption('limit')));
        $output->writeln(sprintf('статьи: скачано %d, не удалось %d', $result['fetched'], $result['failed']));
        $this->release();

        return Command::SUCCESS;
    }
}
