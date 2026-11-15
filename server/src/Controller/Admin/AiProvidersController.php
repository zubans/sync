<?php

namespace App\Controller\Admin;

use App\Entity\AiAgentAccount;
use App\Entity\AiProvider;
use App\Entity\User;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use App\Service\AiAgentCatalog;
use App\Service\AiAgentManager;
use App\Service\AiConsoleRunner;
use App\Service\AiProviderTester;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Админка подключённых провайдеров ИИ и отдельных аккаунтов консольных CLI-агентов
 * (Antigravity CLI / agy, Claude CLI, Codex CLI, Gemini CLI, Kimi CLI и HTTP API-провайдеры).
 * Операции над CLI-аккаунтами выполняются через консольные команды (`app:ai:agent-*`).
 */
#[IsGranted(User::ROLE_ADMIN)]
#[AdminRoute('/ai-providers', name: 'ai_providers')]
final class AiProvidersController extends AbstractController
{
    private const CSRF_ACTION = 'admin_ai_providers_action';

    #[AdminRoute('/', name: 'index')]
    public function index(
        AiProviderRepository $providers,
        AiAgentAccountRepository $accounts,
        AiAgentManager $agentManager,
    ): Response {
        $providerList = $providers->findAllOrdered();
        $accountList = $accounts->findAllOrdered();

        $providerViews = [];
        foreach ($providerList as $provider) {
            $providerViews[] = [
                'entity' => $provider,
                'meta' => AiAgentCatalog::get($provider->getType()),
                'maskedKey' => $agentManager->getMaskedProviderKey($provider),
            ];
        }

        $accountViews = [];
        foreach ($accountList as $account) {
            $accountViews[] = [
                'entity' => $account,
                'meta' => AiAgentCatalog::get($account->getAgentType()),
                'hasAuth' => $agentManager->hasLoginArtifact($account),
                'hasToken' => $agentManager->hasEncryptedAccountToken($account),
                'loginCommand' => $agentManager->buildConsoleLoginCommand($account),
            ];
        }

        return $this->render('admin/ai_providers.html.twig', [
            'providers' => $providerViews,
            'accounts' => $accountViews,
            'catalog' => AiAgentCatalog::definitions(),
            'cliChoices' => AiAgentCatalog::cliChoices(),
            'providerChoices' => AiAgentCatalog::allProviderChoices(),
            'cliTypes' => AiProvider::CLI_TYPES,
            'csrfTokenId' => self::CSRF_ACTION,
            'envInfo' => [
                'agyHome' => $_ENV['NEWS_AGY_HOME'] ?? getenv('NEWS_AGY_HOME') ?: 'var/agy',
                'embeddingsProvider' => $_ENV['NEWS_EMBEDDINGS_PROVIDER'] ?? getenv('NEWS_EMBEDDINGS_PROVIDER') ?: 'ollama',
                'embeddingsUrl' => $_ENV['NEWS_EMBEDDINGS_URL'] ?? getenv('NEWS_EMBEDDINGS_URL') ?: '',
                'digestHourModel' => $_ENV['NEWS_DIGEST_MODEL_HOUR'] ?? getenv('NEWS_DIGEST_MODEL_HOUR') ?: '',
                'digestDayModel' => $_ENV['NEWS_DIGEST_MODEL_DAY'] ?? getenv('NEWS_DIGEST_MODEL_DAY') ?: '',
            ],
        ]);
    }

    #[AdminRoute('/sync', name: 'sync', options: ['methods' => ['POST']])]
    public function sync(Request $request, AiAgentManager $agentManager): Response
    {
        $this->assertValidCsrf($request);
        $agentManager->syncFromEnvironmentAndFilesystem();
        $this->addFlash('success', 'Конфигурация .env и существующие каталоги var/agy импортированы в базу.');

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/create', name: 'provider_create', options: ['methods' => ['POST']])]
    public function createProvider(Request $request, AiAgentManager $agentManager): Response
    {
        $this->assertValidCsrf($request);

        try {
            $type = (string) $request->request->get('type', '');
            $models = self::parseModels((string) $request->request->get('available_models', ''));
            $provider = $agentManager->createProvider(
                name: (string) $request->request->get('name', ''),
                type: $type,
                baseUrl: $this->nullableString($request->request->get('base_url')),
                apiKey: $this->nullableString($request->request->get('api_key')),
                priority: max(1, (int) $request->request->get('priority', 100)),
                isLocal: $request->request->getBoolean('is_local'),
                enabled: $request->request->getBoolean('enabled', true),
                defaultModel: $this->nullableString($request->request->get('default_model')),
                availableModels: $models,
                useForSummarization: $request->request->getBoolean('use_for_summarization'),
                useForEmbeddings: $request->request->getBoolean('use_for_embeddings'),
                timeoutSeconds: max(10, (int) $request->request->get('timeout_seconds', 300)),
            );
            $this->addFlash('success', \sprintf('Провайдер ИИ «%s» добавлен (#%d).', $provider->getName(), (int) $provider->getId()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Не удалось добавить провайдера: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/{id}/update', name: 'provider_update', options: ['methods' => ['POST']])]
    public function updateProvider(
        int $id,
        Request $request,
        AiProviderRepository $providers,
        AiAgentManager $agentManager,
    ): Response {
        $this->assertValidCsrf($request);
        $provider = $providers->find($id);
        if ($provider === null) {
            throw $this->createNotFoundException('Провайдер ИИ не найден.');
        }

        try {
            $models = self::parseModels((string) $request->request->get('available_models', ''));
            $agentManager->updateProvider(
                provider: $provider,
                name: (string) $request->request->get('name', $provider->getName()),
                baseUrl: $this->nullableString($request->request->get('base_url')),
                newApiKey: $this->nullableString($request->request->get('api_key')),
                priority: max(1, (int) $request->request->get('priority', $provider->getPriority())),
                isLocal: $request->request->getBoolean('is_local'),
                enabled: $request->request->getBoolean('enabled'),
                defaultModel: $this->nullableString($request->request->get('default_model')),
                availableModels: $models,
                useForSummarization: $request->request->getBoolean('use_for_summarization'),
                useForEmbeddings: $request->request->getBoolean('use_for_embeddings'),
                timeoutSeconds: max(10, (int) $request->request->get('timeout_seconds', $provider->getTimeoutSeconds())),
            );
            $this->addFlash('success', \sprintf('Настройки провайдера «%s» обновлены.', $provider->getName()));
        } catch (\Throwable $e) {
            $this->addFlash('danger', 'Ошибка сохранения провайдера: '.$e->getMessage());
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/{id}/toggle', name: 'provider_toggle', options: ['methods' => ['POST']])]
    public function toggleProvider(
        int $id,
        Request $request,
        AiProviderRepository $providers,
        AiAgentManager $agentManager,
    ): Response {
        $this->assertValidCsrf($request);
        $provider = $providers->find($id);
        if ($provider === null) {
            throw $this->createNotFoundException('Провайдер ИИ не найден.');
        }

        $next = !$provider->isEnabled();
        $agentManager->toggleProvider($provider, $next);
        $this->addFlash('success', \sprintf('Провайдер «%s» %s.', $provider->getName(), $next ? 'включён' : 'выключен'));

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/{id}/test', name: 'provider_test', options: ['methods' => ['POST']])]
    public function testProvider(
        int $id,
        Request $request,
        AiProviderRepository $providers,
        AiProviderTester $tester,
    ): Response {
        $this->assertValidCsrf($request);
        $provider = $providers->find($id);
        if ($provider === null) {
            throw $this->createNotFoundException('Провайдер ИИ не найден.');
        }

        $result = $tester->testConnection($provider);
        $this->addFlash($result['ok'] ? 'success' : 'warning', $provider->getName().': '.$result['message']);

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/{id}/fetch-models', name: 'provider_fetch_models', options: ['methods' => ['POST']])]
    public function fetchModels(
        int $id,
        Request $request,
        AiProviderRepository $providers,
        AiProviderTester $tester,
    ): Response {
        $this->assertValidCsrf($request);
        $provider = $providers->find($id);
        if ($provider === null) {
            throw $this->createNotFoundException('Провайдер ИИ не найден.');
        }

        try {
            $res = $tester->fetchModels($provider);
            $this->addFlash('success', \sprintf('«%s»: загружено моделей — %d (добавлено %d).', $provider->getName(), $res['total'], \count($res['added'])));
        } catch (\Throwable $e) {
            $this->addFlash('warning', \sprintf('«%s»: не удалось получить список моделей (%s).', $provider->getName(), $e->getMessage()));
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/provider/{id}/delete', name: 'provider_delete', options: ['methods' => ['POST']])]
    public function deleteProvider(
        int $id,
        Request $request,
        AiProviderRepository $providers,
        AiAgentManager $agentManager,
    ): Response {
        $this->assertValidCsrf($request);
        $provider = $providers->find($id);
        if ($provider === null) {
            throw $this->createNotFoundException('Провайдер ИИ не найден.');
        }

        $name = $provider->getName();
        $agentManager->deleteProvider($provider);
        $this->addFlash('success', \sprintf('Провайдер «%s» и связанные с ним аккаунты удалены.', $name));

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/agent/add', name: 'agent_add', options: ['methods' => ['POST']])]
    public function addAgent(Request $request, AiConsoleRunner $console): Response
    {
        $this->assertValidCsrf($request);

        $agentType = trim((string) $request->request->get('agent_type', AiProvider::TYPE_ANTIGRAVITY_CLI));
        $slot = trim((string) $request->request->get('slot', ''));
        $label = trim((string) $request->request->get('label', ''));
        $token = trim((string) $request->request->get('token', ''));
        $priority = max(1, (int) $request->request->get('priority', 100));
        $enabled = $request->request->getBoolean('enabled', true);

        $args = [
            'agentType' => $agentType,
            '--priority' => (string) $priority,
            '--json' => true,
        ];
        if ($slot !== '') {
            $args['--slot'] = $slot;
        }
        if ($label !== '') {
            $args['--label'] = $label;
        }
        if ($token !== '') {
            $args['--token'] = $token;
        }
        if (!$enabled) {
            $args['--disabled'] = true;
        }

        $res = $console->run('app:ai:agent-add', $args);
        $data = $res['data'];

        if ($res['exitCode'] === 0 && \is_array($data) && ($data['ok'] ?? false) === true) {
            $loginCmd = (string) ($data['loginCommand'] ?? '');
            $this->addFlash(
                'success',
                \sprintf(
                    'Консольная команда `app:ai:agent-add` создала слот #%s [%s:%s] «%s». Для интерактивного входа выполните в терминале: %s',
                    (string) ($data['id'] ?? ''),
                    (string) ($data['shortSlug'] ?? $agentType),
                    (string) ($data['slot'] ?? ''),
                    (string) ($data['label'] ?? ''),
                    $loginCmd,
                ),
            );
        } else {
            $err = \is_array($data) && isset($data['error']) ? (string) $data['error'] : $res['output'];
            $this->addFlash('danger', 'Ошибка `app:ai:agent-add`: '.$err);
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/agent/{id}/toggle', name: 'agent_toggle', options: ['methods' => ['POST']])]
    public function toggleAgent(
        int $id,
        Request $request,
        AiAgentAccountRepository $accounts,
        AiConsoleRunner $console,
    ): Response {
        $this->assertValidCsrf($request);
        $account = $accounts->find($id);
        if ($account === null) {
            throw $this->createNotFoundException('Аккаунт CLI-агента не найден.');
        }

        $expectedState = !$account->isEnabled();
        $targetFlag = $account->isEnabled() ? '--disable' : '--enable';
        $res = $console->run('app:ai:agent-toggle', [
            'identifier' => (string) $id,
            $targetFlag => true,
            '--json' => true,
        ]);

        if ($res['exitCode'] === 0) {
            $newState = (bool) ($res['data']['enabled'] ?? $expectedState);
            $this->addFlash('success', \sprintf('Аккаунт #%d [%s:%s] %s.', $id, $account->getAgentType(), $account->getSlot(), $newState ? 'включён' : 'выключен'));
        } else {
            $this->addFlash('danger', 'Ошибка `app:ai:agent-toggle`: '.$res['output']);
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/agent/{id}/check', name: 'agent_check', options: ['methods' => ['POST']])]
    public function checkAgent(
        int $id,
        Request $request,
        AiAgentAccountRepository $accounts,
        AiConsoleRunner $console,
    ): Response {
        $this->assertValidCsrf($request);
        if ($accounts->find($id) === null) {
            throw $this->createNotFoundException('Аккаунт CLI-агента не найден.');
        }

        $res = $console->run('app:ai:agent-check', [
            'identifier' => (string) $id,
            '--json' => true,
        ]);

        $item = $res['data']['results'][0] ?? null;
        if (\is_array($item)) {
            $this->addFlash(
                ($item['ok'] ?? false) ? 'success' : 'warning',
                \sprintf('[%s:%s] %s', (string) ($item['agentType'] ?? ''), (string) ($item['slot'] ?? ''), (string) ($item['message'] ?? '')),
            );
        } else {
            $this->addFlash($res['exitCode'] === 0 ? 'success' : 'warning', $res['output'] !== '' ? $res['output'] : 'Проверка завершена.');
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/agent/{id}/release', name: 'agent_release', options: ['methods' => ['POST']])]
    public function releaseAgentCooldown(
        int $id,
        Request $request,
        AiAgentAccountRepository $accounts,
        AiAgentManager $agentManager,
    ): Response {
        $this->assertValidCsrf($request);
        $account = $accounts->find($id);
        if ($account === null) {
            throw $this->createNotFoundException('Аккаунт CLI-агента не найден.');
        }

        $agentManager->releaseCooldown($account);
        $this->addFlash('success', \sprintf('Ограничение/пауза для аккаунта #%d [%s:%s] сброшена.', $id, $account->getAgentType(), $account->getSlot()));

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    #[AdminRoute('/agent/{id}/delete', name: 'agent_delete', options: ['methods' => ['POST']])]
    public function deleteAgent(
        int $id,
        Request $request,
        AiAgentAccountRepository $accounts,
        AiConsoleRunner $console,
    ): Response {
        $this->assertValidCsrf($request);
        $account = $accounts->find($id);
        if ($account === null) {
            throw $this->createNotFoundException('Аккаунт CLI-агента не найден.');
        }

        $agentType = $account->getAgentType();
        $slot = $account->getSlot();
        $keepFiles = $request->request->getBoolean('keep_files');
        $args = [
            'identifier' => (string) $id,
            '--json' => true,
        ];
        if ($keepFiles) {
            $args['--keep-files'] = true;
        }

        $res = $console->run('app:ai:agent-remove', $args);
        if ($res['exitCode'] === 0) {
            $this->addFlash(
                'success',
                \sprintf(
                    'Консольная команда `app:ai:agent-remove` удалила слот #%d [%s:%s]%s.',
                    $id,
                    $agentType,
                    $slot,
                    $keepFiles ? ' (файлы сессии сохранены)' : ' (локальная папка удалена)',
                ),
            );
        } else {
            $this->addFlash('danger', 'Ошибка `app:ai:agent-remove`: '.$res['output']);
        }

        return $this->redirectToRoute('admin_ai_providers_index');
    }

    private function assertValidCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_ACTION, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Сессия устарела, обновите страницу.');
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /** @return list<string> */
    private static function parseModels(string $raw): array
    {
        $parts = preg_split('/[\r\n,;]+/', $raw) ?: [];
        $out = [];
        foreach ($parts as $item) {
            $m = trim($item);
            if ($m !== '' && !\in_array($m, $out, true)) {
                $out[] = $m;
            }
        }

        return $out;
    }
}
