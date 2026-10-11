<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261012120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Сводки новостей: издание «Сводка» и подробности сводок';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('news_digest', [
                self::id(),
                self::col('period', Types::STRING, 8),
                self::col('period_start', Types::DATETIME_IMMUTABLE),
                self::col('period_end', Types::DATETIME_IMMUTABLE),
                self::col('stories', Types::JSON),
                self::col('news_count', Types::INTEGER),
                self::col('model', Types::STRING, 100, nullable: true),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
                self::col('item_id', Types::INTEGER),
            ], [
                self::index('UNIQ_81BB63C6126F525E', ['item_id'], unique: true),
                self::index('idx_news_digest_period', ['period', 'period_end']),
            ], [
                self::fk('FK_81BB63C6126F525E', 'item_id', 'news_item', ReferentialAction::CASCADE),
            ]),
        );

        // Ленты у сводки нет — адрес пустой, её пишет app:news:digest.
        $this->addSql(
            'INSERT INTO news_source (code, title, url, format, enabled) VALUES (?, ?, ?, ?, ?)',
            ['digest', 'Сводка', '', 'digest', true],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BOOLEAN],
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM news_source WHERE code = 'digest'");
        $this->dropTables('news_digest');
    }
}
