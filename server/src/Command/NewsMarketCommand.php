<?php

namespace App\Command;

use App\Service\NewsMarketService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Последствия новостей для Мосбиржи: связь новостей с инструментами (модель) и замер цены после них (ISS).
 * Контейнер `news` запускает команду после перевода — модель видит заголовки иностранных новостей по-русски.
 */
#[AsCommand(name: 'app:news:market', description: 'Последствия новостей для инструментов Мосбиржи')]
final class NewsMarketCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsMarketService $market)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Разбор последствий уже идёт');

            return Command::SUCCESS;
        }
        $r = $this->market->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $output->writeln(sprintf('мосбиржа: проверено новостей %d, последствий %d, замеров %d, окончательных %d%s',
            $r['checked'], $r['linked'], $r['measured'], $r['settled'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        $this->release();

        return $r['error'] !== null && $r['checked'] + $r['measured'] === 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
