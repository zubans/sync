<?php

namespace App\Doctrine\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Index;
use Doctrine\DBAL\Schema\Index\IndexType;
use Doctrine\DBAL\Schema\PrimaryKeyConstraint;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Types;

/**
 * Описание таблиц для миграций через Schema API DBAL: SQL генерируется под текущую платформу,
 * поэтому миграции работают и на SQLite, и на PostgreSQL/MySQL.
 */
trait PortableSchema
{
    /** Создаёт таблицы SQL-запросами под текущую платформу. */
    private function createTables(Table ...$tables): void
    {
        foreach (Schema::editor()->setTables(...$tables)->create()->toSql($this->platform) as $sql) {
            $this->addSql($sql);
        }
    }

    private function dropTables(string ...$tables): void
    {
        foreach ($tables as $table) {
            $this->addSql($this->platform->getDropTableSQL($table));
        }
    }

    /**
     * @param list<Column>               $columns
     * @param list<Index>                $indexes
     * @param list<ForeignKeyConstraint> $foreignKeys
     */
    private static function table(string $name, array $columns, array $indexes = [], array $foreignKeys = []): Table
    {
        return Table::editor()
            ->setUnquotedName($name)
            ->setColumns(...$columns)
            ->setPrimaryKeyConstraint(PrimaryKeyConstraint::editor()->setUnquotedColumnNames('id')->create())
            ->setIndexes(...$indexes)
            ->setForeignKeyConstraints(...$foreignKeys)
            ->create();
    }

    private static function id(): Column
    {
        return Column::editor()->setUnquotedName('id')->setTypeName(Types::INTEGER)->setAutoincrement(true)->create();
    }

    private static function col(string $name, string $type, ?int $length = null, bool $nullable = false): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setLength($length)
            ->setNotNull(!$nullable)
            ->create();
    }

    /** @param non-empty-list<non-empty-string> $columns */
    private static function index(string $name, array $columns, bool $unique = false): Index
    {
        return Index::editor()
            ->setUnquotedName($name)
            ->setUnquotedColumnNames(...$columns)
            ->setType($unique ? IndexType::UNIQUE : IndexType::REGULAR)
            ->create();
    }

    private static function fk(string $name, string $column, string $refTable, ReferentialAction $onDelete): ForeignKeyConstraint
    {
        return ForeignKeyConstraint::editor()
            ->setUnquotedName($name)
            ->setUnquotedReferencingColumnNames($column)
            ->setUnquotedReferencedTableName($refTable)
            ->setUnquotedReferencedColumnNames('id')
            ->setOnDeleteAction($onDelete)
            ->create();
    }
}
