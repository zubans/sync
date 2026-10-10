<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261010120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Календари и события';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('calendar', [
                self::id(),
                self::col('uuid', Types::STRING, 36),
                self::col('name', Types::STRING, 100),
                self::col('color', Types::STRING, 7),
                self::col('family_shared', Types::BOOLEAN),
                self::col('revision', Types::INTEGER),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('owner_id', Types::INTEGER),
            ], [
                self::index('uniq_calendar_uuid', ['uuid'], unique: true),
                self::index('IDX_6EA9A1467E3C61F9', ['owner_id']),
            ], [
                self::fk('FK_6EA9A1467E3C61F9', 'owner_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('calendar_event', [
                self::id(),
                self::col('uuid', Types::STRING, 36),
                self::col('revision', Types::INTEGER),
                self::col('title', Types::STRING, 500),
                self::col('start_at', Types::DATETIME_IMMUTABLE),
                self::col('end_at', Types::DATETIME_IMMUTABLE),
                self::col('all_day', Types::BOOLEAN),
                self::col('location', Types::STRING, 500, nullable: true),
                self::col('description', Types::TEXT, nullable: true),
                self::col('color', Types::STRING, 7, nullable: true),
                self::col('rrule', Types::STRING, 500, nullable: true),
                self::col('deleted', Types::BOOLEAN),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
                self::col('calendar_id', Types::INTEGER),
                self::col('updated_by_id', Types::INTEGER, nullable: true),
            ], [
                self::index('uniq_calendar_event_uuid', ['uuid'], unique: true),
                self::index('idx_calendar_event_revision', ['calendar_id', 'revision']),
                self::index('idx_calendar_event_start', ['start_at']),
                self::index('IDX_57FA09C9A40A2C8', ['calendar_id']),
                self::index('IDX_57FA09C9896DBBDE', ['updated_by_id']),
            ], [
                self::fk('FK_57FA09C9A40A2C8', 'calendar_id', 'calendar', ReferentialAction::CASCADE),
                self::fk('FK_57FA09C9896DBBDE', 'updated_by_id', 'app_user', ReferentialAction::SET_NULL),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('calendar_event', 'calendar');
    }
}
