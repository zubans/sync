<?php

namespace App\Command;

use App\Service\AppReleaseService;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:release:publish', description: 'Публикует сборку Android-клиента и делает её активной (вызывает make release)')]
final class ReleasePublishCommand
{
    public function __construct(private readonly AppReleaseService $releases)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Путь к APK')] string $apk,
        #[Option('versionCode сборки')] int $versionCode = 0,
        #[Option('versionName сборки')] string $versionName = '',
        #[Option('Git-дерево android/, из которого собрано')] string $revision = '',
        #[Option('Только сохранить, активной не делать')] bool $inactive = false,
    ): int {
        if ($versionName === '' || $revision === '') {
            $io->error('Укажите --version-code, --version-name и --revision.');

            return Command::INVALID;
        }
        try {
            $release = $this->releases->publish($apk, $versionCode, $versionName, $revision, !$inactive);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        $io->success(\sprintf('Опубликована версия %s%s.', $release, $release->isActive() ? ', она активна' : ''));

        return Command::SUCCESS;
    }
}
