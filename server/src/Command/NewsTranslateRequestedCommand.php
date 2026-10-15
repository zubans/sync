<?php

namespace App\Command;

use App\Service\NewsTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Перевод статей по просьбам читателей — приоритетная очередь. Запускается отдельным циклом каждые
 * несколько секунд (сервис `translate` в compose.yaml), а не в общем пятиминутном цикле `news`, где перед
 * переводом идут сбор, статьи, сюжеты и Мосбиржа. Пишет в журнал, только когда было что делать.
 */
#[AsCommand(name: 'app:news:translate-requested', description: 'Перевести статьи, которые попросили перевести читатели')]
final class NewsTranslateRequestedCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsTranslator $translator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            return Command::SUCCESS;
        }
        $r = $this->translator->runRequested();
        if ($r['articles'] > 0 || $r['failed'] > 0 || $r['error'] !== null) {
            $output->writeln(sprintf('перевод по просьбе: статей %d, не удалось %d%s',
                $r['articles'], $r['failed'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        }
        $this->release();

        return Command::SUCCESS;
    }
}
