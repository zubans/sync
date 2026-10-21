<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261021120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Версии Android-клиента для обновления приложения';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(self::table('app_release', [
            self::id(),
            self::col('version_code', Types::INTEGER),
            self::col('version_name', Types::STRING, 64),
            self::col('source_revision', Types::STRING, 64),
            self::col('sha256', Types::STRING, 64),
            self::col('size', Types::BIGINT),
            Column::editor()->setUnquotedName('active')->setTypeName(Types::BOOLEAN)->setNotNull(true)->setDefaultValue(false)->create(),
            self::col('created_at', Types::DATETIME_IMMUTABLE),
        ], [
            self::index('UNIQ_731A8350C6052C9C', ['version_code'], unique: true),
        ]));
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('app_release');
    }
}
