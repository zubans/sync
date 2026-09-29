<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Фото контактов; удаление личных контактов без телефонов (служебные записи мессенджеров)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact ADD photo_sha256 VARCHAR(64) DEFAULT NULL');

        // До этой версии синхронизировались и контакты без телефонов — например, скрытые записи Telegram,
        // которых нет в телефонной книге. Теперь они не синхронизируются; уже загруженные удаляем.
        // Связи с устройствами удаляются каскадом. Семейные контакты не трогаем.
        $this->addSql("DELETE FROM contact WHERE user_id IS NOT NULL AND CAST(phones AS TEXT) = '[]'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE contact DROP COLUMN photo_sha256');
    }
}
