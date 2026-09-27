<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927140000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Хранилище паролей, установленные приложения, APK';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('vault', [
                self::id(),
                self::col('kdf_algorithm', Types::STRING, 32),
                self::col('kdf_iterations', Types::INTEGER),
                self::col('kdf_salt', Types::STRING, 64),
                self::col('protected_key', Types::STRING, 255),
                self::col('revision', Types::INTEGER),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
                self::col('user_id', Types::INTEGER),
            ], [
                self::index('UNIQ_FF304921A76ED395', ['user_id'], unique: true),
            ], [
                self::fk('FK_FF304921A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('vault_item', [
                self::id(),
                self::col('uuid', Types::STRING, 36),
                self::col('revision', Types::INTEGER),
                self::col('data', Types::TEXT, nullable: true),
                self::col('deleted', Types::BOOLEAN),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
                self::col('vault_id', Types::INTEGER),
            ], [
                self::index('uniq_vault_item_uuid', ['vault_id', 'uuid'], unique: true),
                self::index('idx_vault_item_revision', ['vault_id', 'revision']),
                self::index('IDX_EFCC5CE458AC2DF8', ['vault_id']),
            ], [
                self::fk('FK_EFCC5CE458AC2DF8', 'vault_id', 'vault', ReferentialAction::CASCADE),
            ]),
            self::table('installed_app', [
                self::id(),
                self::col('package_name', Types::STRING, 255),
                self::col('label', Types::STRING, 255, nullable: true),
                self::col('version_name', Types::STRING, 100, nullable: true),
                self::col('version_code', Types::BIGINT),
                self::col('installer', Types::STRING, 255, nullable: true),
                self::col('signing_sha256', Types::STRING, 64, nullable: true),
                self::col('files', Types::JSON),
                self::col('first_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('last_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('removed_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('user_id', Types::INTEGER),
                self::col('device_id', Types::INTEGER),
            ], [
                self::index('uniq_installed_app_device_package', ['device_id', 'package_name'], unique: true),
                self::index('idx_installed_app_user_package', ['user_id', 'package_name']),
                self::index('IDX_CE93C7FDA76ED395', ['user_id']),
                self::index('IDX_CE93C7FD94A4C7D4', ['device_id']),
            ], [
                self::fk('FK_CE93C7FDA76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
                self::fk('FK_CE93C7FD94A4C7D4', 'device_id', 'device', ReferentialAction::CASCADE),
            ]),
            self::table('apk_blob', [
                self::id(),
                self::col('sha256', Types::STRING, 64),
                self::col('size', Types::BIGINT),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('UNIQ_DC4072345CC814F7', ['sha256'], unique: true),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('apk_blob', 'installed_app', 'vault_item', 'vault');
    }
}
