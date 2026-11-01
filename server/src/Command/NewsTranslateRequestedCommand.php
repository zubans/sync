<?php

namespace App\Command;

use App\Service\NewsTranslator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Перевод статей по просьбам читателей — приоритетная очередь. Запускается отдельным циклом каждые
 * несколько секунд (сервис `translate` в compose.yaml), а не в общем пятиминутном цикле `news`, где перед
 * переводом идут сбор, статьи, сюжеты и Мосбиржа. Пишет в журнал, только когда было что делать.
 */
#[AsCommand(name: 'app:news:translate-requested', description: 'Перевести статьи, которые попросили перевести читатели')]
final class NewsTranslateRequestedCommand extends Command
{
    use LockableTrait;

    private const REPEAT_ERROR_AFTER = 300;

    public function __construct(
        private readonly NewsTranslator $translator,
        #[Autowire('%kernel.project_dir%/var/translate-requested.last-error')]
        private readonly string $lastErrorFile,
    ) {
        parent::__construct();
    }

    /** Та же ошибка, что в прошлый раз, и прошло меньше [REPEAT_ERROR_AFTER] — не повторять в журнале. */
    private function shouldReport(string $error): bool
    {
        $last = @json_decode((string) @file_get_contents($this->lastErrorFile), true);
        // Время в тексте ошибки («освободится в 17.10 10:32») не меняется — сравниваем текст целиком.
        if (\is_array($last) && ($last['error'] ?? null) === $error && time() - (int) ($last['at'] ?? 0) < self::REPEAT_ERROR_AFTER) {
            return false;
        }
        @file_put_contents($this->lastErrorFile, json_encode(['error' => $error, 'at' => time()]));

        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->lock()) {
            return Command::SUCCESS;
        }
        $r = $this->translator->runRequested();
        // Пока все аккаунты agy отдыхают, одна и та же ошибка шла бы в журнал каждые 5 секунд — пишем её раз в 5 минут.
        if ($r['articles'] === 0 && $r['failed'] === 0 && $r['error'] !== null && !$this->shouldReport($r['error'])) {
            $this->release();

            return Command::SUCCESS;
        }
        if ($r['articles'] > 0 || $r['failed'] > 0 || $r['error'] !== null) {
            $output->writeln(sprintf('перевод по просьбе: статей %d, не удалось %d%s',
                $r['articles'], $r['failed'], $r['error'] !== null ? '; ошибка: '.$r['error'] : ''));
        }
        $this->release();

        return Command::SUCCESS;
    }
}
