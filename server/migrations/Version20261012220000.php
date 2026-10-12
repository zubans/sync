<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Новости: только в сводку — «Аль-Баян» (ОАЭ)';
    }

    /**
     * «Аль-Баян» — газета Дубая, по-арабски. RSS нет — новостная карта сайта; сайт обрывает ответы
     * (карта приходит то целиком, то на ~16 КБ), поэтому карта разбирается в режиме восстановления.
     * Страницы статей обрываются до текста — полного текста не будет, в сводку идут заголовки.
     */
    public function up(Schema $schema): void
    {
        $this->addSql(
            'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ['albayan', 'Аль-Баян (ОАЭ)', 'https://www.albayan.ae/news-sitemap.xml', 'news_sitemap', 'ar', true, false],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code = 'albayan'");
    }
}
