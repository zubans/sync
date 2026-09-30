<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261001120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'День рождения контакта, правки с сервера на устройства, корзина';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD birthday VARCHAR(10) DEFAULT NULL');
        $this->addSql('ALTER TABLE contact ADD server_revision INTEGER DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE contact_link ADD applied_revision INTEGER DEFAULT 0 NOT NULL');
        // Типы под текущую платформу: миграции работают и на SQLite, и на PostgreSQL.
        $this->addSql(\sprintf('ALTER TABLE contact ADD deleted_on_server %s DEFAULT %s NOT NULL',
            $this->platform->getBooleanTypeDeclarationSQL([]), $this->platform->convertBooleans(false)));
        $this->addSql(\sprintf('ALTER TABLE contact ADD restored_at %s DEFAULT NULL',
            $this->platform->getDateTimeTypeDeclarationSQL([])));
        $this->addSql('CREATE INDEX idx_contact_deleted_at ON contact (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_contact_deleted_at');
        $this->addSql('ALTER TABLE contact DROP COLUMN restored_at');
        $this->addSql('ALTER TABLE contact DROP COLUMN deleted_on_server');
        $this->addSql('ALTER TABLE contact_link DROP COLUMN applied_revision');
        $this->addSql('ALTER TABLE contact DROP COLUMN server_revision');
        $this->addSql('ALTER TABLE contact DROP COLUMN birthday');
    }
}
