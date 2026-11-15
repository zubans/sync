<?php

namespace App\Command;

use App\Service\FeatureManager;
use App\Service\NewsCollector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Один проход сбора новостей. Контейнер `news` запускает его раз в 5 минут; блокировка
 * не даёт двум проходам идти одновременно, если какое-то издание отвечает очень долго.
 */
#[AsCommand(name: 'app:news:collect', description: 'Собрать новости включённых изданий')]
final class NewsCollectCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsCollector $collector)
    {
        parent::__construct();
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
            $output->writeln('<comment>Блок «Новости» отключён в админке — сбор пропущен.</comment>');

            return Command::SUCCESS;
        }
        $io = new SymfonyStyle($input, $output);
        if (!$this->lock()) {
            $io->warning('Сбор уже идёт');

            return Command::SUCCESS;
        }

        $failed = 0;
        foreach ($this->collector->collectAll() as $code => $result) {
            if ($result['error'] !== null) {
                ++$failed;
                $io->writeln("$code: ошибка — {$result['error']}");
            } else {
                $io->writeln("$code: новых {$result['new']}, обновлено {$result['updated']}");
            }
        }
        $this->release();

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
