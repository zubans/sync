<?php

namespace App\Command;

use App\Service\NewsStorylineService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
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

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Анализ уже идёт');

            return Command::SUCCESS;
        }
        $r = $this->storylines->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $output->writeln(sprintf('сюжеты: векторов %d, привязано %d, новых линий %d, названо %d, склеено %d%s',
            $r['embedded'], $r['assigned'], $r['created'], $r['named'], $r['merged'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        $this->release();

        return $r['error'] !== null && $r['embedded'] + $r['assigned'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
