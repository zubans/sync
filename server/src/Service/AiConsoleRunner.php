<?php

namespace App\Service;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Вызывает консольные команды `app:ai:*` из веб-админки без дублирования логики
 * добавления/удаления/проверки агентов ИИ.
 */
final class AiConsoleRunner
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array{exitCode: int, output: string, data: ?array<string, mixed>}
     */
    public function run(string $commandName, array $arguments = []): array
    {
        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput(['command' => $commandName] + $arguments);
        $input->setInteractive(false);
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);

        // Symfony Application::configureIO() читает SHELL_VERBOSITY (-1 в phpunit/quiet-режиме),
        // из-за чего BufferedOutput замолкает и JSON-ответ команды не попадает в буфер.
        $prevEnv = $_ENV['SHELL_VERBOSITY'] ?? null;
        $prevServer = $_SERVER['SHELL_VERBOSITY'] ?? null;
        $prevPutenv = getenv('SHELL_VERBOSITY');
        $_ENV['SHELL_VERBOSITY'] = '0';
        $_SERVER['SHELL_VERBOSITY'] = '0';
        if (\function_exists('putenv')) {
            @putenv('SHELL_VERBOSITY=0');
        }

        try {
            $exitCode = $application->run($input, $output);
        } finally {
            if ($prevEnv === null) {
                unset($_ENV['SHELL_VERBOSITY']);
            } else {
                $_ENV['SHELL_VERBOSITY'] = $prevEnv;
            }
            if ($prevServer === null) {
                unset($_SERVER['SHELL_VERBOSITY']);
            } else {
                $_SERVER['SHELL_VERBOSITY'] = $prevServer;
            }
            if (\function_exists('putenv')) {
                @putenv($prevPutenv === false ? 'SHELL_VERBOSITY' : 'SHELL_VERBOSITY='.$prevPutenv);
            }
        }

        $text = trim($output->fetch());

        $decoded = null;
        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace >= $firstBrace) {
            $jsonCandidate = substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
            $parsed = json_decode($jsonCandidate, true);
            if (\is_array($parsed)) {
                $decoded = $parsed;
            }
        }

        return [
            'exitCode' => $exitCode,
            'output' => $text,
            'data' => $decoded,
        ];
    }
}
