<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Признак активности системного пользователя app_user.enabled для корректного отзыва доступа';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getAlterTableSQL($this->diff()) as $sql) {
            $this->addSql($sql);
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE app_user DROP COLUMN enabled');
    }

    private function diff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('app_user');
        $changed = clone $table;
        $changed->addColumn('enabled', Types::BOOLEAN, ['notnull' => true, 'default' => true]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
