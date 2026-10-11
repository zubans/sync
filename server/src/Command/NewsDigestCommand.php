<?php

namespace App\Command;

use App\Entity\NewsDigest;
use App\Service\NewsDigestPeriod;
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
 *
 * С `--preview` пишет сводку за последние N часов прямо сейчас и только печатает её — чтобы проверить
 * модель и промпт, не дожидаясь смены часа и не оставляя в ленте сводок за незаконченный период.
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
        $this
            ->addOption('rebuild', null, InputOption::VALUE_NONE, 'Переписать сводки текущих периодов, даже если они уже есть')
            ->addOption('preview', null, InputOption::VALUE_NONE, 'Написать сводку за последние часы и только показать её, ничего не сохраняя')
            ->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Предпросмотр: за сколько последних часов (1–24; 1 — как часовая сводка, больше — как суточная)', '1')
            ->addOption('model', null, InputOption::VALUE_REQUIRED, 'Предпросмотр: модель agy вместо настроенной, например «Gemini 3.1 Pro (High)»');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ($input->getOption('preview')) {
            return $this->preview($io, (int) $input->getOption('hours'), $input->getOption('model'));
        }
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

    private function preview(SymfonyStyle $io, int $hours, ?string $model): int
    {
        if ($hours < 1 || $hours > 24) {
            $io->error('--hours: от 1 до 24');

            return Command::INVALID;
        }
        $end = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $period = new NewsDigestPeriod($hours === 1 ? NewsDigest::PERIOD_HOUR : NewsDigest::PERIOD_DAY, $end->modify("-$hours hours"), $end);
        $started = microtime(true);
        try {
            $result = $this->digests->preview($period, $model);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }
        if ($result['digest'] === null) {
            $io->warning("За последние $hours ч новостей нет");

            return Command::SUCCESS;
        }

        $io->title($result['digest']['headline']);
        $io->writeln(sprintf('Новостей: %d, сюжетов: %d, %.0f с', $result['news'], \count($result['digest']['stories']), microtime(true) - $started));
        foreach ($result['digest']['stories'] as $story) {
            $io->section($story['title']);
            $io->writeln($story['text']);
            $io->writeln('<comment>новости: '.implode(', ', $story['items']).'</comment>');
        }

        return Command::SUCCESS;
    }
}
