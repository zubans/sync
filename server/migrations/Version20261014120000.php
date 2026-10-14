<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Migration\PortableSchema;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint\ReferentialAction;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Doctrine\Migrations\AbstractMigration;

final class Version20261014120000 extends AbstractMigration
{
    use PortableSchema;

    public function getDescription(): string
    {
        return 'Мосбиржа: инструменты виджета, покупки и анализ инструментов';
    }

    public function up(Schema $schema): void
    {
        $this->createTables(
            self::table('market_instrument', [
                self::id(),
                self::col('user_id', Types::INTEGER),
                self::col('secid', Types::STRING, 36),
                self::col('engine', Types::STRING, 20),
                self::col('market', Types::STRING, 20),
                self::col('board', Types::STRING, 20),
                self::col('name', Types::STRING, 100),
                self::col('position', Types::INTEGER),
            ], [
                self::index('uniq_market_instrument_user_secid', ['user_id', 'secid'], unique: true),
                self::index('IDX_52413DC1A76ED395', ['user_id']),
            ], [
                self::fk('FK_52413DC1A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('market_lot', [
                self::id(),
                self::col('user_id', Types::INTEGER),
                self::col('secid', Types::STRING, 36),
                self::col('bought_on', Types::DATE_IMMUTABLE),
                self::col('quantity', Types::FLOAT),
                self::col('price', Types::FLOAT),
                self::col('amount', Types::FLOAT),
                self::col('created_at', Types::DATETIME_IMMUTABLE),
            ], [
                self::index('idx_market_lot_user_secid', ['user_id', 'secid']),
                self::index('IDX_D88ACBB8A76ED395', ['user_id']),
            ], [
                self::fk('FK_D88ACBB8A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
            self::table('market_analysis', [
                self::id(),
                self::col('user_id', Types::INTEGER),
                self::col('secid', Types::STRING, 36),
                self::col('requested_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('analyzed_at', Types::DATETIME_IMMUTABLE, nullable: true),
                self::col('recommendation', Types::STRING, 10, nullable: true),
                self::col('outlook', Types::STRING, 10, nullable: true),
                self::col('confidence', Types::INTEGER, nullable: true),
                self::col('urgency', Types::STRING, 10, nullable: true),
                self::col('horizon', Types::STRING, 100, nullable: true),
                self::col('summary', Types::TEXT, nullable: true),
                self::col('news', Types::TEXT, nullable: true),
                self::col('technical', Types::TEXT, nullable: true),
                self::col('position', Types::TEXT, nullable: true),
                self::col('risks', Types::JSON),
                self::col('triggers', Types::JSON),
                self::col('price', Types::FLOAT, nullable: true),
                Column::editor()->setUnquotedName('news_count')->setTypeName(Types::INTEGER)->setNotNull(true)->setDefaultValue(0)->create(),
                self::col('model', Types::STRING, 100, nullable: true),
                self::col('error', Types::TEXT, nullable: true),
            ], [
                self::index('uniq_market_analysis_user_secid', ['user_id', 'secid'], unique: true),
                self::index('IDX_ED82D2F8A76ED395', ['user_id']),
            ], [
                self::fk('FK_ED82D2F8A76ED395', 'user_id', 'app_user', ReferentialAction::CASCADE),
            ]),
        );
    }

    public function down(Schema $schema): void
    {
        $this->dropTables('market_analysis', 'market_lot', 'market_instrument');
    }
}
