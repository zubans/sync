<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927190000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Мягкое удаление контактов с устройств и «надгробия» удалённых администратором';
    }

    public function up(Schema $schema): void
    {
        // ALTER TABLE … ADD … DEFAULT NULL — одинаковый синтаксис в SQLite, PostgreSQL и MySQL.
        $this->addSql('ALTER TABLE contact ADD deleted_at '.$this->platform->getDateTimeTypeDeclarationSQL([]).' DEFAULT NULL');
        $this->createTables(
            self::table('contact_tombstone', [
                self::id(),
                self::col('uuid', Types::STRING, 36),
                self::col('deleted_at', Types::DATETIME_IMMUTABLE),
                self::col('user_id', Types::INTEGER),
            ], [
                self::index('uniq_contact_tombstone_user_uuid', ['user_id', 'uuid'], unique: true),
                self::index('IDX_5C236658A76ED395', ['user_id']),
            ], [
                self::fk('FK_5C236658A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('contact_tombstone');
        $this->addSql('ALTER TABLE contact DROP COLUMN deleted_at');
    }
}
