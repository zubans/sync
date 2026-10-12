<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Новости: полный текст со страницы статьи — когда скачан и сколько было попыток';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getAlterTableSQL($this->diff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_item DROP COLUMN content_fetched_at');
        $this->addSql('ALTER TABLE news_item DROP COLUMN content_attempts');
    }

    /** Колонки через Schema API DBAL — SQL под текущую платформу, как в PortableSchema. */
    private function diff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_item');
        $changed = clone $table;
        $changed->addColumn('content_fetched_at', Types::DATETIME_IMMUTABLE, ['notnull' => false]);
        $changed->addColumn('content_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
