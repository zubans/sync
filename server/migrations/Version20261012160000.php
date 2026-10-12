<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012160000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Новости: векторы и сюжетные линии';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('news_storyline', [
                self::id(),
                self::col('title', Types::STRING, 300, nullable: true),
                self::col('summary', Types::TEXT, nullable: true),
                self::col('centroid', Types::TEXT),
                self::col('news_count', Types::INTEGER),
                self::col('named_at_count', Types::INTEGER),
                self::col('first_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('last_seen_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('idx_news_storyline_last_seen', ['last_seen_at']),
            ]),
        );
        foreach ($this->platform->getAlterTableSQL($this->newsItemDiff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_item DROP COLUMN storyline_id');
        $this->addSql('ALTER TABLE news_item DROP COLUMN embedding');
        $this->dropTables('news_storyline');
    }

    /** Колонки, внешний ключ и индекс через Schema API DBAL — SQL под текущую платформу. */
    private function newsItemDiff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_item');
        $changed = clone $table;
        $changed->addColumn('embedding', Types::TEXT, ['notnull' => false]);
        $changed->addColumn('storyline_id', Types::INTEGER, ['notnull' => false]);
        $changed->addIndex(['storyline_id'], 'IDX_CAC6D395FB4B2033');
        $changed->addForeignKeyConstraint('news_storyline', ['storyline_id'], ['id'], ['onDelete' => 'SET NULL'], 'FK_CAC6D395FB4B2033');

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
