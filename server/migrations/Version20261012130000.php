<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Сводки новостей: формат «одна статья» — заголовок и текст';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getAlterTableSQL($this->diff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE news_digest DROP COLUMN article_title');
        $this->addSql('ALTER TABLE news_digest DROP COLUMN article_text');
    }

    /** Две колонки через Schema API DBAL — SQL под текущую платформу, как в PortableSchema. */
    private function diff(): \Doctrine\DBAL\Schema\TableDiff
    {
        $table = $this->connection->createSchemaManager()->introspectTableByUnquotedName('news_digest');

        return $this->connection->createSchemaManager()->createComparator()->compareTables($table, (static function ($t) {
            $t = clone $t;
            $t->addColumn('article_title', Types::STRING, ['length' => 300, 'notnull' => false]);
            $t->addColumn('article_text', Types::TEXT, ['notnull' => false]);

            return $t;
        })($table));
    }
}
