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
 * Проверяет доступность CLI-бинарника и учётных данных одного или всех аккаунтов агентов ИИ.
 */
#[AsCommand(
    name: 'app:ai:agent-check',
    description: 'Проверить вход и работоспособность аккаунта(ов) CLI-агентов ИИ',
)]
final class AiAgentCheckCommand extends Command
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
            ->addArgument('identifier', InputArgument::OPTIONAL, 'ID записи или <type>:<slot>; пусто — проверить все')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Вывести результат в JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $identifier = $input->getArgument('identifier');
        $asJson = (bool) $input->getOption('json');

        $targets = [];
        if ($identifier !== null && trim((string) $identifier) !== '') {
            $one = $this->accounts->findByIdentifier((string) $identifier);
            if ($one === null) {
                $msg = \sprintf('Аккаунт «%s» не найден.', (string) $identifier);
                $output->writeln($asJson
                    ? (string) json_encode(['ok' => false, 'error' => $msg], \JSON_UNESCAPED_UNICODE)
                    : '<error>'.$msg.'</error>');

                return Command::FAILURE;
            }
            $targets[] = $one;
        } else {
            $targets = $this->accounts->findAllOrdered();
        }

        $results = [];
        $allOk = true;
        foreach ($targets as $account) {
            $res = $this->manager->checkAccount($account);
            if (!$res['ok']) {
                $allOk = false;
            }
            $results[] = [
                'id' => $account->getId(),
                'agentType' => $account->getAgentType(),
                'slot' => $account->getSlot(),
                'ok' => $res['ok'],
                'status' => $account->getStatus(),
                'email' => $res['email'],
                'message' => $res['message'],
            ];
            if (!$asJson) {
                $output->writeln(\sprintf(
                    '[%s] #%d %s:%s — %s (%s)',
                    $res['ok'] ? 'OK' : 'ERR',
                    $account->getId() ?? 0,
                    $account->getAgentType(),
                    $account->getSlot(),
                    $account->getStatus(),
                    $res['message'],
                ));
            }
        }

        if ($asJson) {
            $output->writeln((string) json_encode(['ok' => $allOk, 'results' => $results], \JSON_UNESCAPED_UNICODE));
        }

        return $allOk ? Command::SUCCESS : Command::FAILURE;
    }
}
