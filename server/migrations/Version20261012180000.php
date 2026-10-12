<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012180000 extends AbstractMigration
{
    /** Новые издания: код, название, лента, язык (для автоперевода), показывать ли в виджете. */
    private const SOURCES = [
        ['aljazeera', 'Al Jazeera', 'https://www.aljazeera.com/xml/rss/all.xml', 'en', true],
        ['politico', 'Politico', 'https://rss.politico.com/politics-news.xml', 'en', true],
        // Японские, китайские и бразильские новости — только в сводку. Ленты NHK не обновляются
        // с августа 2026, Синьхуа и «Жэньминь жибао» — с 2022–2025, лента Global Times — подборка старых
        // статей: взяты «Асахи» (RSS 1.0, по-японски), China News Service (государственное агентство КНР,
        // по-китайски: общая лента и раздел «Китай») и G1 (Globo, по-португальски).
        ['asahi', 'Асахи', 'https://www.asahi.com/rss/asahi/newsheadlines.rdf', 'ja', false],
        ['chinanews', 'China News (中新网)', 'https://www.chinanews.com.cn/rss/scroll-news.xml https://www.chinanews.com.cn/rss/china.xml', 'zh', false],
        ['g1', 'G1 (Globo)', 'https://g1.globo.com/rss/g1/', 'pt', false],
    ];

    public function getDescription(): string
    {
        return 'Новости: Al Jazeera, Politico; только в сводку — «Асахи», China News, G1';
    }

    public function up(Schema $schema): void
    {
        foreach (self::SOURCES as [$code, $title, $url, $language, $widget]) {
            $this->addSql(
                'INSERT INTO news_source (code, title, url, format, language, enabled, show_in_widget) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$code, $title, $url, 'rss', $language, true, $widget],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code IN ('aljazeera', 'politico', 'asahi', 'chinanews', 'g1')");
    }
}
