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
 * Удаляет слот аккаунта CLI-агента и (по умолчанию) его локальные файлы сессии в var/.
 */
#[AsCommand(
    name: 'app:ai:agent-remove',
    description: 'Удалить аккаунт CLI-агента по ID или <type>:<slot>',
)]
final class AiAgentRemoveCommand extends Command
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
            ->addArgument('identifier', InputArgument::REQUIRED, 'ID записи или пара <type>:<slot> (например 2 или antigravity_cli:account2)')
            ->addOption('keep-files', null, InputOption::VALUE_NONE, 'Не удалять локальную папку HOME с файлами входа')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = (string) $input->getArgument('identifier');
        $keepFiles = (bool) $input->getOption('keep-files');
        $asJson = (bool) $input->getOption('json');

        $account = $this->accounts->findByIdentifier($identifier);
        if ($account === null) {
            $msg = \sprintf('Аккаунт CLI-агента «%s» не найден.', $identifier);
            $output->writeln($asJson
                ? (string) json_encode(['ok' => false, 'error' => $msg], \JSON_UNESCAPED_UNICODE)
                : '<error>'.$msg.'</error>');

            return Command::FAILURE;
        }

        $id = $account->getId();
        $slot = $account->getSlot();
        $type = $account->getAgentType();

        $this->manager->deleteAgentAccount($account, !$keepFiles);

        if ($asJson) {
            $output->writeln((string) json_encode([
                'ok' => true,
                'deletedId' => $id,
                'agentType' => $type,
                'slot' => $slot,
                'filesRemoved' => !$keepFiles,
            ], \JSON_UNESCAPED_UNICODE));

            return Command::SUCCESS;
        }

        $output->writeln(\sprintf('<info>Удалён слот #%d [%s:%s]</info>%s', (int) $id, $type, $slot, $keepFiles ? ' (файлы сохранены)' : ''));

        return Command::SUCCESS;
    }
}
