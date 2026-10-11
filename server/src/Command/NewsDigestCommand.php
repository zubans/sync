<?php

namespace App\Command;

use App\Service\NewsDigestService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Сводки новостей за наступившие периоды. Контейнер `news` запускает команду после каждого сбора;
 * уже написанная сводка не пишется заново, поэтому частый запуск ничего не стоит.
 */
#[AsCommand(name: 'app:news:digest', description: 'Написать сводки новостей за прошедший час и за сутки')]
final class NewsDigestCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsDigestService $digests)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('rebuild', null, InputOption::VALUE_NONE, 'Переписать сводки текущих периодов, даже если они уже есть');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->lock()) {
            $io->warning('Сводка уже пишется');

            return Command::SUCCESS;
        }

        $failed = false;
        foreach ($this->digests->run(new \DateTimeImmutable(), (bool) $input->getOption('rebuild')) as $key => $result) {
            $failed = $failed || str_starts_with($result, 'ошибка');
            $io->writeln("$key: $result");
        }
        $this->release();

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
