<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261011120000 extends AbstractMigration
{
    use PortableSchema;

    /** Издания, которые раньше читал сам виджет планшета. */
    private const SOURCES = [
        ['rg', 'РГ', 'https://rg.ru/xml/index.xml', 'rss'],
        ['rbc', 'РБК', 'https://rssexport.rbc.ru/rbcnews/news/30/full.rss', 'rss'],
        ['cnn', 'CNN', 'https://lite.cnn.com/', 'cnn_lite'],
        ['nypost', 'NY Post', 'https://nypost.com/feed/', 'rss'],
        ['nyt', 'NYT', 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml', 'rss'],
    ];

    public function getDescription(): string
    {
        return 'Новости: издания и собранные новости';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('news_source', [
                self::id(),
                self::col('code', Types::STRING, 32),
                self::col('title', Types::STRING, 100),
                self::col('url', Types::STRING, 500),
                self::col('format', Types::STRING, 20),
                self::col('enabled', Types::BOOLEAN),
                self::col('last_attempt_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('last_success_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('last_error', Types::TEXT, nullable: true),
                self::col('etag', Types::STRING, 255, nullable: true),
                self::col('last_modified', Types::STRING, 100, nullable: true),
            ], [
                self::index('uniq_news_source_code', ['code'], unique: true),
            ]),
            self::table('news_item', [
                self::id(),
                self::col('external_key', Types::STRING, 40),
                self::col('link', Types::STRING, 1000),
                self::col('title', Types::STRING, 1000),
                self::col('summary', Types::TEXT, nullable: true),
                self::col('content', Types::TEXT, nullable: true),
                self::col('published_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('first_seen_at', Types::DATETIME_IMMUTABLE),
                self::col('sort_at', Types::DATETIME_IMMUTABLE),
                self::col('updated_at', Types::DATETIME_IMMUTABLE),
                self::col('source_id', Types::INTEGER),
            ], [
                self::index('uniq_news_item_key', ['source_id', 'external_key'], unique: true),
                self::index('idx_news_item_sort', ['sort_at']),
                self::index('idx_news_item_source_sort', ['source_id', 'sort_at']),
                self::index('IDX_CAC6D395953C1C61', ['source_id']),
            ], [
                self::fk('FK_CAC6D395953C1C61', 'source_id', 'news_source', ReferentialAction::CASCADE),
            ]),
        );

        foreach (self::SOURCES as [$code, $title, $url, $format]) {
            $this->addSql(
                'INSERT INTO news_source (code, title, url, format, enabled) VALUES (?, ?, ?, ?, ?)',
                [$code, $title, $url, $format, true],
                [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('news_item', 'news_source');
    }
}
