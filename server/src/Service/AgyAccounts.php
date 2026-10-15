<?php

namespace App\Service;

/**
 * Аккаунты Google для agy: основной — домашняя папка agy, дополнительные — её подпапки `account2`,
 * `account3`… (вход: `make agy-login ACCOUNT=2`). Аккаунт, упёршийся в лимит, отдыхает до сброса,
 * который называет agy («Resets in 43h17m35s»), а если срока нет — час: отметка
 * лежит в файле, поэтому её видят все шаги цикла `news` (каждый — отдельный процесс) и не ждут
 * исчерпанный аккаунт заново.
 */
final class AgyAccounts
{
    /** Сколько отдыхает аккаунт после ответа о лимите, если agy не назвал срок сброса. */
    public const COOLDOWN = 3600;
    /** Сколько пропускается аккаунт с ошибкой входа: перелогинят — через 10 минут он снова в деле. */
    public const AUTH_COOLDOWN = 600;
    /** Файл, где agy хранит вход в аккаунт: папка `.gemini` появляется раньше — при первом запуске agy. */
    public const TOKEN = '/.gemini/antigravity-cli/antigravity-oauth-token';

    public function __construct(private readonly string $home)
    {
    }

    /** @return list<string> домашние папки аккаунтов: основной первым, дополнительные — если в них выполнен вход */
    public function all(): array
    {
        $extra = array_filter(glob($this->home.'/account[0-9]*', \GLOB_ONLYDIR) ?: [], static fn (string $dir) => is_file($dir.self::TOKEN));
        natsort($extra);

        return [$this->home, ...array_values($extra)];
    }

    /** @return list<string> аккаунты, которые сейчас не отдыхают после лимита, в порядке очереди */
    public function available(int $now): array
    {
        $until = $this->state();

        return array_values(array_filter($this->all(), static fn (string $home) => ($until[$home] ?? 0) <= $now));
    }

    /** Когда освободится первый аккаунт — для текста ошибки, если исчерпаны все. */
    public function nextFree(): int
    {
        $state = $this->state();

        return min(array_map(static fn (string $home) => $state[$home] ?? 0, $this->all()));
    }

    /** [resetIn] — через сколько секунд agy обещал сбросить лимит; не сказал — отдых [COOLDOWN]. */
    public function markExhausted(string $home, int $now, ?int $resetIn = null): void
    {
        $state = array_filter($this->state(), static fn (int $until) => $until > $now);
        $state[$home] = $now + ($resetIn ?? self::COOLDOWN);
        @file_put_contents($this->file(), json_encode($state, \JSON_PRETTY_PRINT), \LOCK_EX);
    }

    /**
     * Ответ agy о лимите: «RESOURCE_EXHAUSTED», «quota», «code 429», «rate limit». Сам agy такие ответы
     * повторяет с паузами — поэтому лимит виден и в выводе запуска, не дождавшегося ответа.
     */
    public static function isQuota(string $text): bool
    {
        return (bool) preg_match('/RESOURCE_EXHAUSTED|quota|code 429|\b429 Too Many|rate.?limit|usage limit|exhausted your/i', $text);
    }

    /** Ошибка входа: «authentication failed or timed out», «UNAUTHENTICATED (code 401)». */
    public static function isAuthFailure(string $text): bool
    {
        return (bool) preg_match('/authentication failed|UNAUTHENTICATED|code 401/i', $text);
    }

    /** «Individual quota reached… Resets in 43h17m35s.» → 155855 секунд; срока в ответе нет — null. */
    public static function resetIn(string $text): ?int
    {
        if (!preg_match('/Resets in ((?:\d+\s*[dhms]\s*)+)/i', $text, $m)) {
            return null;
        }
        preg_match_all('/(\d+)\s*([dhms])/i', $m[1], $parts, \PREG_SET_ORDER);
        $unit = ['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];

        return array_sum(array_map(static fn (array $p) => (int) $p[1] * $unit[strtolower($p[2])], $parts));
    }

    /** @return array<string, int> */
    private function state(): array
    {
        $data = is_file($this->file()) ? json_decode((string) file_get_contents($this->file()), true) : null;

        return \is_array($data) ? array_map('intval', $data) : [];
    }

    private function file(): string
    {
        return $this->home.'/quota.json';
    }
}
