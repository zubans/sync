<?php

namespace App\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsMiddleware;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * В SQLite внешние ключи (и ON DELETE CASCADE) по умолчанию выключены.
 * Включаем их только для SQLite, остальные драйверы не трогаем.
 */
#[AsMiddleware]
final class SqliteForeignKeysMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class($driver) extends AbstractDriverMiddleware {
            public function connect(#[\SensitiveParameter] array $params): Connection
            {
                $connection = parent::connect($params);
                if (\in_array($params['driver'] ?? null, ['pdo_sqlite', 'sqlite3'], true)) {
                    $connection->exec('PRAGMA foreign_keys = ON');
                }

                return $connection;
            }
        };
    }
}
