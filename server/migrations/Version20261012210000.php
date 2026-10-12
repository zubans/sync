<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Новости: только в сводку — Gulf News (ОАЭ)';
    }

    /** Gulf News — газета Дубая, по-английски, ~20 новостей в ленте, с полным текстом. В пару к The National из Абу-Даби. */
    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['gulfnews', 'Gulf News (ОАЭ)', 'https://gulfnews.com/feed', 'rss', 'en', true, false],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code = 'gulfnews'");
    }
}
