<?php

namespace App\Command;

use App\Service\AiAgentCatalog;
use App\Service\AiAgentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Создаёт аккаунт/слот CLI-агента (`agy`, `claude`, `codex`, `gemini-cli`, `kimi`) с изолированным HOME.
 * Используется и напрямую из консоли (`make ai-add`), и через `AiConsoleRunner` из веб-админки.
 */
#[AsCommand(
    name: 'app:ai:agent-add',
    description: 'Добавить слот аккаунта CLI-агента ИИ (agy, claude, codex, gemini-cli, kimi)',
)]
final class AiAgentAddCommand extends Command
{
    public function __construct(private readonly AiAgentManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('agentType', InputArgument::REQUIRED, 'Тип агента: agy|claude|codex|gemini-cli|kimi')
            ->addOption('slot', 's', InputOption::VALUE_REQUIRED, 'Имя слота (main, account2, ...; пусто — следующий по порядку)')
            ->addOption('label', 'l', InputOption::VALUE_REQUIRED, 'Отображаемая заметка / название')
            ->addOption('priority', 'p', InputOption::VALUE_REQUIRED, 'Приоритет (меньше — выше)', '100')
            ->addOption('token', 't', InputOption::VALUE_REQUIRED, 'OAuth setup-token или API-ключ (опционально)')
            ->addOption('disabled', null, InputOption::VALUE_NONE, 'Создать отключённым')
            ->addOption('provider-id', null, InputOption::VALUE_REQUIRED, 'ID существующего провайдера ИИ')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $agentType = (string) $input->getArgument('agentType');
        $slot = $input->getOption('slot');
        $label = $input->getOption('label');
        $token = $input->getOption('token');
        $priority = (int) $input->getOption('priority');
        $enabled = !(bool) $input->getOption('disabled');
        $providerId = $input->getOption('provider-id');
        $asJson = (bool) $input->getOption('json');

        try {
            $account = $this->manager->createAgentAccount(
                agentType: $agentType,
                slot: $slot !== null ? (string) $slot : null,
                label: $label !== null ? (string) $label : null,
                token: $token !== null ? (string) $token : null,
                priority: $priority,
                enabled: $enabled,
                providerId: $providerId !== null && ctype_digit((string) $providerId) ? (int) $providerId : null,
            );
        } catch (\Throwable $e) {
            if ($asJson) {
                $output->writeln((string) json_encode(['ok' => false, 'error' => $e->getMessage()], \JSON_UNESCAPED_UNICODE));
            } else {
                $output->writeln('<error>'.$e->getMessage().'</error>');
            }

            return Command::FAILURE;
        }

        $payload = [
            'ok' => true,
            'id' => $account->getId(),
            'agentType' => $account->getAgentType(),
            'shortSlug' => AiAgentCatalog::get($account->getAgentType())['shortSlug'],
            'slot' => $account->getSlot(),
            'label' => $account->getLabel(),
            'homeDir' => $account->getHomeDir(),
            'enabled' => $account->isEnabled(),
            'status' => $account->getStatus(),
            'email' => $account->getAccountEmail(),
            'loginCommand' => $this->manager->buildConsoleLoginCommand($account),
        ];

        if ($asJson) {
            $output->writeln((string) json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf(
            '<info>Создан слот #%d [%s:%s]</info> «%s» (HOME: %s, статус: %s)',
            $account->getId() ?? 0,
            $payload['shortSlug'],
            $account->getSlot(),
            $account->getLabel(),
            $account->getHomeDir(),
            $account->getStatus(),
        ));
        if (!$this->manager->hasLoginArtifact($account)) {
            $output->writeln(\sprintf('Для интерактивного входа выполните: %s', $payload['loginCommand']));
        }

        return Command::SUCCESS;
    }
}
