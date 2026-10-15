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
 * Аккаунтов может быть несколько (`AgyAccounts`): упёрся в лимит один — запрос тут же повторяется
 * следующим, а исчерпанный час не трогается. Исчерпаны все — ошибка сразу, без ожидания по 600 с
 * на каждом шаге цикла (14.10.2026 такие ожидания остановили сводки и сюжеты на семь часов).
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

    private readonly AgyAccounts $accounts;

    public function __construct(
        #[Autowire('%env(resolve:NEWS_AGY_HOME)%')]
        string $home,
    ) {
        $this->accounts = new AgyAccounts($home);
    }

    public function complete(string $prompt, ?string $model, ?string $effort = null, ?array $schema = null): string
    {
        $available = $this->accounts->available(time());
        if ($available === []) {
            throw new \RuntimeException(sprintf('agy: лимит исчерпан на всех аккаунтах (%d), следующий освободится в %s UTC. Ещё аккаунт — make agy-login ACCOUNT=2',
                \count($this->accounts->all()), date('H:i', $this->accounts->nextFree())));
        }
        foreach ($available as $i => $home) {
            try {
                return $this->completeWith($home, $prompt, $model, $effort, $schema);
            } catch (\RuntimeException $e) {
                if (!AgyAccounts::isQuota($e->getMessage())) {
                    throw $e;
                }
                $this->accounts->markExhausted($home, time());
                $next = $available[$i + 1] ?? null;
                error_log(sprintf('agy: лимит аккаунта %s — %s', basename($home), $next !== null ? 'переключаюсь на '.basename($next) : 'других свободных аккаунтов нет'));
                if ($next === null) {
                    throw $e;
                }
            }
        }

        throw new \LogicException('недостижимо');
    }

    /** Запрос одним аккаунтом — его домашняя папка [home]. */
    private function completeWith(string $home, string $prompt, ?string $model, ?string $effort, ?array $schema): string
    {
        $this->prepareHome($home);
        $base = ['agy', '--input-format', 'stream-json', '--output-format', 'stream-json'];
        if ($model !== null && $model !== '') {
            // Уровень уже в названии модели («Gemini 3.8 Flash (Medium)»): с --effort agy такой запуск отклоняет.
            array_push($base, '--model', $model);
        } elseif ($effort !== null) {
            array_push($base, '--effort', $effort);
        }
        if ($schema !== null) {
            // agy сам добивается от модели итогового ответа по схеме — без пояснений вокруг JSON.
            array_push($base, '--json-schema', json_encode($schema, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));
        }

        $run = $this->run($home, $base, $prompt);
        if ($model !== null && $model !== '' && $run['error'] !== null && self::unknownModel($run['error'])) {
            // Google переименовывает и убирает модели («Gemini 3.1 Pro (High)» пропала 14.10.2026) — без модели
            // сводка всё же выйдет. В журнал — чтобы модель в NEWS_DIGEST_MODEL_* заменили на актуальную.
            error_log(sprintf('agy: модели «%s» больше нет, взята модель по умолчанию — замените её в .env (список: agy models)', $model));
            $base = array_values(array_filter($base, static fn (string $a) => $a !== '--model' && $a !== $model));
            if ($effort !== null) {
                array_splice($base, 5, 0, ['--effort', $effort]);
            }
            $run = $this->run($home, $base, $prompt);
        }
        if ($run['answer'] === '' && $run['conversation'] !== null && $run['error'] === null) {
            $tools = $run['tools'] !== [] ? ' ('.implode(', ', $run['tools']).')' : '';
            $run = $this->run($home, [...$base, '--conversation', $run['conversation']], sprintf(self::RETRY, $tools));
        }
        if ($run['error'] !== null) {
            throw new \RuntimeException('agy: '.$run['error'].'. Если не выполнен вход в аккаунт — make agy-login');
        }
        if ($run['answer'] === '') {
            throw new \RuntimeException('agy не дал ответа: '.$run['trace']);
        }

        return $run['answer'];
    }

    /** agy не знает модель: «invalid model selection … is not recognized as a known model». */
    public static function unknownModel(string $error): bool
    {
        return str_contains($error, 'invalid model selection') || str_contains($error, 'not recognized as a known model');
    }

    /**
     * Один запуск agy.
     *
     * @param list<string> $args
     *
     * @return array{answer: string, conversation: ?string, tools: list<string>, error: ?string, trace: string}
     */
    private function run(string $home, array $args, string $text): array
    {
        $input = json_encode(['event' => 'user', 'message' => ['content' => $text]], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
        // Рабочая папка — домашняя agy: агенту не нужны файлы сервера.
        $process = new Process($args, $home, ['HOME' => $home], $input, self::TIMEOUT);
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            // agy сам повторяет запрос после ответа о лимите — и может так не дождаться ответа вовсе.
            $partial = $process->getOutput().$process->getErrorOutput();
            throw new \RuntimeException(sprintf('agy: нет ответа за %d с%s', self::TIMEOUT, AgyAccounts::isQuota($partial) ? ' — лимит аккаунта (RESOURCE_EXHAUSTED)' : ''));
        }
        if ($process->getExitCode() === 127) {
            throw new \RuntimeException('agy не установлен в контейнере — пересоберите образ');
        }
        // Сырой вывод последнего запуска — для разбора сбоев: что именно прислал агент.
        @file_put_contents($home.'/last-run.jsonl', $process->getOutput());
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
        // Только по \n: без флага u выражение \R режет и по байту 0x85, а он есть в русских буквах UTF-8
        // («х» — D1 85), и строки JSON рвались посреди текста.
        foreach (explode("\n", $output) as $line) {
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
        // С --json-schema agy кладёт разобранный ответ в structured_output, а в response тогда идут
        // два JSON подряд — ответ и служебный вызов завершения, — и разбирать его нельзя.
        $answer = \is_array($result['structured_output'] ?? null)
            ? json_encode($result['structured_output'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)
            : (trim((string) ($result['response'] ?? '')) ?: trim($streamed));
        $trace = 'события: '.implode(', ', array_map(static fn (string $k, int $n) => $n > 1 ? "{$k}×{$n}" : $k, array_keys($events), $events))
            .($tools !== [] ? '; инструменты: '.implode(', ', array_keys($tools)) : '')
            .($result === null ? '; итогового события нет' : '');

        return ['answer' => $answer, 'conversation' => $conversation, 'tools' => array_keys($tools), 'error' => $error, 'trace' => $trace];
    }

    private function prepareHome(string $home): void
    {
        $file = $home.'/.gemini/antigravity-cli/settings.json';
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
