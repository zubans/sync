<?php

namespace App\Tests\Service;

use App\Service\AgyAccounts;
use PHPUnit\Framework\TestCase;

final class AgyAccountsTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/agy-'.bin2hex(random_bytes(4));
        mkdir($this->home.'/account2/.gemini/antigravity-cli', 0o777, true);
        touch($this->home.'/account2'.AgyAccounts::TOKEN);
        mkdir($this->home.'/account3/.gemini/antigravity-cli', 0o777, true); // agy запускали, но вход не завершён — не аккаунт
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    public function testExhaustedAccountRestsAnHourAndNextIsUsed(): void
    {
        $accounts = new AgyAccounts($this->home);
        self::assertSame([$this->home, $this->home.'/account2'], $accounts->all(), 'папка без входа — не аккаунт');

        $accounts->markExhausted($this->home, 1000);

        self::assertSame([$this->home.'/account2'], (new AgyAccounts($this->home))->available(1000), 'отметка видна другому процессу');
        self::assertSame([$this->home, $this->home.'/account2'], $accounts->available(1000 + AgyAccounts::COOLDOWN), 'через час основной снова первый');

        $accounts->markExhausted($this->home.'/account2', 1100);
        self::assertSame([], $accounts->available(1200));
        self::assertSame(1000 + AgyAccounts::COOLDOWN, $accounts->nextFree());
    }

    public function testExhaustedAccountRestsUntilResetNamedByAgy(): void
    {
        $answer = 'agy: Individual quota reached. Please upgrade your subscription to increase your limits. Resets in 43h17m35s.';
        self::assertSame(43 * 3600 + 17 * 60 + 35, AgyAccounts::resetIn($answer));
        self::assertNull(AgyAccounts::resetIn('RESOURCE_EXHAUSTED (code 429)'));

        $accounts = new AgyAccounts($this->home);
        $accounts->markExhausted($this->home, 1000, AgyAccounts::resetIn($answer));
        self::assertSame([$this->home.'/account2'], $accounts->available(1000 + 2 * AgyAccounts::COOLDOWN), 'через два часа основной ещё отдыхает');
    }

    public function testAuthFailuresAreRecognized(): void
    {
        self::assertTrue(AgyAccounts::isAuthFailure('agy: authentication failed or timed out'));
        self::assertTrue(AgyAccounts::isAuthFailure('UNAUTHENTICATED (code 401): Request had invalid authentication credentials.'));
        self::assertFalse(AgyAccounts::isAuthFailure('Individual quota reached. Resets in 43h17m35s.'));
        self::assertTrue(AgyAccounts::isIneligible('Eligibility check failed: Your current account is not eligible for Antigravity, because it is not currently available in your location.'));
        self::assertFalse(AgyAccounts::isIneligible('Eligibility check failed: Post "https://daily-cloudcode-pa.googleapis.com/v1internal:loadCodeAssist": dial tcp: lookup daily-cloudcode-pa.googleapis.com: server misbehaving'), 'сбой DNS — не отказ в допуске');
    }

    public function testQuotaAnswersAreRecognized(): void
    {
        foreach ([
            'agy: Individual quota reached. Please upgrade your subscription to increase your limits. Resets in 43h17m35s.',
            'RESOURCE_EXHAUSTED (code 429): You have exhausted your capacity on this model.',
            'Quota exceeded for quota metric',
            'agy: нет ответа за 600 с — лимит аккаунта (RESOURCE_EXHAUSTED)',
        ] as $text) {
            self::assertTrue(AgyAccounts::isQuota($text), $text);
        }
        foreach ([
            'UNAVAILABLE (code 503): No capacity available for model gemini-3.8-flash-medium',
            'Your previous response was cut off because it exceeded the output token limit',
            'agy: нет ответа за 600 с',
        ] as $text) {
            self::assertFalse(AgyAccounts::isQuota($text), $text);
        }
    }
}
