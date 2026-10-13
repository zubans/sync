<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Новости: последствия для инструментов Мосбиржи';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('news_market_impact', [
                self::id(),
                self::col('item_id', Types::INTEGER),
                self::col('secid', Types::STRING, 36),
                self::col('name', Types::STRING, 100),
                self::col('engine', Types::STRING, 20),
                self::col('market', Types::STRING, 20),
                self::col('forecast', Types::STRING, 10),
                self::col('reason', Types::TEXT),
                self::col('base_price', Types::FLOAT, nullable: true),
                self::col('hour_price', Types::FLOAT, nullable: true),
                self::flag('hour_final'),
                self::col('day_price', Types::FLOAT, nullable: true),
                self::flag('settled'),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('idx_news_market_impact_settled', ['settled']),
                self::index('IDX_B14E074D126F525E', ['item_id']),
            ], [
                self::fk('FK_B14E074D126F525E', 'item_id', 'news_item', ReferentialAction::CASCADE),
            ]),
        );
        foreach ($this->platform->getAlterTableSQL($this->newsItemDiff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_item DROP COLUMN market_checked_at');
        $this->dropTables('news_market_impact');
    }

    private static function flag(string $name): Column
    {
        return Column::editor()->setUnquotedName($name)->setTypeName(Types::BOOLEAN)->setNotNull(true)->setDefaultValue(false)->create();
    }

    private function newsItemDiff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_item');
        $changed = clone $table;
        $changed->addColumn('market_checked_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
