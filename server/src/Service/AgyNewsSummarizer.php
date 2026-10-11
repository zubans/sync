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
 * Собственные инструменты агента не нужны: агент получает новости в промпте, а настройки домашней
 * папки запрещают ему всё остальное (toolPermission: strict). Gemini всё равно тянется к ним
 * и заканчивает ход без ответа — тогда ход один раз повторяется в том же разговоре, как в KB.
 */
final class AgyNewsSummarizer implements NewsSummarizer
{
    /** Дневная сводка — около тысячи новостей, ответ может идти несколько минут. */
    private const TIMEOUT = 600;
    private const RETRY = 'Собственные инструменты agy%s отклонены и не работают. Дай ответ текстом по уже переданным новостям — только JSON указанного вида.';

    public function __construct(
        #[Autowire('%env(resolve:NEWS_AGY_HOME)%')]
        private readonly string $home,
    ) {
    }

    public function complete(string $prompt, ?string $model): string
    {
        $this->prepareHome();
        $base = ['agy', '--input-format', 'stream-json', '--output-format', 'stream-json'];
        if ($model !== null && $model !== '') {
            array_push($base, '--model', $model);
        }

        $run = $this->run($base, $prompt);
        if ($run['answer'] === '' && $run['conversation'] !== null && $run['error'] === null) {
            $tools = $run['tools'] !== [] ? ' ('.implode(', ', $run['tools']).')' : '';
            $run = $this->run([...$base, '--conversation', $run['conversation']], sprintf(self::RETRY, $tools));
        }
        if ($run['error'] !== null) {
            throw new \RuntimeException('agy: '.$run['error'].'. Если не выполнен вход в аккаунт — make agy-login');
        }
        if ($run['answer'] === '') {
            throw new \RuntimeException('agy не дал ответа: '.$run['trace']);
        }

        return $run['answer'];
    }

    /**
     * Один запуск agy.
     *
     * @param list<string> $args
     *
     * @return array{answer: string, conversation: ?string, tools: list<string>, error: ?string, trace: string}
     */
    private function run(array $args, string $text): array
    {
        $input = json_encode(['event' => 'user', 'message' => ['content' => $text]], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
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
        $run = self::parse($process->getOutput());
        if ($run['answer'] === '' && $run['error'] === null) {
            $stderr = trim($process->getErrorOutput());
            $run['trace'] = sprintf('код %d; %s%s', (int) $process->getExitCode(), $run['trace'], $stderr !== '' ? '; stderr: '.mb_substr($stderr, 0, 300) : '');
        }

        return $run;
    }

    /**
     * Разбор вывода `--output-format stream-json`: строки JSON. Ответ — `response` итогового события
     * `result`, а если его нет — текст, напечатанный по ходу (`agent_response`). Для ошибки — короткий след:
     * какие события пришли и к каким инструментам агент тянулся.
     *
     * @return array{answer: string, conversation: ?string, tools: list<string>, error: ?string, trace: string}
     */
    public static function parse(string $output): array
    {
        $result = null;
        $streamed = '';
        $conversation = null;
        $tools = [];
        $events = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }
            $type = (string) ($event['event'] ?? '?');
            $step = \is_array($event['step_update'] ?? null) ? $event['step_update'] : [];
            $label = $type.(isset($step['step_type']) ? ':'.$step['step_type'] : '');
            $events[$label] = ($events[$label] ?? 0) + 1;
            $conversation = ($event['conversation_id'] ?? null) ?: ($event['result']['conversation_id'] ?? null) ?: $conversation;
            if ($type === 'result' && \is_array($event['result'] ?? null)) {
                $result = $event['result'];
            } elseif (($step['step_type'] ?? '') === 'agent_response' && isset($step['text_delta'])) {
                $streamed .= (string) $step['text_delta'];
            } elseif (($step['step_type'] ?? '') === 'tool' && isset($step['tool_name'])) {
                $tools[(string) $step['tool_name']] = true;
            }
        }

        $error = null;
        if ($result !== null && ($result['status'] ?? '') !== 'SUCCESS') {
            $error = mb_substr((string) ($result['error'] ?? $result['status'] ?? 'ошибка'), 0, 500);
        }
        $answer = trim((string) ($result['response'] ?? '')) ?: trim($streamed);
        $trace = 'события: '.implode(', ', array_map(static fn (string $k, int $n) => $n > 1 ? "$k×$n" : $k, array_keys($events), $events))
            .($tools !== [] ? '; инструменты: '.implode(', ', array_keys($tools)) : '')
            .($result === null ? '; итогового события нет' : '');

        return ['answer' => $answer, 'conversation' => $conversation, 'tools' => array_keys($tools), 'error' => $error, 'trace' => $trace];
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
