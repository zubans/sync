<?php

namespace App\Service;

use App\Entity\AiAgentAccount;
use App\Entity\AiProvider;
use App\Repository\AiAgentAccountRepository;
use App\Repository\AiProviderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Единое управление подключёнными ИИ-провайдерами и пулом отдельных аккаунтов CLI-агентов
 * (`antigravity_cli` / agy, `claude_cli`, `codex_cli`, `gemini_cli`, `kimi_cli`).
 * Используется и консольными командами (`app:ai:*`), и веб-админкой.
 */
final class AiAgentManager
{
    private readonly Filesystem $fs;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AiProviderRepository $providers,
        private readonly AiAgentAccountRepository $accounts,
        private readonly SecretBox $secretBox,
        private readonly CliProcessExecutor $executor,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(resolve:NEWS_AGY_HOME)%')]
        private readonly string $agyHome,
        #[Autowire('%env(NEWS_EMBEDDINGS_PROVIDER)%')]
        private readonly string $embeddingsProvider = 'ollama',
        #[Autowire('%env(NEWS_EMBEDDINGS_URL)%')]
        private readonly string $embeddingsUrl = '',
        #[Autowire('%env(NEWS_EMBEDDINGS_KEY)%')]
        private readonly string $embeddingsKey = '',
        #[Autowire('%env(NEWS_EMBEDDINGS_MODEL)%')]
        private readonly string $embeddingsModel = 'qwen3-embedding:4b',
        #[Autowire('%env(NEWS_DIGEST_MODEL_HOUR)%')]
        private readonly string $digestModelHour = '',
        #[Autowire('%env(NEWS_DIGEST_MODEL_DAY)%')]
        private readonly string $digestModelDay = '',
        #[Autowire('%env(MARKET_ANALYSIS_MODEL)%')]
        private readonly string $marketAnalysisModel = '',
    ) {
        $this->fs = new Filesystem();
    }

    /**
     * Подхватывает текущую конфигурацию `.env` и существующие папки входа `var/agy` / `var/agents`,
     * если провайдеров ещё нет или появились новые входы, выполненные напрямую из консоли.
     */
    public function syncFromEnvironmentAndFilesystem(): void
    {
        if ($this->providers->countAll() === 0) {
            $agy = (new AiProvider())
                ->setName('Antigravity CLI (подписка Google)')
                ->setType(AiProvider::TYPE_ANTIGRAVITY_CLI)
                ->setEnabled(true)
                ->setPriority(10)
                ->setUseForSummarization(true)
                ->setUseForEmbeddings(false);

            $models = array_values(array_unique(array_filter([
                trim($this->digestModelHour),
                trim($this->digestModelDay),
                trim($this->marketAnalysisModel),
                ...AiAgentCatalog::get(AiProvider::TYPE_ANTIGRAVITY_CLI)['defaultModels'],
            ])));
            $agy->setAvailableModels($models);
            if (trim($this->digestModelHour) !== '') {
                $agy->setDefaultModel(trim($this->digestModelHour));
            }
            $this->em->persist($agy);

            if ($this->embeddingsProvider === NewsEmbedder::PROVIDER_OLLAMA && trim($this->embeddingsUrl) !== '') {
                $ollama = (new AiProvider())
                    ->setName('Ollama (эмбеддинги новостей)')
                    ->setType(AiProvider::TYPE_OLLAMA)
                    ->setEnabled(true)
                    ->setIsLocal(true)
                    ->setPriority(100)
                    ->setBaseUrl($this->embeddingsUrl)
                    ->setDefaultModel(trim($this->embeddingsModel) ?: 'qwen3-embedding:4b')
                    ->setAvailableModels([trim($this->embeddingsModel) ?: 'qwen3-embedding:4b'])
                    ->setUseForSummarization(false)
                    ->setUseForEmbeddings(true);
                $this->em->persist($ollama);
            } elseif ($this->embeddingsProvider === NewsEmbedder::PROVIDER_GEMINI && trim($this->embeddingsKey) !== '') {
                $gemini = (new AiProvider())
                    ->setName('Gemini API (эмбеддинги новостей)')
                    ->setType(AiProvider::TYPE_GEMINI)
                    ->setEnabled(true)
                    ->setIsLocal(false)
                    ->setPriority(100)
                    ->setBaseUrl('https://generativelanguage.googleapis.com')
                    ->setApiKeyEncrypted($this->secretBox->encrypt($this->embeddingsKey))
                    ->setDefaultModel('gemini-embedding-001')
                    ->setAvailableModels(['gemini-embedding-001'])
                    ->setUseForSummarization(false)
                    ->setUseForEmbeddings(true);
                $this->em->persist($gemini);
            }
            $this->em->flush();
        }

        // Синхронизируем существующие папки `var/agy` и `var/agy/account*` с БД, чтобы ручной `make agy-login` не терялся.
        $agyProvider = $this->providers->findFirstByType(AiProvider::TYPE_ANTIGRAVITY_CLI);
        if ($agyProvider !== null) {
            $agyAccounts = new AgyAccounts($this->agyHome);
            $changed = false;
            $dirs = [$this->agyHome => 'main'];
            foreach (glob($this->agyHome.'/account[0-9]*', \GLOB_ONLYDIR) ?: [] as $dir) {
                if (is_file($dir.AgyAccounts::TOKEN)) {
                    $dirs[$dir] = basename($dir);
                }
            }
            // Основной слот регистрируем, даже если токен ещё не получен, если папка или другие аккаунты отсутствуют.
            if ($this->accounts->findOneByTypeAndSlot(AiProvider::TYPE_ANTIGRAVITY_CLI, 'main') === null && \count($dirs) >= 1) {
                $mainAcc = $this->instantiateAccount($agyProvider, 'main', 'Основной аккаунт', $this->agyHome, 10);
                $this->refreshAccountStatusFromDisk($mainAcc);
                $this->em->persist($mainAcc);
                $changed = true;
            }
            $prio = 20;
            foreach ($dirs as $dir => $slot) {
                if ($slot === 'main') {
                    $acc = $this->accounts->findOneByTypeAndSlot(AiProvider::TYPE_ANTIGRAVITY_CLI, 'main');
                    if ($acc !== null) {
                        $this->refreshAccountStatusFromDisk($acc);
                        $changed = true;
                    }
                    continue;
                }
                if ($this->accounts->findOneByTypeAndSlot(AiProvider::TYPE_ANTIGRAVITY_CLI, $slot) === null) {
                    $acc = $this->instantiateAccount($agyProvider, $slot, 'Аккаунт '.$slot, $dir, $prio);
                    $this->refreshAccountStatusFromDisk($acc);
                    $this->em->persist($acc);
                    $changed = true;
                }
                $prio += 10;
            }
            if ($changed) {
                $this->em->flush();
            }
        }
    }

    public function ensureCliProvider(string $type): AiProvider
    {
        $normalized = AiAgentCatalog::normalizeType($type);
        $meta = AiAgentCatalog::get($normalized);
        if (!$meta['isCli']) {
            throw new \InvalidArgumentException(\sprintf('Тип «%s» не является консольным CLI-агентом.', $type));
        }

        $provider = $this->providers->findFirstByType($normalized);
        if ($provider !== null) {
            return $provider;
        }

        $provider = (new AiProvider())
            ->setName($meta['label'])
            ->setType($normalized)
            ->setEnabled(true)
            ->setPriority($normalized === AiProvider::TYPE_ANTIGRAVITY_CLI ? 10 : 50)
            ->setIsLocal(false)
            ->setAvailableModels($meta['defaultModels'])
            ->setDefaultModel($meta['defaultModels'][0] ?? null)
            ->setUseForSummarization(true)
            ->setUseForEmbeddings(false);

        $this->em->persist($provider);
        $this->em->flush();

        return $provider;
    }

    /**
     * Создаёт новый слот аккаунта консольного агента (`agy`, `claude_cli`, `codex_cli`, `gemini_cli`, `kimi_cli`)
     * с изолированным HOME-каталогом.
     */
    public function createAgentAccount(
        string $agentType,
        ?string $slot = null,
        ?string $label = null,
        ?string $token = null,
        int $priority = 100,
        bool $enabled = true,
        ?int $providerId = null,
    ): AiAgentAccount {
        $normalized = AiAgentCatalog::normalizeType($agentType);
        $meta = AiAgentCatalog::get($normalized);
        if (!$meta['isCli']) {
            throw new \InvalidArgumentException(\sprintf('«%s» — API-провайдер, добавьте его в разделе «Провайдеры ИИ».', $agentType));
        }

        $provider = $providerId !== null ? $this->providers->find($providerId) : null;
        if ($provider === null || $provider->getType() !== $normalized) {
            $provider = $this->ensureCliProvider($normalized);
        }

        $resolvedSlot = $slot !== null && trim($slot) !== ''
            ? $this->sanitizeSlot($slot)
            : $this->nextAvailableSlot($normalized);

        if ($this->accounts->findOneByTypeAndSlot($normalized, $resolvedSlot) !== null) {
            throw new \InvalidArgumentException(\sprintf('Аккаунт «%s» для агента %s уже существует.', $resolvedSlot, $meta['shortSlug']));
        }

        $homeDir = $this->resolveHomeDir($normalized, $resolvedSlot);
        $displayLabel = $label !== null && trim($label) !== ''
            ? trim($label)
            : ($resolvedSlot === 'main' ? 'Основной ('.$meta['shortSlug'].')' : $meta['shortSlug'].' / '.$resolvedSlot);

        $account = $this->instantiateAccount($provider, $resolvedSlot, $displayLabel, $homeDir, $priority);
        $account->setEnabled($enabled);

        $this->prepareIsolatedHome($account);
        if ($token !== null && trim($token) !== '') {
            $account->setAuthTokenEncrypted($this->secretBox->encrypt(trim($token)));
        }
        $this->refreshAccountStatusFromDisk($account);

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    public function deleteAgentAccount(AiAgentAccount $account, bool $purgeFiles = true): void
    {
        $homeDir = $account->getHomeDir();
        $this->em->remove($account);
        $this->em->flush();

        if ($purgeFiles) {
            $this->cleanupHomeDir($homeDir);
        }
    }

    public function toggleAccount(AiAgentAccount $account, bool $enabled): AiAgentAccount
    {
        $account->setEnabled($enabled);
        $this->em->flush();

        return $account;
    }

    public function releaseCooldown(AiAgentAccount $account): AiAgentAccount
    {
        $this->prepareIsolatedHome($account);
        if ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            $file = $this->agyHome.'/agy-state.json';
            if (is_file($file)) {
                $data = json_decode((string) file_get_contents($file), true);
                if (\is_array($data)) {
                    $home = $account->getHomeDir();
                    foreach (array_keys($data) as $key) {
                        if ($key === $home || str_starts_with((string) $key, $home.'|')) {
                            unset($data[$key]);
                        }
                    }
                    file_put_contents($file, json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE), \LOCK_EX);
                }
            }
        }
        $account->releaseCooldown($this->hasLoginArtifact($account) ? AiAgentAccount::STATUS_READY : AiAgentAccount::STATUS_NO_LOGIN);
        $this->em->flush();

        return $account;
    }

    /**
     * Обновляет статус и email аккаунта по артефактам на диске / токену и квоте.
     */
    public function refreshAccountStatusFromDisk(AiAgentAccount $account): void
    {
        if ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            $email = AgyAccounts::email($account->getHomeDir());
            if ($email !== null) {
                $account->setAccountEmail($email);
            }
        }

        if ($account->isCoolingNow()) {
            return;
        }

        if ($this->hasLoginArtifact($account)) {
            if (\in_array($account->getStatus(), [AiAgentAccount::STATUS_NO_LOGIN, AiAgentAccount::STATUS_COOLING], true)) {
                $account->setStatus(AiAgentAccount::STATUS_READY);
            }
        } elseif ($account->getStatus() === AiAgentAccount::STATUS_READY || $account->getStatus() === AiAgentAccount::STATUS_ACTIVE) {
            $account->setStatus(AiAgentAccount::STATUS_NO_LOGIN);
        }
    }

    public function hasLoginArtifact(AiAgentAccount $account): bool
    {
        if ($account->getAuthTokenEncrypted() !== null && $account->getAuthTokenEncrypted() !== '') {
            return true;
        }
        $meta = AiAgentCatalog::get($account->getAgentType());
        foreach ($meta['tokenFiles'] as $suffix) {
            if (is_file($account->getHomeDir().$suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{ok: bool, message: string, email: ?string} */
    public function checkAccount(AiAgentAccount $account): array
    {
        $this->prepareIsolatedHome($account);
        $meta = AiAgentCatalog::get($account->getAgentType());
        $binary = $meta['binary'];

        if ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            $account->setAccountEmail(AgyAccounts::email($account->getHomeDir()));
        }

        if ($binary !== null && !$this->executor->hasBinary($binary)) {
            $msg = \sprintf('Бинарник «%s» не найден в PATH контейнера.', $binary);
            $account->recordCheck(
                $this->hasLoginArtifact($account) ? AiAgentAccount::STATUS_ERROR : AiAgentAccount::STATUS_NO_LOGIN,
                $msg,
            );
            $this->em->flush();

            return ['ok' => false, 'message' => $msg, 'email' => $account->getAccountEmail()];
        }

        if (!$this->hasLoginArtifact($account)) {
            $msg = \sprintf('Вход не выполнен: запустите в консоли `%s`.', $this->buildConsoleLoginCommand($account));
            $account->recordCheck(AiAgentAccount::STATUS_NO_LOGIN, $msg);
            $this->em->flush();

            return ['ok' => false, 'message' => $msg, 'email' => $account->getAccountEmail()];
        }

        $env = $this->buildAgentProcessEnv($account);
        $cmd = match ($account->getAgentType()) {
            AiProvider::TYPE_ANTIGRAVITY_CLI => [$binary ?? 'agy', 'models'],
            default => [$binary ?? 'sh', '--version'],
        };

        $result = $this->executor->run($cmd, $account->getHomeDir(), $env, null, 30);
        if ($result['exitCode'] !== 0) {
            $err = trim($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            if ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI && AgyAccounts::isAuthFailure($err)) {
                $account->recordCheck(AiAgentAccount::STATUS_AUTH_FAILED, $err);
            } elseif ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI && AgyAccounts::isIneligible($err)) {
                $account->recordCheck(AiAgentAccount::STATUS_INELIGIBLE, $err);
            } else {
                $account->recordCheck(AiAgentAccount::STATUS_ERROR, $err !== '' ? $err : 'Код выхода '.$result['exitCode']);
            }
            $this->em->flush();

            return ['ok' => false, 'message' => $account->getLastError() ?? 'Ошибка CLI', 'email' => $account->getAccountEmail()];
        }

        $account->recordCheck(AiAgentAccount::STATUS_READY);
        $this->em->flush();

        $extra = $account->getAccountEmail() !== null ? ' ('.$account->getAccountEmail().')' : '';

        return ['ok' => true, 'message' => 'CLI и учётные данные активны'.$extra.'.', 'email' => $account->getAccountEmail()];
    }

    /** @return array<string, string> */
    public function buildAgentProcessEnv(AiAgentAccount $account): array
    {
        $home = $account->getHomeDir();
        $env = [
            'HOME' => $home,
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        ];
        if ($account->getAgentType() === AiProvider::TYPE_CODEX_CLI) {
            $env['CODEX_HOME'] = $home.'/.codex';
        } elseif ($account->getAgentType() === AiProvider::TYPE_KIMI_CLI) {
            $env['KIMI_HOME'] = $home.'/.kimi';
        }

        $token = $this->secretBox->decrypt($account->getAuthTokenEncrypted());
        if ($token !== null && $token !== '') {
            if ($account->getAgentType() === AiProvider::TYPE_CLAUDE_CLI) {
                $env[str_starts_with($token, 'sk-ant-api') ? 'ANTHROPIC_API_KEY' : 'CLAUDE_CODE_OAUTH_TOKEN'] = $token;
            } else {
                $var = AiAgentCatalog::get($account->getAgentType())['envTokenVar'];
                if ($var !== null) {
                    $env[$var] = $token;
                }
            }
        }

        return $env;
    }

    /** Сбросить файл локальной авторизации для повторного входа (`make logout` из КБ). */
    public function logoutAccount(AiAgentAccount $account): void
    {
        $account->setAuthTokenEncrypted(null);
        $account->setAccountEmail(null);
        foreach (AiAgentCatalog::get($account->getAgentType())['tokenFiles'] as $suffix) {
            $path = $account->getHomeDir().$suffix;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $account->setStatus(AiAgentAccount::STATUS_NO_LOGIN);
        $account->recordCheck(AiAgentAccount::STATUS_NO_LOGIN, 'Сессия сброшена для повторного входа.');
        $this->em->flush();
    }

    public function createProvider(
        string $name,
        string $type,
        ?string $baseUrl,
        ?string $apiKey,
        int $priority = 100,
        bool $isLocal = false,
        bool $enabled = true,
        ?string $defaultModel = null,
        array $availableModels = [],
        bool $useForSummarization = true,
        bool $useForEmbeddings = false,
        int $timeoutSeconds = 300,
    ): AiProvider {
        $normalized = AiAgentCatalog::normalizeType($type);
        $meta = AiAgentCatalog::get($normalized);

        $provider = (new AiProvider())
            ->setName(trim($name) !== '' ? trim($name) : $meta['label'])
            ->setType($normalized)
            ->setEnabled($enabled)
            ->setPriority($priority)
            ->setIsLocal($isLocal)
            ->setBaseUrl($baseUrl !== null && trim($baseUrl) !== '' ? $baseUrl : $meta['defaultUrl'])
            ->setDefaultModel($defaultModel)
            ->setAvailableModels($availableModels !== [] ? $availableModels : $meta['defaultModels'])
            ->setUseForSummarization($useForSummarization)
            ->setUseForEmbeddings($useForEmbeddings && $meta['supportsEmbeddings'])
            ->setTimeoutSeconds($timeoutSeconds);

        if ($apiKey !== null && trim($apiKey) !== '') {
            $provider->setApiKeyEncrypted($this->secretBox->encrypt(trim($apiKey)));
        }

        $this->em->persist($provider);
        $this->em->flush();

        return $provider;
    }

    public function updateProvider(
        AiProvider $provider,
        string $name,
        ?string $baseUrl,
        ?string $newApiKey,
        int $priority,
        bool $isLocal,
        bool $enabled,
        ?string $defaultModel,
        array $availableModels,
        bool $useForSummarization,
        bool $useForEmbeddings,
        int $timeoutSeconds,
    ): AiProvider {
        $meta = AiAgentCatalog::get($provider->getType());
        $provider
            ->setName(trim($name) !== '' ? trim($name) : $provider->getName())
            ->setBaseUrl($baseUrl)
            ->setPriority($priority)
            ->setIsLocal($isLocal)
            ->setEnabled($enabled)
            ->setDefaultModel($defaultModel)
            ->setAvailableModels($availableModels)
            ->setUseForSummarization($useForSummarization)
            ->setUseForEmbeddings($useForEmbeddings && $meta['supportsEmbeddings'])
            ->setTimeoutSeconds($timeoutSeconds);

        if ($newApiKey !== null && trim($newApiKey) !== '') {
            $provider->setApiKeyEncrypted($this->secretBox->encrypt(trim($newApiKey)));
        }

        $this->em->flush();

        return $provider;
    }

    public function toggleProvider(AiProvider $provider, bool $enabled): AiProvider
    {
        $provider->setEnabled($enabled);
        $this->em->flush();

        return $provider;
    }

    public function deleteProvider(AiProvider $provider): void
    {
        foreach ($provider->getAccounts() as $account) {
            $this->cleanupHomeDir($account->getHomeDir());
        }
        $this->em->remove($provider);
        $this->em->flush();
    }

    public function getDecryptedProviderKey(AiProvider $provider): ?string
    {
        return $this->secretBox->decrypt($provider->getApiKeyEncrypted());
    }

    public function getMaskedProviderKey(AiProvider $provider): ?string
    {
        return $this->secretBox->mask($provider->getApiKeyEncrypted());
    }

    public function hasEncryptedAccountToken(AiAgentAccount $account): bool
    {
        return $account->getAuthTokenEncrypted() !== null && $account->getAuthTokenEncrypted() !== '';
    }

    public function buildConsoleLoginCommand(AiAgentAccount $account): string
    {
        $slug = AiAgentCatalog::get($account->getAgentType())['shortSlug'];

        return \sprintf('make ai-login TYPE=%s ACCOUNT=%s', $slug, $account->getSlot());
    }

    public function buildConsoleAddCommand(string $agentType, ?string $slot = null, ?string $label = null): string
    {
        $slug = AiAgentCatalog::get($agentType)['shortSlug'];
        $parts = ['php bin/console app:ai:agent-add', $slug];
        if ($slot !== null && trim($slot) !== '') {
            $parts[] = '--slot='.escapeshellarg($this->sanitizeSlot($slot));
        }
        if ($label !== null && trim($label) !== '') {
            $parts[] = '--label='.escapeshellarg(trim($label));
        }

        return implode(' ', $parts);
    }

    public function prepareIsolatedHome(AiAgentAccount $account): void
    {
        $home = $account->getHomeDir();
        if (!is_dir($home)) {
            $this->fs->mkdir($home, 0o700);
        }

        if ($account->getAgentType() === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            $file = $home.'/.gemini/antigravity-cli/settings.json';
            $settings = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            $settings = \is_array($settings) ? $settings : [];
            $wanted = ['toolPermission' => 'strict', 'allowNonWorkspaceAccess' => false];
            if (array_intersect_key($settings, $wanted) != $wanted) {
                $this->fs->mkdir(\dirname($file), 0o700);
                file_put_contents($file, json_encode($wanted + $settings, \JSON_PRETTY_PRINT));
            }
        } elseif ($account->getAgentType() === AiProvider::TYPE_CODEX_CLI) {
            $this->fs->mkdir($home.'/.codex', 0o700);
        } elseif ($account->getAgentType() === AiProvider::TYPE_KIMI_CLI) {
            $this->fs->mkdir($home.'/.kimi', 0o700);
        } elseif ($account->getAgentType() === AiProvider::TYPE_CLAUDE_CLI) {
            $this->fs->mkdir($home.'/.claude', 0o700);
        } elseif ($account->getAgentType() === AiProvider::TYPE_GEMINI_CLI) {
            $this->fs->mkdir($home.'/.gemini', 0o700);
        }
    }

    private function instantiateAccount(AiProvider $provider, string $slot, string $label, string $homeDir, int $priority): AiAgentAccount
    {
        $account = new AiAgentAccount($provider, $slot, $label, $homeDir);
        $account->setPriority($priority);

        return $account;
    }

    private function sanitizeSlot(string $slot): string
    {
        $clean = mb_strtolower(trim($slot));
        if ($clean === '1') {
            return 'main';
        }
        if (ctype_digit($clean)) {
            return 'account'.$clean;
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,47}$/', $clean)) {
            throw new \InvalidArgumentException('Имя слота может содержать только латиницу в нижнем регистре, цифры, «_» и «-».');
        }

        return $clean;
    }

    private function nextAvailableSlot(string $agentType): string
    {
        $existing = [];
        foreach ($this->accounts->findBy(['agentType' => $agentType]) as $acc) {
            $existing[$acc->getSlot()] = true;
        }

        if (!isset($existing['main'])) {
            return 'main';
        }
        for ($i = 2; $i <= 999; ++$i) {
            $candidate = 'account'.$i;
            if (!isset($existing[$candidate])) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Превышен предел числа слотов для агента.');
    }

    private function resolveHomeDir(string $agentType, string $slot): string
    {
        $agyBase = rtrim($this->agyHome, '/');
        if ($agentType === AiProvider::TYPE_ANTIGRAVITY_CLI) {
            return $slot === 'main' ? $agyBase : $agyBase.'/'.$slot;
        }
        $slug = AiAgentCatalog::get($agentType)['shortSlug'];

        return rtrim($this->projectDir, '/').'/var/agents/'.$slug.'/'.$slot;
    }

    private function cleanupHomeDir(string $homeDir): void
    {
        $cleanHome = rtrim($homeDir, '/');
        $varRoot = rtrim($this->projectDir, '/').'/var/';
        $agyBase = rtrim($this->agyHome, '/');

        // Безопасная граница: удаляем файлы только внутри var/ или настроенного NEWS_AGY_HOME.
        $insideAllowed = str_starts_with($cleanHome.'/', $varRoot) || str_starts_with($cleanHome.'/', $agyBase.'/');
        if (!$insideAllowed && $cleanHome !== $agyBase) {
            return;
        }

        // Особый случай: var/agy может содержать вложенные account2/account3 — их трогать нельзя!
        if ($cleanHome === $agyBase) {
            $this->fs->remove([
                $cleanHome.'/.gemini',
                $cleanHome.'/agy-state.json',
                $cleanHome.'/last-run.jsonl',
            ]);

            return;
        }

        if (is_dir($cleanHome)) {
            $this->fs->remove($cleanHome);
        }
    }
}
