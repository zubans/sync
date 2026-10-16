<?php

namespace App\Command;

use App\Service\NewsStorylineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Аналитика новостей: векторы, сюжетные линии и их названия. Контейнер `news` запускает команду после
 * сбора и до сводок — тогда сводка уже знает, к какой истории относится новость.
 */
#[AsCommand(name: 'app:news:analyze', description: 'Векторы новостей и сюжетные линии')]
final class NewsAnalyzeCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsStorylineService $storylines)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Стереть векторы и сюжеты и посчитать заново (сменилась модель векторов)')
            ->addOption('reassign', null, InputOption::VALUE_NONE, 'Стереть сюжеты и собрать заново по готовым векторам (сменился порог)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Анализ уже идёт');

            return Command::SUCCESS;
        }
        if ($input->getOption('rebuild') || $input->getOption('reassign')) {
            $this->storylines->reset((bool) $input->getOption('rebuild'));
            $output->writeln($input->getOption('rebuild') ? 'векторы и сюжеты стёрты — считаются заново' : 'сюжеты стёрты — собираются заново по готовым векторам');
        }
        $r = $this->storylines->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $output->writeln(sprintf('сюжеты: векторов %d, привязано %d, новых линий %d, названо %d, склеено %d%s',
            $r['embedded'], $r['assigned'], $r['created'], $r['named'], $r['merged'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        $this->release();

        return $r['error'] !== null && $r['embedded'] + $r['assigned'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
