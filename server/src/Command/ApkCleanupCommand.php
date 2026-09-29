<?php

namespace App\Command;

use App\Service\ApkGarbageCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:apk:cleanup', description: 'Удаляет APK, которых нет ни на одном устройстве, и брошенные загрузки')]
final class ApkCleanupCommand
{
    public function __construct(private readonly ApkGarbageCollector $collector)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $result = $this->collector->collect();
        $io->success(\sprintf(
            'Удалено APK: %d (%.1f МБ), брошенных загрузок: %d',
            $result['apks'],
            $result['bytes'] / 1048576,
            $result['uploads'],
        ));

        return Command::SUCCESS;
    }
}
