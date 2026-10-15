<?php

namespace App\Service;

/**
 * Аккаунты Google для agy: основной — домашняя папка agy, дополнительные — её подпапки `account2`,
 * `account3`… (вход: `make agy-login ACCOUNT=2`). Аккаунт, упёршийся в лимит, отдыхает час: отметка
 * лежит в файле, поэтому её видят все шаги цикла `news` (каждый — отдельный процесс) и не ждут
 * исчерпанный аккаунт заново.
 */
final class AgyAccounts
{
    /** Сколько отдыхает аккаунт после ответа о лимите: квота agy восстанавливается в течение часа-двух. */
    public const COOLDOWN = 3600;

    public function __construct(private readonly string $home)
    {
    }

    /** @return list<string> домашние папки аккаунтов: основной первым, дополнительные — если в них выполнен вход */
    public function all(): array
    {
        $extra = array_filter(glob($this->home.'/account[0-9]*', \GLOB_ONLYDIR) ?: [], static fn (string $dir) => is_dir($dir.'/.gemini'));
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

    public function markExhausted(string $home, int $now): void
    {
        $state = array_filter($this->state(), static fn (int $until) => $until > $now);
        $state[$home] = $now + self::COOLDOWN;
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
