<?php

namespace App\Service;

/**
 * Аккаунты Google для agy: основной — домашняя папка agy, дополнительные — её подпапки `account2`,
 * `account3`… (вход: `make agy-login ACCOUNT=2`). Аккаунт, упёршийся в лимит, отдыхает до сброса,
 * который называет agy («Resets in 43h17m35s»), а если срока нет — час: отметка
 * лежит в файле, поэтому её видят все шаги цикла `news` (каждый — отдельный процесс) и не ждут
 * исчерпанный аккаунт заново.
 *
 * Квота у моделей своя (проверено 15.10.2026: Gemini исчерпана, Claude и GPT-OSS того же аккаунта отвечают),
 * поэтому лимит отмечается парой «аккаунт + модель» ([markModelExhausted]); ошибка входа и отказ по региону —
 * аккаунтом целиком ([markExhausted]).
 */
final class AgyAccounts
{
    /** Сколько отдыхает аккаунт после ответа о лимите, если agy не назвал срок сброса. */
    public const COOLDOWN = 3600;
    /** Сколько пропускается аккаунт с ошибкой входа: перелогинят — через 10 минут он снова в деле. */
    public const AUTH_COOLDOWN = 600;
    /** Сколько пропускается аккаунт, который Google не допускает к Antigravity в его регионе. */
    public const INELIGIBLE_COOLDOWN = 86400;
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

    /** Модель [model] (null — модель agy по умолчанию) аккаунта [home] не отдыхает после лимита. */
    public function isModelFree(string $home, ?string $model, int $now): bool
    {
        return ($this->state()[self::modelKey($home, $model)] ?? 0) <= $now;
    }

    /** У модели аккаунта кончилась квота: остальные модели этого аккаунта работают. */
    public function markModelExhausted(string $home, ?string $model, int $now, ?int $resetIn = null): void
    {
        $this->write(self::modelKey($home, $model), $now + ($resetIn ?? self::COOLDOWN), $now);
    }

    /**
     * Когда освободится хоть одна пара «аккаунт + модель» из [models] — для текста ошибки, если заняты все.
     *
     * @param list<?string> $models
     */
    public function nextFreeFor(array $models): int
    {
        $state = $this->state();
        $times = [];
        foreach ($this->all() as $home) {
            $model = min(array_map(static fn (?string $m) => $state[self::modelKey($home, $m)] ?? 0, $models));
            $times[] = max($state[$home] ?? 0, $model);
        }

        return min($times);
    }

    private static function modelKey(string $home, ?string $model): string
    {
        return $home.'|'.($model !== null && $model !== '' ? $model : 'default');
    }

    /** [resetIn] — через сколько секунд agy обещал сбросить лимит; не сказал — отдых [COOLDOWN]. */
    public function markExhausted(string $home, int $now, ?int $resetIn = null): void
    {
        $this->write($home, $now + ($resetIn ?? self::COOLDOWN), $now);
    }

    private function write(string $key, int $until, int $now): void
    {
        $state = array_filter($this->state(), static fn (int $u) => $u > $now);
        $state[$key] = $until;
        @file_put_contents($this->file(), json_encode($state, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
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

    /** «Eligibility check failed: Your current account is not eligible … not currently available in your location». */
    public static function isIneligible(string $text): bool
    {
        return str_contains($text, 'not eligible for Antigravity');
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
        // Новое имя с 15.10.2026: в старом quota.json лимит Gemini отмечен аккаунтом целиком —
        // с ним запасные модели исчерпанных аккаунтов не пробовались бы.
        return $this->home.'/agy-state.json';
    }
}
