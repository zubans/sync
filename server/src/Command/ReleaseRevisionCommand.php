<?php

namespace App\Command;

use App\Repository\AppReleaseRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Печатает git-дерево android/ активной сборки (пусто, если сборок нет). make release сравнивает его
 * с текущим и собирает APK, только если клиент изменился.
 */
#[AsCommand(name: 'app:release:revision', description: 'Git-дерево android/ активной сборки клиента')]
final class ReleaseRevisionCommand
{
    public function __construct(private readonly AppReleaseRepository $releases)
    {
    }

    public function __invoke(OutputInterface $output): int
    {
        $output->writeln($this->releases->findActive()?->getSourceRevision() ?? '');

        return Command::SUCCESS;
    }
}
