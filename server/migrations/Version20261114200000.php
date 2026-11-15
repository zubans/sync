<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use App\Entity\AiAgentAccount;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114200000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Переключатели функциональных блоков и ИИ-функций, подключённые ИИ-провайдеры и пул аккаунтов CLI-агентов';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('feature_settings', [
                self::id(),
                self::flag('device_data_enabled', true),
                self::flag('contacts_enabled', true),
                self::flag('vault_enabled', true),
                self::flag('apps_enabled', true),
                self::flag('calendar_enabled', true),
                self::flag('browsing_enabled', true),
                self::flag('news_enabled', true),
                self::flag('market_enabled', true),
                self::flag('vpn_enabled', true),
                self::flag('ai_news_enabled', true),
                self::flag('ai_news_translate_enabled', true),
                self::flag('ai_news_storylines_enabled', true),
                self::flag('ai_news_digest_enabled', true),
                self::flag('ai_market_enabled', true),
                self::flag('ai_market_news_impact_enabled', true),
                self::flag('ai_market_analysis_enabled', true),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('ai_provider', [
                self::id(),
                self::col('name', Types::STRING, 120),
                self::colWithDefault('type', Types::STRING, 'antigravity_cli', 32),
                self::flag('enabled', true),
                self::intWithDefault('priority', 100),
                self::flag('is_local', false),
                self::col('base_url', Types::STRING, 255, nullable: true),
                self::col('api_key_encrypted', Types::TEXT, nullable: true),
                self::col('headers', Types::JSON),
                self::intWithDefault('timeout_seconds', 300),
                self::col('default_model', Types::STRING, 180, nullable: true),
                self::col('available_models', Types::JSON),
                self::flag('use_for_summarization', true),
                self::flag('use_for_embeddings', false),
                self::col('last_checked_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('last_check_ok', Types::BOOLEAN, nullable: true),
                self::col('last_error', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('ai_agent_account', [
                self::id(),
                self::col('provider_id', Types::INTEGER),
                self::col('agent_type', Types::STRING, 32),
                self::col('slot', Types::STRING, 64),
                self::col('label', Types::STRING, 120),
                self::col('home_dir', Types::STRING, 255),
                self::flag('enabled', true),
                self::intWithDefault('priority', 100),
                self::colWithDefault('status', Types::STRING, AiAgentAccount::STATUS_NO_LOGIN, 32),
                self::col('account_email', Types::STRING, 180, nullable: true),
                self::col('auth_token_encrypted', Types::TEXT, nullable: true),
                self::col('cooling_until', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('cooling_reason', Types::TEXT, nullable: true),
                self::col('last_used_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('last_checked_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('last_error', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('IDX_AI_AGENT_PROVIDER', ['provider_id']),
                self::index('UNIQ_AI_AGENT_TYPE_SLOT', ['agent_type', 'slot'], unique: true),
            ], [
                self::fk('FK_AI_AGENT_PROVIDER', 'provider_id', 'ai_provider', ReferentialAction::CASCADE),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('ai_agent_account', 'ai_provider', 'feature_settings');
    }

    private static function flag(string $name, bool $default): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName(Types::BOOLEAN)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }

    private static function intWithDefault(string $name, int $default): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName(Types::INTEGER)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }

    private static function colWithDefault(string $name, string $type, mixed $default, int $length): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setLength($length)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }
}
