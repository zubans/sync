<?php

namespace App\Command;

use App\Service\NewsTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Перевод новостей изданий на других языках на русский. Контейнер `news` запускает команду после
 * скачивания статей и до анализа: свежие заголовки переводятся в том же проходе, что и собраны.
 */
#[AsCommand(name: 'app:news:translate', description: 'Перевести на русский новости изданий на других языках')]
final class NewsTranslateCommand extends Command
{
    use LockableTrait;

    public function __construct(private readonly NewsTranslator $translator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Сколько заголовков перевести за проход', (string) NewsTranslator::HEADLINE_BATCH)
            ->addOption('articles', null, InputOption::VALUE_REQUIRED, 'Сколько полных текстов перевести за проход без просьбы читателя (по умолчанию — ни одного: статьи переводятся по кнопке)', (string) NewsTranslator::AUTO_ARTICLES);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            $output->writeln('Перевод уже идёт');

            return Command::SUCCESS;
        }
        $r = $this->translator->run(max(0, (int) $input->getOption('limit')), max(0, (int) $input->getOption('articles')));
        $output->writeln(sprintf('перевод: заголовков %d, статей %d, не удалось %d%s',
            $r['headlines'], $r['articles'], $r['failed'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        $this->release();

        return Command::SUCCESS;
    }
}
