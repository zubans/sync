<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Роль «Узел на цепочке»: правила пропуска трафика, обёртка Argon4 у серверных служб и порт Argon4 у подключений';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('relay_route', [
                self::id(),
                self::col('name', Types::STRING, 120),
                self::flag('enabled', true),
                self::withDefault('method', Types::STRING, 'argon_blind', 16),
                self::withDefault('transport_proto', Types::STRING, 'udp', 8),
                self::col('listen_port', Types::INTEGER),
                self::col('target_host', Types::STRING, 255),
                self::col('target_port', Types::INTEGER),
                self::col('source_cidr', Types::STRING, 64, nullable: true),
                self::withDefault('argon_path_mtu', Types::INTEGER, 0),
                self::col('comment', Types::TEXT, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
            ]),
        );

        $this->addColumns('vpn_server_service', [
            'argon_wrap_enabled' => [Types::BOOLEAN, ['notnull' => true, 'default' => false]],
            'argon_wrap_port' => [Types::INTEGER, ['notnull' => false]],
            'argon_wrap_password' => [Types::STRING, ['notnull' => false, 'length' => 255]],
        ]);
        $this->addColumns('vpn_connection', [
            'argon_port' => [Types::INTEGER, ['notnull' => false]],
        ]);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vpn_connection DROP COLUMN argon_port');
        $this->addSql('ALTER TABLE vpn_server_service DROP COLUMN argon_wrap_password');
        $this->addSql('ALTER TABLE vpn_server_service DROP COLUMN argon_wrap_port');
        $this->addSql('ALTER TABLE vpn_server_service DROP COLUMN argon_wrap_enabled');
        $this->dropTables('relay_route');
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

    private static function flag(string $name, bool $default): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName(Types::BOOLEAN)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }

    private static function withDefault(string $name, string $type, mixed $default, ?int $length = null): Column
    {
        return Column::editor()
            ->setUnquotedName($name)
            ->setTypeName($type)
            ->setLength($length)
            ->setNotNull(true)
            ->setDefaultValue($default)
            ->create();
    }
}
