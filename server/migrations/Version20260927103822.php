<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927103822 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Пользователи, семьи, контакты, устройства, Google-аккаунты, API-токены';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('family', [
                self::id(),
                self::col('name', Types::STRING, 120),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
            ]),
            self::table('app_user', [
                self::id(),
                self::col('email', Types::STRING, 180),
                self::col('roles', Types::JSON),
                self::col('password', Types::STRING, 255),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('family_id', Types::INTEGER, nullable: true),
            ], [
                self::index('UNIQ_88BDF3E9E7927C74', ['email'], unique: true),
                self::index('IDX_88BDF3E9C35E566A', ['family_id']),
            ], [
                self::fk('FK_88BDF3E9C35E566A', 'family_id', 'family', ReferentialAction::SET_NULL),
            ]),
            self::table('api_token', [
                self::id(),
                self::col('token_hash', Types::STRING, 64),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('last_used_at', Types::DATETIME_IMMUTABLE),
                self::col('user_id', Types::INTEGER),
            ], [
                self::index('UNIQ_7BA2F5EBB3BC57DA', ['token_hash'], unique: true),
                self::index('IDX_7BA2F5EBA76ED395', ['user_id']),
            ], [
                self::fk('FK_7BA2F5EBA76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('device', [
                self::id(),
                self::col('install_id', Types::STRING, 64),
                self::col('model', Types::STRING, 255, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('last_sync_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('user_id', Types::INTEGER),
            ], [
                self::index('uniq_device_user_install', ['user_id', 'install_id'], unique: true),
                self::index('IDX_92FB68EA76ED395', ['user_id']),
            ], [
                self::fk('FK_92FB68EA76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('contact', [
                self::id(),
                self::col('uuid', Types::STRING, 36),
                self::col('name', Types::STRING, 255, nullable: true),
                self::col('phones', Types::JSON),
                self::col('emails', Types::JSON),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
                self::col('user_id', Types::INTEGER, nullable: true),
                self::col('family_id', Types::INTEGER, nullable: true),
            ], [
                self::index('UNIQ_4C62E638D17F50A6', ['uuid'], unique: true),
                self::index('idx_contact_name', ['name']),
                self::index('IDX_4C62E638A76ED395', ['user_id']),
                self::index('IDX_4C62E638C35E566A', ['family_id']),
            ], [
                self::fk('FK_4C62E638A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
                self::fk('FK_4C62E638C35E566A', 'family_id', 'family', ReferentialAction::CASCADE),
            ]),
            self::table('contact_link', [
                self::id(),
                self::col('external_id', Types::STRING, 255),
                self::col('device_id', Types::INTEGER),
                self::col('contact_id', Types::INTEGER),
            ], [
                self::index('uniq_link_device_external', ['device_id', 'external_id'], unique: true),
                self::index('IDX_1E531B0E94A4C7D4', ['device_id']),
                self::index('IDX_1E531B0EE7A1254A', ['contact_id']),
            ], [
                self::fk('FK_1E531B0E94A4C7D4', 'device_id', 'device', ReferentialAction::CASCADE),
                self::fk('FK_1E531B0EE7A1254A', 'contact_id', 'contact', ReferentialAction::CASCADE),
            ]),
            self::table('google_account', [
                self::id(),
                self::col('email', Types::STRING, 255),
                self::col('first_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('last_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('user_id', Types::INTEGER),
                self::col('device_id', Types::INTEGER, nullable: true),
            ], [
                self::index('uniq_google_account_user_email', ['user_id', 'email'], unique: true),
                self::index('IDX_83726B22A76ED395', ['user_id']),
                self::index('IDX_83726B2294A4C7D4', ['device_id']),
            ], [
                self::fk('FK_83726B22A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
                self::fk('FK_83726B2294A4C7D4', 'device_id', 'device', ReferentialAction::SET_NULL),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('google_account', 'contact_link', 'contact', 'device', 'api_token', 'app_user', 'family');
    }
}
