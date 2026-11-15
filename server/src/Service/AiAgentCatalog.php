<?php

namespace App\Service;

use App\Entity\AiProvider;

/**
 * Справочник поддерживаемых ИИ-провайдеров и CLI-агентов (по образцу KB).
 */
final class AiAgentCatalog
{
    /**
     * @return array<string, array{
     *     type: string,
     *     shortSlug: string,
     *     label: string,
     *     isCli: bool,
     *     binary: ?string,
     *     loginArgs: list<string>,
     *     tokenFiles: list<string>,
     *     envTokenVar: ?string,
     *     defaultModels: list<string>,
     *     defaultUrl: ?string,
     *     defaultIsLocal: bool,
     *     supportsEmbeddings: bool
     * }>
     */
    public static function definitions(): array
    {
        return [
            AiProvider::TYPE_ANTIGRAVITY_CLI => [
                'type' => AiProvider::TYPE_ANTIGRAVITY_CLI,
                'shortSlug' => 'agy',
                'label' => 'Antigravity CLI (agy — Google подписка)',
                'isCli' => true,
                'binary' => 'agy',
                'loginArgs' => ['agy'],
                'tokenFiles' => ['/.gemini/antigravity-cli/antigravity-oauth-token'],
                'envTokenVar' => null,
                'defaultModels' => ['Gemini 3.8 Flash (Medium)', 'Gemini 3.8 Flash (Low)', 'Claude Sonnet 4.6 (Thinking)'],
                'defaultUrl' => null,
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_CLAUDE_CLI => [
                'type' => AiProvider::TYPE_CLAUDE_CLI,
                'shortSlug' => 'claude',
                'label' => 'Claude Code CLI (claude — подписка Anthropic / setup-token)',
                'isCli' => true,
                'binary' => 'claude',
                'loginArgs' => ['claude', 'auth', 'login'],
                'tokenFiles' => ['/.claude/.credentials.json', '/.claude.json'],
                'envTokenVar' => 'CLAUDE_CODE_OAUTH_TOKEN',
                'defaultModels' => ['sonnet', 'opus', 'haiku'],
                'defaultUrl' => null,
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_CODEX_CLI => [
                'type' => AiProvider::TYPE_CODEX_CLI,
                'shortSlug' => 'codex',
                'label' => 'Codex CLI (codex — подписка ChatGPT / OpenAI)',
                'isCli' => true,
                'binary' => 'codex',
                'loginArgs' => ['codex', 'login'],
                'tokenFiles' => ['/.codex/auth.json', '/auth.json'],
                'envTokenVar' => 'OPENAI_API_KEY',
                'defaultModels' => ['gpt-5', 'gpt-5-codex', 'o3'],
                'defaultUrl' => null,
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_GEMINI_CLI => [
                'type' => AiProvider::TYPE_GEMINI_CLI,
                'shortSlug' => 'gemini-cli',
                'label' => 'Gemini CLI (gemini — Google подписка / GEMINI_API_KEY)',
                'isCli' => true,
                'binary' => 'gemini',
                'loginArgs' => ['gemini'],
                'tokenFiles' => ['/.gemini/oauth_creds.json', '/.gemini/google_accounts.json'],
                'envTokenVar' => 'GEMINI_API_KEY',
                'defaultModels' => ['gemini-2.5-flash', 'gemini-2.5-pro'],
                'defaultUrl' => null,
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_KIMI_CLI => [
                'type' => AiProvider::TYPE_KIMI_CLI,
                'shortSlug' => 'kimi',
                'label' => 'Kimi CLI (kimi — Moonshot AI)',
                'isCli' => true,
                'binary' => 'kimi',
                'loginArgs' => ['kimi', 'login'],
                'tokenFiles' => ['/.kimi/credentials.json', '/.kimi/config.toml', '/config.toml'],
                'envTokenVar' => 'KIMI_API_KEY',
                'defaultModels' => ['kimi-for-coding', 'kimi-k2'],
                'defaultUrl' => null,
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_OLLAMA => [
                'type' => AiProvider::TYPE_OLLAMA,
                'shortSlug' => 'ollama',
                'label' => 'Ollama API (локальный HTTP сервер)',
                'isCli' => false,
                'binary' => null,
                'loginArgs' => [],
                'tokenFiles' => [],
                'envTokenVar' => null,
                'defaultModels' => ['gpt-oss:20b', 'qwen3-embedding:4b'],
                'defaultUrl' => 'http://192.168.1.133:11434',
                'defaultIsLocal' => true,
                'supportsEmbeddings' => true,
            ],
            AiProvider::TYPE_OPENAI => [
                'type' => AiProvider::TYPE_OPENAI,
                'shortSlug' => 'openai',
                'label' => 'OpenAI API',
                'isCli' => false,
                'binary' => null,
                'loginArgs' => [],
                'tokenFiles' => [],
                'envTokenVar' => null,
                'defaultModels' => ['gpt-4.1-mini', 'gpt-4.1'],
                'defaultUrl' => 'https://api.openai.com/v1',
                'defaultIsLocal' => false,
                'supportsEmbeddings' => true,
            ],
            AiProvider::TYPE_ANTHROPIC => [
                'type' => AiProvider::TYPE_ANTHROPIC,
                'shortSlug' => 'anthropic',
                'label' => 'Anthropic API (Claude HTTP)',
                'isCli' => false,
                'binary' => null,
                'loginArgs' => [],
                'tokenFiles' => [],
                'envTokenVar' => null,
                'defaultModels' => ['claude-sonnet-4-6', 'claude-haiku-4-5'],
                'defaultUrl' => 'https://api.anthropic.com',
                'defaultIsLocal' => false,
                'supportsEmbeddings' => false,
            ],
            AiProvider::TYPE_GEMINI => [
                'type' => AiProvider::TYPE_GEMINI,
                'shortSlug' => 'gemini',
                'label' => 'Gemini HTTP API (Google AI Studio)',
                'isCli' => false,
                'binary' => null,
                'loginArgs' => [],
                'tokenFiles' => [],
                'envTokenVar' => null,
                'defaultModels' => ['gemini-2.5-flash', 'gemini-embedding-001'],
                'defaultUrl' => 'https://generativelanguage.googleapis.com',
                'defaultIsLocal' => false,
                'supportsEmbeddings' => true,
            ],
            AiProvider::TYPE_OPENAI_COMPATIBLE => [
                'type' => AiProvider::TYPE_OPENAI_COMPATIBLE,
                'shortSlug' => 'openai_compatible',
                'label' => 'OpenAI-совместимый API (PPR, LiteLLM, vLLM)',
                'isCli' => false,
                'binary' => null,
                'loginArgs' => [],
                'tokenFiles' => [],
                'envTokenVar' => null,
                'defaultModels' => [],
                'defaultUrl' => '',
                'defaultIsLocal' => false,
                'supportsEmbeddings' => true,
            ],
        ];
    }

    /**
     * @return array{
     *     type: string,
     *     shortSlug: string,
     *     label: string,
     *     isCli: bool,
     *     binary: ?string,
     *     loginArgs: list<string>,
     *     tokenFiles: list<string>,
     *     envTokenVar: ?string,
     *     defaultModels: list<string>,
     *     defaultUrl: ?string,
     *     defaultIsLocal: bool,
     *     supportsEmbeddings: bool
     * }
     */
    public static function get(string $type): array
    {
        $resolved = self::normalizeType($type);
        $all = self::definitions();
        if (!isset($all[$resolved])) {
            throw new \InvalidArgumentException(\sprintf('Неизвестный тип провайдера/агента «%s».', $type));
        }

        return $all[$resolved];
    }

    /** Принимает как полное имя (`antigravity_cli`), так и консольный псевдоним (`agy`, `claude`, `codex`, `kimi`, `gemini-cli`). */
    public static function normalizeType(string $type): string
    {
        $clean = mb_strtolower(trim($type));

        return match ($clean) {
            'agy', 'antigravity', 'antigravity_cli' => AiProvider::TYPE_ANTIGRAVITY_CLI,
            'claude', 'claude_code', 'claude_cli' => AiProvider::TYPE_CLAUDE_CLI,
            'codex', 'codex_cli' => AiProvider::TYPE_CODEX_CLI,
            'gemini-cli', 'gemini_cli' => AiProvider::TYPE_GEMINI_CLI,
            'kimi', 'kimi_cli' => AiProvider::TYPE_KIMI_CLI,
            'gemini', 'gemini_api' => AiProvider::TYPE_GEMINI,
            'openai-compatible', 'openai_compatible' => AiProvider::TYPE_OPENAI_COMPATIBLE,
            default => $clean,
        };
    }

    /** @return array<string, string> Варианты CLI-агентов для выпадающих списков */
    public static function cliChoices(): array
    {
        $choices = [];
        foreach (AiProvider::CLI_TYPES as $type) {
            $choices[$type] = self::definitions()[$type]['label'];
        }

        return $choices;
    }

    /** @return array<string, string> Все варианты провайдеров */
    public static function allProviderChoices(): array
    {
        $choices = [];
        foreach (self::definitions() as $type => $meta) {
            $choices[$type] = $meta['label'];
        }

        return $choices;
    }
}
