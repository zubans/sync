<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Сводки через Antigravity CLI (`agy`) по подписке Google — тот же механизм, что у провайдера
 * «Antigravity CLI (подписка)» в KB. Вход — один раз интерактивно (`make agy-login`), он хранится
 * в домашней папке agy (том `agy_data`). Каждая сводка — отдельный разговор.
 *
 * Собственные инструменты агента не нужны: агент получает новости в промпте, а настройки
 * домашней папки запрещают ему всё остальное (toolPermission: strict).
 */
final class AgyNewsSummarizer implements NewsSummarizer
{
    /** Дневная сводка — около тысячи новостей, ответ может идти несколько минут. */
    private const TIMEOUT = 600;

    public function __construct(
        #[Autowire('%env(resolve:NEWS_AGY_HOME)%')]
        private readonly string $home,
    ) {
    }

    public function complete(string $prompt, ?string $model): string
    {
        $this->prepareHome();
        $args = ['agy', '--input-format', 'stream-json', '--output-format', 'stream-json'];
        if ($model !== null && $model !== '') {
            array_push($args, '--model', $model);
        }
        $input = json_encode(['event' => 'user', 'message' => ['content' => $prompt]], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
        // Рабочая папка — домашняя agy: агенту не нужны файлы сервера.
        $process = new Process($args, $this->home, ['HOME' => $this->home], $input, self::TIMEOUT);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException(sprintf('agy: нет ответа за %d с', self::TIMEOUT));
        }
        if ($process->getExitCode() === 127) {
            throw new \RuntimeException('agy не установлен в контейнере — пересоберите образ');
        }

        $result = self::result($process->getOutput());
        if ($result === null) {
            throw new \RuntimeException(sprintf('agy завершился с кодом %d: %s', (int) $process->getExitCode(),
                mb_substr(trim($process->getErrorOutput()) ?: trim($process->getOutput()), 0, 500)));
        }
        if (($result['status'] ?? '') !== 'SUCCESS') {
            throw new \RuntimeException('agy: '.mb_substr((string) ($result['error'] ?? $result['status'] ?? 'ошибка'), 0, 500)
                .'. Если не выполнен вход в аккаунт — make agy-login');
        }

        return trim((string) ($result['response'] ?? ''));
    }

    /**
     * Итоговое событие из вывода `--output-format stream-json`: строки JSON, последняя с `event: result`.
     *
     * @return array<string, mixed>|null
     */
    public static function result(string $output): ?array
    {
        $result = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);
            if (\is_array($event) && ($event['event'] ?? '') === 'result' && \is_array($event['result'] ?? null)) {
                $result = $event['result'];
            }
        }

        return $result;
    }

    private function prepareHome(): void
    {
        $file = $this->home.'/.gemini/antigravity-cli/settings.json';
        $settings = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $settings = \is_array($settings) ? $settings : [];
        $wanted = ['toolPermission' => 'strict', 'allowNonWorkspaceAccess' => false];
        if (array_intersect_key($settings, $wanted) != $wanted) {
            if (!is_dir(\dirname($file))) {
                mkdir(\dirname($file), 0o700, true);
            }
            file_put_contents($file, json_encode($wanted + $settings, \JSON_PRETTY_PRINT));
        }
    }
}
