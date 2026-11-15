<?php

namespace App\Command;

use App\Entity\AiAgentAccount;
use App\Repository\AiAgentAccountRepository;
use App\Service\AiAgentCatalog;
use App\Service\AiAgentManager;
use App\Service\SecretBox;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Интерактивный консольный вход или сохранение setup-token в изолированном HOME аккаунта CLI-агента
 * (аналог `make agy-login`, `make codex-login`, `make kimi-login`, `make claude-login` из КБ).
 */
#[AsCommand(
    name: 'app:ai:agent-login',
    description: 'Выполнить вход в аккаунт CLI-агента (agy, claude, codex, gemini-cli, kimi)',
)]
final class AiAgentLoginCommand extends Command
{
    public function __construct(
        private readonly AiAgentAccountRepository $accounts,
        private readonly AiAgentManager $manager,
        private readonly SecretBox $secretBox,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agentType', InputArgument::REQUIRED, 'Тип агента: agy|claude|codex|gemini-cli|kimi')
            ->addArgument('slot', InputArgument::OPTIONAL, 'Имя/номер слота (main, account2, ...)', 'main')
            ->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Название слота при автосоздании')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'Сохранить setup-token / API-ключ без интерактивного запуска')
            ->addOption('logout', null, InputOption::VALUE_NONE, 'Удалить сохранённый токен входа перед повторной авторизацией');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $rawType = (string) $input->getArgument('agentType');
        $rawSlot = (string) $input->getArgument('slot');
        $token = $input->getOption('token');
        $logout = (bool) $input->getOption('logout');

        try {
            $normalized = AiAgentCatalog::normalizeType($rawType);
            $meta = AiAgentCatalog::get($normalized);
            if (!$meta['isCli']) {
                throw new \InvalidArgumentException(\sprintf('«%s» является HTTP-провайдером; настройте ключ в админке.', $rawType));
            }
        } catch (\Throwable $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return Command::FAILURE;
        }

        $slot = $rawSlot === '1' ? 'main' : (ctype_digit($rawSlot) ? 'account'.$rawSlot : mb_strtolower(trim($rawSlot)));
        $account = $this->accounts->findOneByTypeAndSlot($normalized, $slot);
        if ($account === null) {
            if ($logout) {
                $output->writeln(\sprintf('<comment>Аккаунт [%s:%s] не найден — сбрасывать нечего.</comment>', $meta['shortSlug'], $slot));

                return Command::SUCCESS;
            }
            $label = $input->getOption('label');
            $account = $this->manager->createAgentAccount(
                agentType: $normalized,
                slot: $slot,
                label: $label !== null ? (string) $label : null,
            );
        }

        if ($logout) {
            $this->manager->logoutAccount($account);
            $output->writeln(\sprintf('<info>Учётные данные [%s:%s] очищены.</info>', $meta['shortSlug'], $account->getSlot()));

            return Command::SUCCESS;
        }

        if ($token !== null && trim((string) $token) !== '') {
            $this->manager->prepareIsolatedHome($account);
            $account->setAuthTokenEncrypted($this->secretBox->encrypt(trim((string) $token)));
            $account->setStatus(AiAgentAccount::STATUS_READY);
            $this->em->flush();
            $output->writeln(\sprintf('<info>Токен сохранён для [%s:%s].</info>', $meta['shortSlug'], $account->getSlot()));

            return Command::SUCCESS;
        }

        $this->manager->prepareIsolatedHome($account);
        $env = $this->manager->buildAgentProcessEnv($account);

        $output->writeln(\sprintf(
            '<info>Запуск консольного входа для %s (слот «%s», HOME=%s)…</info>',
            $meta['shortSlug'],
            $account->getSlot(),
            $account->getHomeDir(),
        ));

        $process = new Process($meta['loginArgs'], $account->getHomeDir(), $env, null, 600);
        if (Process::isTtySupported()) {
            $process->setTty(true);
        }
        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        $this->manager->refreshAccountStatusFromDisk($account);
        $this->em->flush();

        if (!$process->isSuccessful()) {
            $output->writeln(\sprintf('<error>Процесс входа завершился с кодом %d.</error>', (int) $process->getExitCode()));

            return Command::FAILURE;
        }

        $output->writeln(\sprintf(
            '<info>Готово:</info> [%s:%s] статус = %s%s',
            $meta['shortSlug'],
            $account->getSlot(),
            $account->getStatus(),
            $account->getAccountEmail() !== null ? ' ('.$account->getAccountEmail().')' : '',
        ));

        return Command::SUCCESS;
    }
}
