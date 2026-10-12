<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012230000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Календари: «Поделиться с» — выбранные члены семьи владельца';
    }

    public function up(Schema $schema): void
    {
        // Связующая таблица ManyToMany: ключ — пара (календарь, пользователь), а не id, как в table().
        $this->createTables(Table::editor()
            ->setUnquotedName('calendar_share')
            ->setColumns(self::col('calendar_id', Types::INTEGER), self::col('user_id', Types::INTEGER))
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('calendar_id', 'user_id')->create())
            ->setIndexes(self::index('IDX_83529E34A40A2C8', ['calendar_id']), self::index('IDX_83529E34A76ED395', ['user_id']))
            ->setForeignKeyConstraints(
                self::fk('FK_83529E34A40A2C8', 'calendar_id', 'calendar', ReferentialAction::CASCADE),
                self::fk('FK_83529E34A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            )
            ->create());
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('calendar_share');
    }
}
