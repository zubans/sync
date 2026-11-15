<?php

namespace App\Command;

use App\Repository\AiAgentAccountRepository;
use App\Service\AiAgentManager;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Включает или выключает отдельный аккаунт CLI-агента ИИ из консоли или админки.
 */
#[AsCommand(
    name: 'app:ai:agent-toggle',
    description: 'Включить или выключить отдельный аккаунт CLI-агента ИИ',
)]
final class AiAgentToggleCommand extends Command
{
    public function __construct(
        private readonly AiAgentAccountRepository $accounts,
        private readonly AiAgentManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('identifier', InputArgument::REQUIRED, 'ID записи или <type>:<slot>')
            ->addOption('enable', null, InputOption::VALUE_NONE, 'Включить аккаунт')
            ->addOption('disable', null, InputOption::VALUE_NONE, 'Выключить аккаунт')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = (string) $input->getArgument('identifier');
        $enable = (bool) $input->getOption('enable');
        $disable = (bool) $input->getOption('disable');
        $asJson = (bool) $input->getOption('json');

        $account = $this->accounts->findByIdentifier($identifier);
        if ($account === null) {
            $msg = \sprintf('Аккаунт «%s» не найден.', $identifier);
            $output->writeln($asJson
                ? (string) json_encode(['ok' => false, 'error' => $msg], \JSON_UNESCAPED_UNICODE)
                : '<error>'.$msg.'</error>');

            return Command::FAILURE;
        }

        $targetState = match (true) {
            $enable && !$disable => true,
            $disable && !$enable => false,
            default => !$account->isEnabled(),
        };

        $this->manager->toggleAccount($account, $targetState);

        if ($asJson) {
            $output->writeln((string) json_encode([
                'ok' => true,
                'id' => $account->getId(),
                'enabled' => $account->isEnabled(),
            ], \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf(
            '<info>Аккаунт #%d [%s:%s]</info>: %s',
            $account->getId() ?? 0,
            $account->getAgentType(),
            $account->getSlot(),
            $account->isEnabled() ? 'включён' : 'выключен',
        ));

        return Command::SUCCESS;
    }
}
