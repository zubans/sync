<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Прошлая версия приложения (для отката)';
    }

    public function up(Schema $schema): void
    {
        // Типы берём у платформы: JSON в PostgreSQL и CLOB в SQLite.
        $this->addSql('ALTER TABLE installed_app ADD previous_version_name VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE installed_app ADD previous_version_code '.$this->platform->getBigIntTypeDeclarationSQL([]).' DEFAULT NULL');
        $this->addSql('ALTER TABLE installed_app ADD previous_files '.$this->platform->getJsonTypeDeclarationSQL([]).' DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE installed_app DROP COLUMN previous_files');
        $this->addSql('ALTER TABLE installed_app DROP COLUMN previous_version_code');
        $this->addSql('ALTER TABLE installed_app DROP COLUMN previous_version_name');
    }
}
