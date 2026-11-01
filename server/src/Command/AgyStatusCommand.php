<?php

namespace App\Command;

use App\Service\AgyAccounts;
use App\Service\NewsTranslator;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Состояние работы с agy одним экраном (`make agy-status`): аккаунты и что у них отдыхает, модели шагов,
 * очередь необработанного и последняя сводка. Журнал контейнеров к нему добавляет `make`.
 */
#[AsCommand(name: 'app:agy:status', description: 'Аккаунты agy, их лимиты, модели и очередь новостей')]
final class AgyStatusCommand extends Command
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%env(resolve:NEWS_AGY_HOME)%')]
        private readonly string $home,
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $hourModel = '',
        #[Autowire('%env(NEWS_DIGEST_MODEL_DAY)%')]
        private readonly string $dayModel = '',
        #[Autowire('%env(NEWS_AGY_FALLBACK_MODELS)%')]
        private readonly string $fallbackModels = '',
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = time();
        $accounts = new AgyAccounts($this->home);
        $resting = $accounts->resting($now);

        $output->writeln('<info>Аккаунты agy</info>');
        foreach ($accounts->all() as $home) {
            $name = $home === $this->home ? 'основной' : basename($home);
            $output->writeln(sprintf('  %s — %s', $name, AgyAccounts::email($home) ?? 'вход не выполнен'));
            $rests = 0;
            foreach ($resting as $key => $until) {
                [$keyHome, $model] = array_pad(explode('|', $key, 2), 2, null);
                if ($keyHome !== $home) {
                    continue;
                }
                ++$rests;
                $output->writeln(sprintf('      отдыхает %s до %s UTC (ещё %s)',
                    $model === null ? 'весь аккаунт (вход или регион)' : '«'.($model === 'default' ? 'модель по умолчанию' : $model).'»',
                    gmdate('d.m H:i', $until), self::left($until - $now)));
            }
            if ($rests === 0) {
                $output->writeln('      квота: свободен');
            }
        }

        $output->writeln('');
        $output->writeln('<info>Модели</info>');
        $output->writeln('  часовая сводка, перевод, сюжеты: '.($this->hourModel !== '' ? $this->hourModel : 'по умолчанию'));
        $output->writeln('  суточная сводка: '.($this->dayModel !== '' ? $this->dayModel : 'по умолчанию'));
        $output->writeln('  запасные: '.($this->fallbackModels !== '' ? $this->fallbackModels : 'нет'));

        $output->writeln('');
        $output->writeln('<info>Очередь</info>');
        $since = gmdate('Y-m-d H:i:s', $now - NewsTranslator::HEADLINE_WINDOW_HOURS * 3600);
        $day = gmdate('Y-m-d H:i:s', $now - 86400);
        $threeDays = gmdate('Y-m-d H:i:s', $now - 3 * 86400);
        $rows = [
            'заголовки к переводу (за сутки)' => ["SELECT count(*) FROM news_item i JOIN news_source s ON s.id = i.source_id WHERE s.language <> 'ru' AND s.format <> 'digest' AND i.translated_title IS NULL AND i.headline_translation_attempts < ? AND i.sort_at >= ?", [NewsTranslator::MAX_ATTEMPTS, $since]],
            'статьи к переводу по кнопке' => ['SELECT count(*) FROM news_item WHERE translation_requested_at IS NOT NULL AND translated_content IS NULL', []],
            'новости без вектора (сюжеты)' => ["SELECT count(*) FROM news_item i JOIN news_source s ON s.id = i.source_id WHERE s.format <> 'digest' AND i.embedding IS NULL AND i.first_seen_at >= ?", [$day]],
            'сюжеты без названия' => ['SELECT count(*) FROM news_storyline WHERE title IS NULL AND news_count >= 2 AND last_seen_at >= ?', [$threeDays]],
            'новости без проверки для Мосбиржи (за сутки)' => ["SELECT count(*) FROM news_item i JOIN news_source s ON s.id = i.source_id WHERE s.format <> 'digest' AND i.market_checked_at IS NULL AND i.first_seen_at >= ?", [$day]],
        ];
        foreach ($rows as $label => [$sql, $params]) {
            $output->writeln(sprintf('  %s: %d', $label, (int) $this->db->fetchOne($sql, $params)));
        }

        $digest = $this->db->fetchAssociative("SELECT max(i.sort_at) AS last, s.last_attempt_at AS attempt, s.last_error AS error FROM news_source s LEFT JOIN news_item i ON i.source_id = s.id WHERE s.code = 'digest' GROUP BY s.id, s.last_attempt_at, s.last_error");
        $output->writeln('');
        $output->writeln('<info>Сводки</info>');
        if ($digest === false) {
            $output->writeln('  издания «Сводка» нет');
        } else {
            $output->writeln('  последняя: '.($digest['last'] !== null ? substr((string) $digest['last'], 0, 16).' UTC' : 'ещё не было'));
            $output->writeln('  ошибка последней попытки: '.($digest['error'] !== null && $digest['error'] !== '' ? mb_substr((string) $digest['error'], 0, 200) : 'нет'));
        }

        return Command::SUCCESS;
    }

    private static function left(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);

        return $hours >= 24 ? sprintf('%d д %d ч', intdiv($hours, 24), $hours % 24) : sprintf('%d ч %d мин', $hours, intdiv($seconds % 3600, 60));
    }
}
