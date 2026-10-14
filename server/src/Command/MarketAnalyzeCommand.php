<?php

namespace App\Command;

use App\Service\MarketAnalysisService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Анализ инструментов из виджета Мосбиржи: что модель советует — докупить, держать, продать.
 * Контейнер `news` запускает команду после последствий новостей: модель видит свежие триггеры.
 */
#[AsCommand(name: 'app:market:analyze', description: 'Анализ инструментов виджета Мосбиржи: новости, индикаторы, покупки → совет')]
final class MarketAnalyzeCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly MarketAnalysisService $analysis)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Инструментов за проход', (string) MarketAnalysisService::PER_RUN);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Анализ инструментов уже идёт');

            return Command::SUCCESS;
        }
        $r = $this->analysis->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), max(1, (int) $input->getOption('limit')));
        $output->writeln(sprintf('анализ инструментов: готово %d, не удалось %d%s', $r['analyzed'], $r['failed'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        $this->release();

        return $r['failed'] > 0 && $r['analyzed'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
