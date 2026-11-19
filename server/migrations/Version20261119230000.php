<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119230000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Подписки провайдеров и выбор сервера подписки в подключении';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('vpn_subscription', [
                self::id(),
                self::col('external_id', Types::STRING, 64),
                self::col('name', Types::STRING, 120),
                self::col('url', Types::STRING, 1024),
                self::col('hwid', Types::STRING, 128, nullable: true),
                self::col('servers', Types::JSON),
                self::col('info', Types::STRING, 255, nullable: true),
                self::col('refreshed_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
            ], [self::index('UNIQ_VPN_SUBSCRIPTION_EXTERNAL_ID', ['external_id'], unique: true)]),
        );

        $this->addColumns('vpn_connection', [
            'subscription_id' => [Types::STRING, ['notnull' => false, 'length' => 64]],
            'subscription_server' => [Types::STRING, ['notnull' => false, 'length' => 255]],
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_connection DROP COLUMN subscription_server');
        $this->addSql('ALTER TABLE vpn_connection DROP COLUMN subscription_id');
        $this->dropTables('vpn_subscription');
    }

    /** @param array<string, array{0: string, 1: array<string, mixed>}> $columns */
    private function addColumns(string $tableName, array $columns): void
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName($tableName);
        $changed = clone $table;
        foreach ($columns as $name => [$type, $options]) {
            $changed->addColumn($name, $type, $options);
        }
        foreach ($this->platform->getAlterTableSQL($manager->createComparator()->compareTables($table, $changed)) as $sql) {
            $this->addSql($sql);
        }
    }
}
