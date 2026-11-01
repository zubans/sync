<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261015120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Новости: перевод статьи по просьбе читателя — translation_requested_at';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getAlterTableSQL($this->diff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_item DROP COLUMN translation_requested_at');
    }

    /** Колонка через Schema API DBAL — SQL под текущую платформу, как в PortableSchema. */
    private function diff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_item');
        $changed = clone $table;
        $changed->addColumn('translation_requested_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
