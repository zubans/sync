<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\TableDiff;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012150000 extends AbstractMigration
{
    /** Новые издания: код, название, ленты (через пробел), формат, показывать ли в виджете. */
    private const SOURCES = [
        // RSS BBC (feeds.bbci.co.uk) из нашей сети не открывается; новостная карта bbc.com — открывается.
        // В ней все языковые службы и разделы вперемешку — #/news/articles/ оставляет английские новости.
        ['bbc', 'BBC', 'https://www.bbc.com/sitemaps/https-sitemap-com-news-1.xml#/news/articles/', 'news_sitemap', true],
        // В карте сайта thetimes.com — и The Times, и The Sunday Times; RSS у издания нет.
        ['times', 'The Times', 'https://www.thetimes.com/sitemaps/news', 'news_sitemap', true],
        // Общей ленты у Газеты.Ru нет — разделы.
        ['gazeta', 'Газета.Ru', 'https://www.gazeta.ru/export/rss/politics.xml https://www.gazeta.ru/export/rss/business.xml https://www.gazeta.ru/export/rss/social.xml', 'rss', true],
        // Французские и украинские новости — только в сводку.
        ['lemonde', 'Le Monde', 'https://www.lemonde.fr/rss/une.xml', 'rss', false],
        ['pravda_ua', 'Українська правда', 'https://www.pravda.com.ua/rss/view_news/', 'rss', false],
    ];

    public function getDescription(): string
    {
        return 'Новости: издания «только для сводки»; BBC, The Times, Газета.Ru, Le Monde, «Українська правда»';
    }

    public function up(Schema $schema): void
    {
        foreach ($this->platform->getAlterTableSQL($this->diff()) as $sql) {
            $this->addSql($sql);
        }
        foreach (self::SOURCES as [$code, $title, $url, $format, $widget]) {
            $this->addSql(
                'INSERT INTO news_source (code, title, url, format, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?)',
                [$code, $title, $url, $format, true, $widget],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code IN ('bbc', 'times', 'gazeta', 'lemonde', 'pravda_ua')");
        $this->addSql('ALTER TABLE news_source DROP COLUMN show_in_widget');
    }

    /** Колонка через Schema API DBAL — SQL под текущую платформу, как в PortableSchema. */
    private function diff(): TableDiff
    {
        $manager = $this->connection->createSchemaManager();
        $table = $manager->introspectTableByUnquotedName('news_source');
        $changed = clone $table;
        $changed->addColumn('show_in_widget', Types::BOOLEAN, ['notnull' => true, 'default' => true]);

        return $manager->createComparator()->compareTables($table, $changed);
    }
}
