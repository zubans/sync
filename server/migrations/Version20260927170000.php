<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260927170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Параметры Argon2id для ключа хранилища';
    }

    public function up(Schema $schema): void
    {
        // ALTER TABLE … ADD … INTEGER DEFAULT NULL — одинаковый синтаксис в SQLite, PostgreSQL и MySQL.
        $this->addSql('ALTER TABLE vault ADD kdf_memory INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE vault ADD kdf_parallelism INTEGER DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vault DROP COLUMN kdf_parallelism');
        $this->addSql('ALTER TABLE vault DROP COLUMN kdf_memory');
    }
}
