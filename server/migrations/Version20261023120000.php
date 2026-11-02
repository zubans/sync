<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261023120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'История браузеров с устройств пользователей';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(self::table('browsing_visit', [
            self::id(),
            self::col('user_id', Types::INTEGER),
            self::col('device_id', Types::INTEGER, nullable: true),
            self::col('url', Types::TEXT),
            self::col('title', Types::STRING, 500, nullable: true),
            self::col('browser', Types::STRING, 128, nullable: true),
            self::col('visited_at', Types::DATETIME_IMMUTABLE),
            self::col('created_at', Types::DATETIME_IMMUTABLE),
            self::col('dedup_hash', Types::STRING, 64),
        ], [
            self::index('uniq_browsing_visit_device_dedup', ['device_id', 'dedup_hash'], unique: true),
            self::index('IDX_browsing_visit_user', ['user_id']),
            self::index('IDX_browsing_visit_device', ['device_id']),
        ], [
            self::fk('FK_browsing_visit_user', 'user_id', 'app_user', ReferentialAction::CASCADE),
            self::fk('FK_browsing_visit_device', 'device_id', 'device', ReferentialAction::SET_NULL),
        ]));
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('browsing_visit');
    }
}
