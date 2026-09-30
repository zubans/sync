<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Семья — отметка общего доступа: у каждого контакта есть владелец';
    }

    public function up(Schema $schema): void
    {
        // Раньше семейные контакты принадлежали семье без владельца. Теперь владелец обязателен:
        // назначаем члена семьи с наименьшим id. В семье без участников контакт остаётся без владельца —
        // его никто не получает, администратор назначит владельца при редактировании.
        $this->addSql('UPDATE contact SET user_id = (SELECT MIN(u.id) FROM app_user u WHERE u.family_id = contact.family_id) '
            .'WHERE user_id IS NULL AND family_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('UPDATE contact SET user_id = NULL WHERE family_id IS NOT NULL');
    }
}
