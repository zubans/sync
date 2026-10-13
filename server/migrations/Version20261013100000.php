<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261013100000 extends AbstractMigration
{
    /**
     * Хабр — статьи и новости (две ленты), полный текст скачивается со страниц. Медуза — полный текст
     * приходит в самой ленте. Оба — вкладками в виджете.
     */
    private const SOURCES = [
        ['habr', 'Хабр', 'https://habr.com/ru/rss/articles/?fl=ru https://habr.com/ru/rss/news/?fl=ru'],
        ['meduza', 'Медуза', 'https://meduza.io/rss/all'],
    ];

    public function getDescription(): string
    {
        return 'Новости: Хабр и Медуза';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SOURCES as [$code, $title, $url]) {
            $this->addSql(
                'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $title, $url, 'rss', 'ru', true, true],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code IN ('habr', 'meduza')");
    }
}
