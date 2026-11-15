<?php

namespace App\Service;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Тонкая обёртка запуска внешних консольных процессов CLI-агентов (тестируемая в PHPUnit без подпроцессов).
 */
class CliProcessExecutor
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env
     *
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool}
     */
    public function run(array $command, string $cwd, array $env, ?string $input = null, int $timeout = 120): array
    {
        $process = new Process($command, $cwd, $env, $input, $timeout);
        try {
            $process->run();

            return [
                'exitCode' => (int) ($process->getExitCode() ?? 1),
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'timedOut' => false,
            ];
        } catch (ProcessTimedOutException) {
            return [
                'exitCode' => 124,
                'stdout' => $process->getOutput(),
                'stderr' => $process->getErrorOutput(),
                'timedOut' => true,
            ];
        }
    }

    public function hasBinary(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_file($binary) && is_executable($binary);
        }
        $check = new Process(['sh', '-c', 'command -v -- "$1" >/dev/null 2>&1', '--', $binary]);
        $check->setTimeout(5);
        $check->run();

        return $check->isSuccessful();
    }
}
